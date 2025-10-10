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
