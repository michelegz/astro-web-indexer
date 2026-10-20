"""Frame extraction: the I/O and the pure computation, split apart.

The indexer runs in two modes (INDEXER_PHASE_MODE):

* ``single``: one worker opens the file once and produces every column.
* ``two``: the same worker without the star metrics (the fast pass, which
  makes the file show up in the frontend), then a second worker that opens
  the file again for the metrics alone (the slow pass).

Both modes call the functions below, so a change to the extraction is made
once and the two modes cannot drift apart.

The functions that take a FrameCtx are pure: they read the header and the
pixel array the context already holds, and never touch the disk.
"""

import logging
from datetime import timezone

import numpy as np
from astropy.io import fits
from astropy.time import Time
from xisf import XISF

from indexer_lib.ephemeris import get_moon_ephemeris
from indexer_lib.file_utils import (get_header_value, get_xisf_header_value,
                                    pick_image_hdu, pick_image_hdu_header)
from indexer_lib.image_processing import (frame_statistics, make_crop_preview,
                                          make_thumbnail)
from indexer_lib.projects import normalize_imgtype
from indexer_lib.star_analysis import analyze_frame

logger = logging.getLogger('reindex.frame_extract')


class FrameCtx:
    """One open frame: its header, its pixel data and its geometry."""

    __slots__ = ('header', 'get_value', 'data', 'width', 'height', 'channels')

    def __init__(self, header, get_value, data):
        self.header = header
        self.get_value = get_value
        self.data = data
        self.width, self.height, self.channels = frame_geometry(data)


def frame_geometry(data):
    """(width, height, channels) of a pixel array.

    The derivation the indexer has always used: the first axis is dropped
    when it holds 3 or 4 channels (a colour cube), and the channels are
    counted on the squeezed array. Kept as it was, so a frame measured by
    the fast pass and a frame measured by the slow pass agree.
    """
    if data is None:
        return None, None, None
    sq = np.squeeze(data)
    thumb_data = sq
    if thumb_data.ndim > 2 and thumb_data.shape[0] in (3, 4):
        thumb_data = thumb_data[0]
    width = height = None
    if thumb_data.ndim >= 2:
        height, width = thumb_data.shape[:2]
    channels = None
    if sq.ndim == 2:
        channels = 1
    elif sq.ndim == 3:
        if sq.shape[0] in (3, 4):
            channels = int(sq.shape[0])
        elif sq.shape[-1] in (3, 4):
            channels = int(sq.shape[-1])
    return width, height, channels


def open_frame(full_path, with_data=True):
    """Open a FITS/XISF file and return a FrameCtx.

    with_data=False never touches the pixel data: the header alone is enough
    for the schema upgrade pass, and reading the pixels there used to cost a
    whole frame (decompressed on a .fz) for nothing.
    """
    file_lower = full_path.lower()
    if file_lower.endswith(('.fits', '.fit', '.fz')):
        with fits.open(full_path, ignore_missing_end=True) as hdul:
            if with_data:
                header, data = pick_image_hdu(hdul)
            else:
                header, data = pick_image_hdu_header(hdul)
            return FrameCtx(header, get_header_value, _squeeze(data))
    if file_lower.endswith('.xisf'):
        xisf_file = XISF(full_path)
        images_meta = xisf_file.get_images_metadata()
        if not images_meta:
            raise ValueError('No image metadata in XISF')
        header = images_meta[0].get('FITSKeywords', {})
        data = xisf_file.read_image(0) if with_data else None
        return FrameCtx(header, get_xisf_header_value, _squeeze(data))
    raise ValueError(f'Unsupported file type: {full_path}')


def _squeeze(data):
    """The squeezed pixel array, or None."""
    return np.squeeze(data) if data is not None else None


def frame_imgtype(ctx):
    """The canonical IMAGETYPE of the frame (LIGHT, DARK, FLAT, BIAS, ...)."""
    return normalize_imgtype(ctx.get_value(ctx.header, 'IMAGETYP', 'UNKNOWN', str))


def frame_resolution(ctx, rel_path=None):
    """The arcsec/pixel of the frame, from the header and the geometry.

    None when the frame does not say (no pixel size, no focal length, or a
    degenerate one). The effective pixel size scales with the binning.
    """
    get_value = ctx.get_value
    xpixsz = get_value(ctx.header, 'XPIXSZ', None, float)
    focallen = get_value(ctx.header, 'FOCALLEN', None, float)
    if not (xpixsz and focallen and ctx.width and ctx.height):
        return None
    if xpixsz <= 0 or focallen <= 0:
        return None
    xbinning = get_value(ctx.header, 'XBINNING', None, int)
    # Effective pixel size scales with binning (bin2 = 2x pixel width).
    # Use XBINNING (documented assumption when X/Y differ, rare).
    xb = xbinning if xbinning and xbinning > 0 else 1
    resolution = (xpixsz * xb / focallen) * 206.265
    if rel_path and (resolution < 0.1 or resolution > 10):
        logger.warning(f"Unusual resolution {resolution:.3f} arcsec/px in {rel_path} "
                       f"(xpixsz={xpixsz}, xbinning={xb}, focallen={focallen})")
    return resolution


def header_params(ctx, rel_path, file_hash, file_name, mtime, file_size):
    """Every column that comes from the header (and the geometry)."""
    header = ctx.header
    get_value = ctx.get_value
    width, height = ctx.width, ctx.height

    object_name = get_value(header, 'OBJECT', 'Unknown', str).strip()
    date_obs_str = get_value(header, 'DATE-OBS', None, str)
    date_obs = None
    if date_obs_str:
        try:
            normalized_date_str = date_obs_str.replace('/', '-')
            date_obs = Time(normalized_date_str,
                            format='isot' if 'T' in normalized_date_str else 'iso').to_datetime()
        except Exception:
            logger.warning(f"Unparsable DATE-OBS: '{date_obs_str}' in {rel_path}")

    exptime = get_value(header, 'EXPTIME', 0, float)
    if exptime == 0:
        exptime = get_value(header, 'EXPOSURE', 0, float)

    filt = get_value(header, 'FILTER', '', str)
    imgtype = frame_imgtype(ctx)
    xbinning = get_value(header, 'XBINNING', None, int)
    ybinning = get_value(header, 'YBINNING', None, int)
    egain = get_value(header, 'EGAIN', None, float)
    offset = get_value(header, 'OFFSET', None, float)
    xpixsz = get_value(header, 'XPIXSZ', None, float)
    ypixsz = get_value(header, 'YPIXSZ', None, float)
    instrume = get_value(header, 'INSTRUME', None, str)
    set_temp = get_value(header, 'SET-TEMP', None, float)
    ccd_temp = get_value(header, 'CCD-TEMP', None, float)
    telescop = get_value(header, 'TELESCOP', None, str)
    focallen = get_value(header, 'FOCALLEN', None, float)
    focratio = get_value(header, 'FOCRATIO', None, float)
    ra = get_value(header, 'RA', None, float)
    dec = get_value(header, 'DEC', None, float)
    centalt = get_value(header, 'CENTALT', None, float)
    centaz = get_value(header, 'CENTAZ', None, float)
    airmass = get_value(header, 'AIRMASS', None, float)
    pierside = get_value(header, 'PIERSIDE', None, str)
    siteelev = get_value(header, 'SITEELEV', None, float)
    sitelat = get_value(header, 'SITELAT', None, float)
    sitelong = get_value(header, 'SITELONG', None, float)
    gain = get_value(header, 'GAIN', None, float)

    focpos = get_value(header, 'FOCPOS', None, int)
    if focpos is None:
        focpos = get_value(header, 'FOCUSPOS', None, int)

    date_avg_str = get_value(header, 'DATE-AVG', None, str)
    date_avg = None
    if date_avg_str:
        try:
            normalized_date_avg_str = date_avg_str.replace('/', '-')
            date_avg = Time(normalized_date_avg_str,
                            format='isot' if 'T' in normalized_date_avg_str else 'iso').to_datetime()
        except Exception:
            logger.warning(f"Unparsable DATE-AVG: '{date_avg_str}' in {rel_path}")

    swcreate = get_value(header, 'SWCREATE', None, str)
    objct_ra = get_value(header, 'OBJCTRA', None, str)
    objct_dec = get_value(header, 'OBJCTDEC', None, str)
    camera_id = get_value(header, 'CAMERAID', None, str)
    usb_limit = get_value(header, 'USBLIMIT', None, int)
    fwheel = get_value(header, 'FWHEEL', None, str)
    foc_name = get_value(header, 'FOCNAME', None, str)
    focus_sz = get_value(header, 'FOCUSSZ', None, float)
    foc_temp = get_value(header, 'FOCTEMP', None, float)
    if foc_temp is None:
        foc_temp = get_value(header, 'FOCUSTEM', None, float)
    objctrot = get_value(header, 'OBJCTROT', None, float)
    # Mechanical rotator state (the planned OBJCTROT above is not the same
    # thing). ROTATANG is the common alias (same FOCPOS/FOCUSPOS pattern).
    rotator_angle = get_value(header, 'ROTATOR', None, float)
    if rotator_angle is None:
        rotator_angle = get_value(header, 'ROTATANG', None, float)
    rotator_name = get_value(header, 'ROTNAME', None, str)
    readoutm = get_value(header, 'READOUTM', None, str)
    cloudcvr = get_value(header, 'CLOUDCVR', None, float)
    dewpoint = get_value(header, 'DEWPOINT', None, float)
    humidity = get_value(header, 'HUMIDITY', None, float)
    pressure = get_value(header, 'PRESSURE', None, float)
    ambtemp = get_value(header, 'AMBTEMP', None, float)
    roworder = get_value(header, 'ROWORDER', None, str)
    equinox = get_value(header, 'EQUINOX', None, float)

    resolution = frame_resolution(ctx, rel_path)
    fov_w = fov_h = None
    if resolution and width and height:
        fov_w = (width * resolution) / 60
        fov_h = (height * resolution) / 60

    moon_phase = None
    moon_angle = None
    if date_obs:
        date_obs_aware = date_obs.replace(tzinfo=timezone.utc)
        timestamp = date_obs_aware.timestamp()
        moon_phase, moon_angle = get_moon_ephemeris(timestamp)

    bitpix = get_value(header, 'BITPIX', None, int)
    bit_depth = abs(bitpix) if bitpix is not None else None
    bayer = get_value(header, 'BAYERPAT', None, str)
    bayer = bayer.strip().upper() if bayer else None
    if bayer:
        color_type = 'OSC'
    elif ctx.channels == 3:
        color_type = 'RGB'
    elif ctx.channels == 1:
        color_type = 'MONO'
    else:
        color_type = None

    return {
        'path': rel_path, 'file_hash': file_hash, 'name': file_name,
        'mtime': int(mtime), 'file_size': file_size,
        'width': width, 'height': height, 'resolution': resolution,
        'fov_w': fov_w, 'fov_h': fov_h,
        'object': object_name, 'objctra': objct_ra, 'objctdec': objct_dec,
        'imgtype': imgtype, 'exptime': exptime, 'date_obs': date_obs,
        'date_avg': date_avg, 'filter': filt,
        'xbinning': xbinning, 'ybinning': ybinning, 'egain': egain, 'gain': gain,
        'offset': offset, 'xpixsz': xpixsz, 'ypixsz': ypixsz,
        'set_temp': set_temp, 'ccd_temp': ccd_temp,
        'instrume': instrume, 'cameraid': camera_id, 'usblimit': usb_limit,
        'fwheel': fwheel, 'telescop': telescop, 'focallen': focallen,
        'focratio': focratio, 'focname': foc_name, 'focpos': focpos,
        'focussz': focus_sz, 'foctemp': foc_temp,
        'ra': ra, 'dec': dec, 'centalt': centalt, 'centaz': centaz,
        'airmass': airmass, 'pierside': pierside, 'objctrot': objctrot,
        'rotator_angle': rotator_angle, 'rotator_name': rotator_name,
        'readoutm': readoutm, 'cloudcvr': cloudcvr, 'dewpoint': dewpoint,
        'humidity': humidity, 'pressure': pressure, 'ambtemp': ambtemp,
        'siteelev': siteelev, 'sitelat': sitelat, 'sitelong': sitelong,
        'swcreate': swcreate, 'roworder': roworder, 'equinox': equinox,
        'moon_phase': moon_phase, 'moon_angle': moon_angle,
        'bit_depth': bit_depth, 'image_channels': ctx.channels,
        'image_color_type': color_type, 'bayer_pattern': bayer,
    }


def preview_params(ctx, thumb_size):
    """The thumbnail and the 100% crop preview.

    The thumbnail is taken from the array without the colour axis (the same
    one the geometry is measured on), the crop preview from the whole frame.
    """
    thumb, thumb_crop = None, None
    data = ctx.data
    if data is not None:
        thumb_data = data
        if thumb_data.ndim > 2 and thumb_data.shape[0] in (3, 4):
            thumb_data = thumb_data[0]
        if thumb_data.ndim >= 2:
            thumb = make_thumbnail(thumb_data, thumb_size)
            thumb_crop = make_crop_preview(data, thumb_size)
    return {'thumb': thumb, 'thumb_crop': thumb_crop}


def stats_params(ctx):
    """The pixel-level statistics of the frame (any frame type).

    background_mean is a robust median-based estimate (not the sep
    background used for star detection), so it is defined for calibration
    frames too.
    """
    fstats = {'background_mean': None, 'min_pixel': None, 'max_pixel': None,
              'mean_pixel': None, 'median_pixel': None}
    if ctx.data is not None:
        fstats.update(frame_statistics(ctx.data))
    return fstats


def metric_params(ctx, resolution, star_metrics=True):
    """The star/quality metrics (LIGHT frames only).

    analyze_frame() never raises: None means "not computed" (all the columns
    stay NULL), while a result with star_count == 0 means "computed, no stars
    found". Column names follow the initial schema (hfr in pixels, fwhm in
    arcsec).
    """
    star = {'hfr': None, 'fwhm': None, 'hfr_sd': None, 'eccentricity': None,
            'star_count': None, 'snr_weight': None, 'psf_signal': None}
    if star_metrics and frame_imgtype(ctx) == 'LIGHT' and ctx.data is not None:
        computed = analyze_frame(ctx.data)
        if computed is not None:
            star['hfr'] = computed['hfr_avg']
            if computed['fwhm_avg'] is not None and resolution:
                star['fwhm'] = computed['fwhm_avg'] * resolution
            star['hfr_sd'] = computed['hfr_sd']
            star['eccentricity'] = computed['ecc_avg']
            star['star_count'] = computed['star_count']
            star['snr_weight'] = computed['snr_weight']
            star['psf_signal'] = computed['psf_signal']
    return star


def metrics_owed(ctx, star_metrics=True):
    """True when the frame still owes a star-metrics pass.

    Only the LIGHT frames have star metrics: a DARK, a FLAT or a BIAS is
    complete as soon as it is written, so it never enters the slow pass.
    """
    return bool(star_metrics and frame_imgtype(ctx) == 'LIGHT'
                and ctx.data is not None)
