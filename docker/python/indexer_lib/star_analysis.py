"""
Star and frame quality metrics using `sep` (Source Extraction and Photometry).

`sep` exposes the SExtractor (Bertin & Arnouts 1996) algorithms as a C library
with Python bindings (LGPLv3+, used here as a pip dependency - no code copied).

Method notes:
- Background/noise: sep.Background. Detection: sep.extract with SExtractor
  defaults (thresh 1.5 sigma, minarea 5, deblending on).
- HFR: sep.flux_radius(frac=0.5), i.e. the radius enclosing half the AUTO flux.
- FWHM: approximated as 2xHFR (exact for Gaussian PSFs). Validated against
  N.I.N.A. logs; see tmp/star-metrics-plan.md for the fallback (radial profile
  following NINA's Star.CalculateFwhm) if the deviation proves significant.
- Eccentricity: sqrt(1-(b/a)^2) from the sep ellipse parameters.
- Star selection mirrors N.I.N.A.'s cuts: reject saturated/truncated/incomplete
  objects (SExtractor flag bits), eccentricity > 0.8, and radius outliers
  beyond +/-1.5 sigma (NINA's IdentifyStars filter).
- snr_weight: SubframeSelector-style classic estimator, MAD^2 / noise^2
  (relative measure, meaningful when comparing frames of the same
  target/filter/background).
- psf_signal: PixInsight PCL PSFSignalEstimator formula (published math, not
  code): (5.326e-6 * totalFlux * totalMeanFlux) / (9.0e+6 * sigmaN * MStar),
  with sep star fluxes and sigmaN = background global RMS. Values are not
  numerically identical to PixInsight (different detection/photometry) but
  share meaning and scale: suitable for ranking/weighting frames.

All failures return None (never block indexing); the caller stores NULLs.
"""

import logging
import time

import numpy as np

try:
    import sep
    _SEP_AVAILABLE = True
except ImportError:  # pragma: no cover - reindex must survive a missing dep
    sep = None
    _SEP_AVAILABLE = False

logger = logging.getLogger('reindex.star_analysis')
_sep_warning_logged = False

THRESH_SIGMA = 1.5
MIN_AREA = 5
MAX_ECCENTRICITY = 0.8
OUTLIER_SIGMA = 1.5
MIN_DIMENSION = 32

# SExtractor extraction flag bits for objects to reject:
# 4 = saturated, 8 = truncated at image boundary,
# 16 = aperture incomplete, 32 = isophotal incomplete.
_BAD_FLAG_BITS = 4 | 8 | 16 | 32


def _extract_luminance(data):
    """Return a 2D luminance array from mono or color data, or None."""
    arr = np.squeeze(data)
    if arr.ndim == 2:
        return arr
    if arr.ndim == 3:
        if arr.shape[0] in (3, 4):  # (C, H, W), e.g. FITS RGB cube
            return arr[1] if arr.shape[0] >= 3 else arr[0]
        if arr.shape[-1] in (3, 4):  # (H, W, C)
            return arr[..., 1]  # green channel approximates luminance
    return None


def analyze_frame(data):
    """Compute star/quality metrics for one frame.

    Args:
        data: numpy array with pixel data (any shape/dtype/byte order).

    Returns:
        dict with hfr_avg, fwhm_avg, hfr_sd (pixels), ecc_avg, star_count,
        snr_weight, psf_signal (None values when not measurable), or None if
        sep is unavailable or the input is unusable.
    """
    empty = {
        'hfr_avg': None, 'fwhm_avg': None, 'hfr_sd': None, 'ecc_avg': None,
        'star_count': 0, 'snr_weight': None, 'psf_signal': None,
    }
    if not _SEP_AVAILABLE:
        global _sep_warning_logged
        if not _sep_warning_logged:
            logger.warning("sep is not installed, skipping star metrics")
            _sep_warning_logged = True
        return None
    t_start = time.perf_counter()
    try:
        lum = _extract_luminance(data)
        if lum is None or lum.shape[0] < MIN_DIMENSION or lum.shape[1] < MIN_DIMENSION:
            return None
        # float32 + native byte order + C-contiguous, as required by sep
        # (astropy hands out big-endian arrays; astype() normalizes that).
        img = np.ascontiguousarray(np.nan_to_num(lum).astype(np.float32))

        bkg = sep.Background(img)
        noise = float(bkg.globalrms)
        if not np.isfinite(noise) or noise <= 0:
            return None
        sub = img - bkg
        t_bg = time.perf_counter()

        objs = sep.extract(sub, THRESH_SIGMA, err=noise, minarea=MIN_AREA)
        t_extract = time.perf_counter()
        logger.debug("sep background+extract: %d detections (bg %.2fs, extract %.2fs)",
                     len(objs), t_bg - t_start, t_extract - t_bg)
        if len(objs) == 0:
            return dict(empty)

        x = np.asarray(objs['x'])
        y = np.asarray(objs['y'])
        a = objs['a'].astype(np.float64)
        b = objs['b'].astype(np.float64)
        theta = np.asarray(objs['theta'], dtype=np.float64)

        # Pre-filter on extract-only fields, so degenerate objects never reach
        # the photometry calls below (a single NaN there would abort the whole
        # frame with "invalid aperture parameters").
        pre = (objs['flag'] & _BAD_FLAG_BITS) == 0
        pre &= np.isfinite(a) & np.isfinite(b) & np.isfinite(theta)
        pre &= (a > 0) & (b >= 0)
        ecc = np.sqrt(np.clip(1.0 - (b / np.where(a > 0, a, 1.0)) ** 2, 0.0, 1.0))
        pre &= np.isfinite(ecc) & (ecc <= MAX_ECCENTRICITY)
        if not np.any(pre):
            return dict(empty)
        x, y, a, b, theta, ecc = x[pre], y[pre], a[pre], b[pre], theta[pre], ecc[pre]

        # AUTO flux via Kron radius, then half-flux radius (HFR).
        # Each stage gates on finite/positive values before the next sep call.
        kronrad, _ = sep.kron_radius(sub, x, y, a, b, theta, 6.0)
        kronrad = np.asarray(kronrad, dtype=np.float64)
        m = np.isfinite(kronrad) & (kronrad > 0)
        if not np.any(m):
            return dict(empty)
        x, y, a, b, theta, ecc = x[m], y[m], a[m], b[m], theta[m], ecc[m]
        kronrad = np.maximum(kronrad[m], 1e-3)
        flux, _, _ = sep.sum_ellipse(
            sub, x, y, a, b, theta, 2.5 * kronrad, err=noise, subpix=1)
        flux = np.asarray(flux, dtype=np.float64)
        m = np.isfinite(flux) & (flux > 0)
        if not np.any(m):
            return dict(empty)
        x, y, a, flux, ecc = x[m], y[m], a[m], flux[m], ecc[m]
        hfr, _ = sep.flux_radius(sub, x, y, 6.0 * a, 0.5, normflux=flux, subpix=5)
        hfr = np.asarray(hfr, dtype=np.float64)
        m = np.isfinite(hfr) & (hfr > 0)
        if not np.any(m):
            return dict(empty)
        hfr, ecc, flux = hfr[m], ecc[m], flux[m]
        logger.debug(f"sep: {len(objs)} detections, {hfr.size} accepted stars")

        # NINA-style outlier rejection on radii (+/-1.5 sigma).
        if hfr.size >= 3:
            std = float(np.std(hfr))
            if std > 0:
                mean = float(np.mean(hfr))
                keep = np.abs(hfr - mean) <= OUTLIER_SIGMA * std
                if np.any(keep):
                    hfr = hfr[keep]
                    ecc = ecc[keep]
                    flux = flux[keep]

        n = int(hfr.size)
        hfr_avg = float(np.mean(hfr))
        result = dict(empty)
        result.update({
            'hfr_avg': hfr_avg,
            'fwhm_avg': 2.0 * hfr_avg,  # exact for Gaussian PSFs; see module docstring
            'hfr_sd': float(np.std(hfr)) if n > 1 else 0.0,
            'ecc_avg': float(np.mean(ecc)),
            'star_count': n,
        })

        # Frame-level quality estimators (same catalog, ~zero extra cost).
        med = float(np.median(sub))
        mad = float(np.median(np.abs(sub - med)))
        if mad > 0:
            result['snr_weight'] = (mad / noise) ** 2
        total_flux = float(np.sum(flux))
        if total_flux > 0:
            mean_flux = total_flux / n
            result['psf_signal'] = (
                (5.326e-6 * total_flux * mean_flux) / (9.0e6 * noise * n)
            )
        logger.debug("sep star analysis done: %d accepted stars in %.2fs total",
                     n, time.perf_counter() - t_start)
        return result
    except Exception as e:
        logger.warning(f"Star metrics computation failed: {e}")
        return None
