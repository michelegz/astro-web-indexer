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
        dict with hfr_avg, fwhm_avg, ecc_avg, star_count, snr_weight,
        psf_signal (None values when not measurable), or None if sep is
        unavailable or the input is unusable.
    """
    empty = {
        'hfr_avg': None, 'fwhm_avg': None, 'ecc_avg': None,
        'star_count': 0, 'snr_weight': None, 'psf_signal': None,
    }
    if not _SEP_AVAILABLE:
        global _sep_warning_logged
        if not _sep_warning_logged:
            logger.warning("sep is not installed, skipping star metrics")
            _sep_warning_logged = True
        return None
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

        objs = sep.extract(sub, THRESH_SIGMA, err=noise, minarea=MIN_AREA)
        if len(objs) == 0:
            return dict(empty)

        x = objs['x']
        y = objs['y']
        a = objs['a'].astype(np.float64)
        b = objs['b'].astype(np.float64)

        ok = (objs['flag'] & _BAD_FLAG_BITS) == 0
        ok &= np.isfinite(a) & np.isfinite(b) & (a > 0) & (b >= 0)
        ecc = np.sqrt(np.clip(1.0 - (b / np.where(a > 0, a, 1.0)) ** 2, 0.0, 1.0))
        ok &= np.isfinite(ecc) & (ecc <= MAX_ECCENTRICITY)
        if not np.any(ok):
            return dict(empty)

        # AUTO flux via Kron radius, then half-flux radius (HFR).
        kronrad, _ = sep.kron_radius(sub, x, y, objs['a'], objs['b'], objs['theta'], 6.0)
        kronrad = np.maximum(kronrad, 1e-3)
        flux, _, _ = sep.sum_ellipse(
            sub, x, y, objs['a'], objs['b'], objs['theta'],
            2.5 * kronrad, err=noise, subpix=1)
        ok &= np.isfinite(flux) & (flux > 0)
        hfr, _ = sep.flux_radius(
            sub, x, y, 6.0 * objs['a'], 0.5, normflux=np.maximum(flux, 1e-9),
            subpix=5)
        hfr = np.asarray(hfr, dtype=np.float64)
        ok &= np.isfinite(hfr) & (hfr > 0)

        hfr = hfr[ok]
        ecc = ecc[ok]
        flux = flux[ok].astype(np.float64)
        if hfr.size == 0:
            return dict(empty)

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
        return result
    except Exception as e:
        logger.warning(f"Star metrics computation failed: {e}")
        return None
