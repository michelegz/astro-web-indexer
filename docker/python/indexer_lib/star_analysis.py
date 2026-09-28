"""
Star and frame quality metrics using `sep` (Source Extraction and Photometry).

`sep` exposes the SExtractor (Bertin & Arnouts 1996) algorithms as a C library
with Python bindings (LGPLv3+, used here as a pip dependency - no code copied).

Method notes:
- Background/noise: sep.Background. Detection: sep.extract at 5 sigma with
  minarea 9 (bright, reliable stars only — faint objects add noise, not signal,
  to a mean HFR), capped to the brightest MAX_STARS by isophotal flux.
  No downscaling (fields can be undersampled — shrinking would erase real
  stars); hot pixels are cut by tiny-area AND extreme-peak signature.
- HFR: sep.flux_radius(frac=0.5), i.e. the radius enclosing half the AUTO flux.
- FWHM: approximated as 2xHFR (exact for Gaussian PSFs). Validated against
  N.I.N.A. logs; see tmp/star-metrics-plan.md for the fallback (radial profile
  following NINA's Star.CalculateFwhm) if the deviation proves significant.
- Eccentricity: sqrt(1-(b/a)^2) from the sep ellipse parameters.
- Star selection mirrors N.I.N.A.'s cuts: reject saturated/truncated/incomplete
  objects (SExtractor flag bits), eccentricity > 0.8, and radius outliers
  beyond +/-1.5 sigma (NINA's IdentifyStars filter).
- snr_weight: stacked SNR of the measured stars, i.e. sum(flux) /
  sqrt(sum(fluxerr^2)) with fluxerr from the background-noise map. Flux-weighted
  sums are junk-immune (faint junk contributes negligibly), unlike a median over
  a junk-dominated catalog. Relative measure for ranking frames; fluxerr ignores
  the Poisson term (no gain used), so not an absolute SNR.
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

THRESH_SIGMA = 5.0
MIN_AREA = 9
MAX_ECCENTRICITY = 0.8
OUTLIER_SIGMA = 1.5
MIN_DIMENSION = 32
# Only the brightest stars are measured: plenty for a robust mean HFR, and it
# keeps deblending/photometry fast on dense fields (tens of thousands of
# detections would otherwise take minutes per frame). NINA does the same for
# autofocus (NumberOfAFStars: brightest stars only).
MAX_STARS = 1500
# Hot-pixel signature: tiny area AND extreme peak (real stars never combine
# both — faint small stars have modest peaks). Full-resolution units.
# Note: npix is measured on convolved data, so a 3x3 hot cluster spans ~25px;
# the threshold accounts for that spread.
HOT_NPIX = 32
HOT_PEAK_SIGMA = 30.0

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
        dict with hfr_avg, fwhm_avg, hfr_sd (pixels), ecc_avg, snr_weight,
        psf_signal (None values when not measurable) plus star_count = stars
        passing the quality cuts (before the top-N measurement cap),
        or None if sep is unavailable or the input is unusable.
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

        # NOTE: no downscaling (unlike NINA's maxWidth resize): our fields can be
        # undersampled (HFR < 1px) and shrinking would erase real stars before
        # hot pixels. Speed comes from the high threshold + top-N cap instead.
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
        # Hot pixels: tiny area AND extreme peak (faint small stars never combine
        # both). No downscaling: fields can be undersampled (HFR < 1px) and
        # shrinking would erase real stars before hot pixels.
        hot = (np.asarray(objs['npix']) <= HOT_NPIX) & (np.asarray(objs['peak'], dtype=np.float64) > HOT_PEAK_SIGMA * noise)
        pre &= ~hot
        if not np.any(pre):
            return dict(empty)
        # Stars passing the quality cuts (this is the reported star_count).
        # The top-N cap below only limits how many are measured for the means.
        n_detected = int(np.count_nonzero(pre))
        result = dict(empty)
        result['star_count'] = n_detected
        # Keep only the brightest MAX_STARS by isophotal flux: enough for a
        # robust mean, and deblending/photometry stay fast on dense fields.
        idx = np.flatnonzero(pre)
        isoflux = np.asarray(objs['flux'], dtype=np.float64)[idx]
        isoflux[~np.isfinite(isoflux)] = -np.inf
        if idx.size > MAX_STARS:
            part = np.argpartition(isoflux, -MAX_STARS)[-MAX_STARS:]
            idx = idx[part[np.argsort(isoflux[part])[::-1]]]
        x, y, a, b, theta, ecc = x[idx], y[idx], a[idx], b[idx], theta[idx], ecc[idx]

        # AUTO flux via Kron radius, then half-flux radius (HFR).
        # Each stage gates on finite/positive values before the next sep call.
        kronrad, _ = sep.kron_radius(sub, x, y, a, b, theta, 6.0)
        kronrad = np.asarray(kronrad, dtype=np.float64)
        m = np.isfinite(kronrad) & (kronrad > 0)
        if not np.any(m):
            return result
        x, y, a, b, theta, ecc = x[m], y[m], a[m], b[m], theta[m], ecc[m]
        kronrad = np.maximum(kronrad[m], 1e-3)
        flux, fluxerr, _ = sep.sum_ellipse(
            sub, x, y, a, b, theta, 2.5 * kronrad, err=noise, subpix=1)
        flux = np.asarray(flux, dtype=np.float64)
        fluxerr = np.asarray(fluxerr, dtype=np.float64)
        m = np.isfinite(flux) & (flux > 0) & np.isfinite(fluxerr) & (fluxerr > 0)
        if not np.any(m):
            return result
        x, y, a, flux, fluxerr, ecc = x[m], y[m], a[m], flux[m], fluxerr[m], ecc[m]
        hfr, _ = sep.flux_radius(sub, x, y, 6.0 * a, 0.5, normflux=flux, subpix=5)
        hfr = np.asarray(hfr, dtype=np.float64)
        m = np.isfinite(hfr) & (hfr > 0)
        if not np.any(m):
            return result
        hfr, ecc, flux, fluxerr = hfr[m], ecc[m], flux[m], fluxerr[m]
        logger.debug(f"sep: {len(objs)} detections, {n_detected} quality, {hfr.size} measured")

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
                    fluxerr = fluxerr[keep]

        n = int(hfr.size)
        hfr_avg = float(np.mean(hfr))
        result.update({
            'hfr_avg': hfr_avg,
            'fwhm_avg': 2.0 * hfr_avg,  # exact for Gaussian PSFs; see module docstring
            'hfr_sd': float(np.std(hfr)) if n > 1 else 0.0,
            'ecc_avg': float(np.mean(ecc)),
        })

        # Frame-level quality estimators (same catalog, ~zero extra cost).
        total_flux = float(np.sum(flux))
        total_variance = float(np.sum(fluxerr ** 2))
        if total_variance > 0:
            result['snr_weight'] = float(total_flux / np.sqrt(total_variance))
        if total_flux > 0:
            mean_flux = total_flux / n
            result['psf_signal'] = (
                (5.326e-6 * total_flux * mean_flux) / (9.0e6 * noise * n)
            )
        logger.debug("sep star analysis done: %d measured of %d quality stars in %.2fs total",
                     n, n_detected, time.perf_counter() - t_start)
        return result
    except Exception as e:
        logger.warning(f"Star metrics computation failed: {e}")
        return None
