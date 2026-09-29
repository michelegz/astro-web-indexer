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
- FWHM: radial-profile half-maximum (NINA Star.CalculateFwhm method, numpy
  reimplementation — no NINA code): azimuthal mean profile on small cutouts,
  linear interpolation of the half-maximum radius, FWHM = 2x that. Median over
  the brightest stars; falls back to 2xHFR (exact for Gaussians) when too few
  stars are measurable. Relative measure for ranking/trends, not absolute.
- Luminance: mono/Bayer-RAW 2D used as-is; RGB cubes use the best channel by
  signal score (G on broadband, R on Ha/dual-band).
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
# both at these levels — faint small stars have modest peaks, and even bright
# undersampled stars rarely exceed ~80 sigma on a single pixel without
# saturating, which is already caught by the SExtractor saturated flag).
# Full-resolution units.
# Note: npix is measured on convolved data, so a 3x3 hot cluster spans ~25px;
# HOT_NPIX=32 keeps margin above that spread (20 would let them through).
# True hot clusters typically peak at hundreds of sigma, so 80 sigma keeps
# margin against bright real stars while still catching them.
HOT_NPIX = 32
HOT_PEAK_SIGMA = 80.0

# SExtractor extraction flag bits for objects to reject:
# 4 = saturated, 8 = truncated at image boundary,
# 16 = aperture incomplete, 32 = isophotal incomplete.
_BAD_FLAG_BITS = 4 | 8 | 16 | 32

# FWHM radial-profile sampling (NINA Star.CalculateFwhm method, numpy-only).
# Only the brightest subset is measured: enough for a robust median, and the
# per-star cutout cost stays in the milliseconds (vs seconds for sep.extract).
MAX_FWHM_STARS = 200
MIN_FWHM_STARS = 20


def _channel_score(ch):
    """Signal score for one color channel: (p99 - median) / MAD.

    Picks the channel carrying the most stellar signal. G wins on broadband
    RGB (as before), R wins on Ha/dual-band narrowband where G is ~noise.
    MAD-based denominator stays robust to stars/nebulosity.
    """
    try:
        flat = np.asarray(ch).ravel()
        if flat.dtype.kind == 'f':
            flat = flat[np.isfinite(flat)]
        if flat.size == 0:
            return -np.inf
        # Subsample huge channels for speed (percentile on 1M px is plenty).
        if flat.size > 1_000_000:
            flat = flat[:: flat.size // 1_000_000]
        med = float(np.median(flat))
        mad = float(np.median(np.abs(flat.astype(np.float64) - med)))
        if not np.isfinite(mad) or mad <= 0:
            return -np.inf
        p99 = float(np.percentile(flat, 99))
        score = (p99 - med) / mad
        return score if np.isfinite(score) else -np.inf
    except Exception:
        return -np.inf


def _extract_luminance(data):
    """Return a 2D luminance array from mono or color data, or None.

    Mono / OSC RAW with Bayer mosaic (2D): used as-is.
    RGB cube (3D): best-channel by signal score — G on broadband (as before),
    R on Ha/dual-band narrowband, G/B on OIII. Averaging would dilute
    narrowband 3x; per-pixel max would amplify noise.
    """
    arr = np.squeeze(data)
    if arr.ndim == 2:
        return arr
    if arr.ndim == 3:
        if arr.shape[0] in (3, 4):  # (C, H, W), e.g. FITS RGB cube
            nch = min(3, int(arr.shape[0]))  # ignore alpha if RGBA
            scores = [_channel_score(arr[i]) for i in range(nch)]
            best = int(np.argmax(scores))
            logger.debug("luminance best-channel: %d/%d scores=%s",
                         best, nch, [f"{s:.2f}" for s in scores])
            return arr[best]
        if arr.shape[-1] in (3, 4):  # (H, W, C)
            nch = min(3, int(arr.shape[-1]))
            scores = [_channel_score(arr[..., i]) for i in range(nch)]
            best = int(np.argmax(scores))
            logger.debug("luminance best-channel: %d/%d scores=%s",
                         best, nch, [f"{s:.2f}" for s in scores])
            return arr[..., best]  # green channel approximates luminance
    return None


def _fwhm_from_profile(sub, x, y, hfr_est):
    """Half-maximum FWHM from azimuthal mean profile (NINA method, numpy-only).

    For one star: cutout of radius R=4*HFR+4 around the integer centroid,
    local background from the cutout border, azimuthal mean in 0.5px bins,
    linear interpolation of the radius where the profile drops to half the
    background-subtracted peak. Returns FWHM = 2*r_half, or NaN if unusable
    (edge, non-monotonic core, saturated plateau).
    Pure numpy; ~microseconds per star. NOT a Moffat fit (that would need a
    per-star non-linear solver, 10-100x heavier, Siril-style — deliberately
    avoided here).
    """
    try:
        h = float(hfr_est)
        if not np.isfinite(h) or h <= 0:
            return np.nan
        r_box = int(min(25, max(8, round(4.0 * h + 4.0))))
        xi, yi = int(round(float(x))), int(round(float(y)))
        h_img, w_img = sub.shape
        if xi - r_box < 0 or yi - r_box < 0 or xi + r_box >= w_img or yi + r_box >= h_img:
            return np.nan
        cut = np.asarray(sub[yi - r_box: yi + r_box + 1, xi - r_box: xi + r_box + 1],
                         dtype=np.float64)
        if cut.shape != (2 * r_box + 1, 2 * r_box + 1):
            return np.nan
        # Local background: median of the outer 1px border (robust to neighbors
        # unless the field is extremely crowded, where it degrades gracefully).
        border = np.concatenate([cut[0, :], cut[-1, :], cut[1:-1, 0], cut[1:-1, -1]])
        bg = float(np.median(border))
        if not np.isfinite(bg):
            return np.nan
        core = cut[r_box - 1: r_box + 2, r_box - 1: r_box + 2]
        peak = float(np.max(core)) - bg
        if not np.isfinite(peak) or peak <= 0:
            return np.nan
        half = peak / 2.0
        yy, xx = np.mgrid[-r_box: r_box + 1, -r_box: r_box + 1]
        rr = np.sqrt(xx.astype(np.float64) ** 2 + yy.astype(np.float64) ** 2)
        vals = (cut - bg).ravel()
        rad = rr.ravel()
        nbins = int(r_box * 2)  # 0.5px bins up to r_box
        edges = np.arange(nbins + 1, dtype=np.float64) * 0.5
        idx = np.digitize(rad, edges) - 1
        prof = np.full(nbins, np.nan)
        for b in range(nbins):
            m = idx == b
            if np.any(m):
                prof[b] = float(np.mean(vals[m]))
        # NOTE: 0.5px bins leave every other bin empty on the pixel grid
        # (no pixel has e.g. 0.5<=r<1.0), so only finite bins take part.
        finite = [(edges[b] + 0.25, prof[b]) for b in range(nbins)
                  if np.isfinite(prof[b])]
        if len(finite) < 3 or not finite[0][1] > half:
            return np.nan
        # Walk outwards from the core: first segment crossing half-max.
        for (r0, v0), (r1, v1) in zip(finite, finite[1:]):
            if v0 >= half > v1:
                if v0 == v1 or r1 <= r0:
                    return np.nan
                frac = (v0 - half) / (v0 - v1)
                r_half = r0 + frac * (r1 - r0)
                if 0.5 <= r_half <= r_box:
                    return 2.0 * r_half
                return np.nan
        return np.nan
    except Exception:
        return np.nan


def _median_fwhm(sub, xs, ys, hfrs, fluxes):
    """Median FWHM over the brightest subset; NaN if too few measurable."""
    try:
        n = len(hfrs)
        if n == 0:
            return np.nan
        order = np.argsort(np.asarray(fluxes, dtype=np.float64))[::-1]
        take = order[: min(MAX_FWHM_STARS, n)]
        out = []
        for i in take:
            v = _fwhm_from_profile(sub, xs[i], ys[i], hfrs[i])
            if np.isfinite(v) and 0.5 < v < 60.0:
                out.append(v)
        if len(out) >= min(MIN_FWHM_STARS, n):
            return float(np.median(out))
        return np.nan
    except Exception:
        return np.nan


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
        n_hot = int(np.count_nonzero(hot))
        if n_hot:
            logger.debug("sep hot-pixel cut rejected %d detections (npix<=%d and peak>%.0fσ)",
                         n_hot, HOT_NPIX, HOT_PEAK_SIGMA)
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
        x, y, hfr, ecc, flux, fluxerr = x[m], y[m], hfr[m], ecc[m], flux[m], fluxerr[m]
        logger.debug(f"sep: {len(objs)} detections, {n_detected} quality, {hfr.size} measured")

        # NINA-style outlier rejection on radii (+/-1.5 sigma).
        if hfr.size >= 3:
            std = float(np.std(hfr))
            if std > 0:
                mean = float(np.mean(hfr))
                keep = np.abs(hfr - mean) <= OUTLIER_SIGMA * std
                if np.any(keep):
                    x = x[keep]
                    y = y[keep]
                    hfr = hfr[keep]
                    ecc = ecc[keep]
                    flux = flux[keep]
                    fluxerr = fluxerr[keep]

        n = int(hfr.size)
        hfr_avg = float(np.mean(hfr))
        fwhm_med = _median_fwhm(sub, x, y, hfr, flux)
        if np.isfinite(fwhm_med):
            fwhm_avg = float(fwhm_med)
        else:  # too few measurable profiles (sparse/tiny frame): exact for Gaussians
            fwhm_avg = 2.0 * hfr_avg
            logger.debug("fwhm fallback to 2xHFR (profiles unmeasurable)")
        result.update({
            'hfr_avg': hfr_avg,
            'fwhm_avg': fwhm_avg,
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
