import xxhash
import logging

logger = logging.getLogger('reindex.file_utils')

def calculate_hash(filepath, block_size=65536):
    """Calculates the xxHash64 of a file."""
    hasher = xxhash.xxh64()
    try:
        with open(filepath, 'rb') as f:
            while True:
                buf = f.read(block_size)
                if not buf:
                    break
                hasher.update(buf)
        return hasher.hexdigest()
    except IOError as e:
        logger.error(f"Error reading file for hashing {filepath}: {e}")
        return None

def get_header_value(header, key, default=None, type_func=None):
    """Safely extracts a value from a FITS header."""
    val = header.get(key, default)
    if val is None or val == '':
        return default
    if type_func:
        try:
            return type_func(val)
        except (ValueError, TypeError):
            return default
    return val

def image_hdu_index(hdul):
    """Index of the first HDU holding an image, decided from the headers alone.

    Touching `hdu.data` is what makes a pass expensive: it reads the whole
    frame, and on a tile-compressed .fz it decompresses it. The header
    already says which HDU carries the image, so the choice is made here
    without the pixels.

    A table extension (XTENSION='BINTABLE') holds data too, but it is not an
    image: it is skipped instead of being taken for one.
    """
    for i, hdu in enumerate(hdul):
        try:
            hdr = hdu.header
        except Exception:
            continue
        if hdr.get('NAXIS', 0) < 1:
            continue
        if hdr.get('XTENSION') in ('BINTABLE', 'TABLE', 'A3DTABLE'):
            continue
        return i
    return None


def pick_image_hdu(hdul):
    """Return (header, data) from the first HDU that actually holds an image.

    Plain FITS: primary HDU (0). CFITSIO/FPACK tile-compressed files (.fz):
    the primary HDU is empty (NAXIS=0) and the image lives in a
    COMPRESSED_IMAGE extension (ZIMAGE=T); astropy decompresses it on .data
    access. The returned header merges the primary (base) with the image
    HDU (which wins: FPACK copies the original keywords there).
    """
    primary_header = hdul[0].header
    idx = image_hdu_index(hdul)
    if idx is None:
        return primary_header, None
    hdu = hdul[idx]
    try:
        data = hdu.data
    except Exception:
        data = None
    if hdu is hdul[0]:
        return primary_header, data
    merged = primary_header.copy()
    merged.update(hdu.header)
    return merged, data


def pick_image_hdu_header(hdul):
    """Return (header, None) from the image HDU, without reading the pixels.

    Same HDU as pick_image_hdu, chosen from the header keywords alone: the
    schema upgrade pass needs the header and nothing else, and it used to pay
    for the whole frame (decompressing it on a .fz) to get it.
    """
    primary_header = hdul[0].header
    idx = image_hdu_index(hdul)
    if idx is None:
        return primary_header, None
    hdu = hdul[idx]
    if hdu is hdul[0]:
        return hdu.header, None
    merged = primary_header.copy()
    merged.update(hdu.header)
    return merged, None


def get_xisf_header_value(header, key, default=None, type_func=None):
    """Safely extracts a value from an XISF FITSKeywords structure."""
    if key in header and header[key]:
        val = header[key][0].get('value', default)
    else:
        val = default
    
    if val is None or val == '':
        return default
        
    if type_func:
        try:
            if type_func == bool and isinstance(val, str):
                if val.lower() == 'true': return True
                if val.lower() == 'false': return False
            return type_func(val)
        except (ValueError, TypeError):
            return default
    return val
