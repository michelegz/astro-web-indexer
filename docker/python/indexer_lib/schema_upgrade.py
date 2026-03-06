"""
Schema upgrade utilities for the reindexer.

When a new FITS header field is added to the database schema, a new entry
must be added to SCHEMA_VERSION_FIELDS mapping the new schema version to the
list of DB column names that are populated from the FITS header.

The upgrade worker reads only the file header (no pixel data, no thumbnail
regeneration), extracts the relevant fields, and returns them to the main
process which performs the DB UPDATE.

To add a new schema version in the future:
  1. Add the DB column via a Phinx migration.
  2. Add the new version and its fields to SCHEMA_VERSION_FIELDS.
  3. Add the corresponding header extraction inside schema_upgrade_worker.
  4. Increment current_schema_version in reindex.py.
"""

import os
import logging

from astropy.io import fits
from xisf import XISF

from indexer_lib.file_utils import get_header_value, get_xisf_header_value

logger = logging.getLogger('reindex')

# Maps each schema version to the DB column names introduced in that step.
# Keys are version numbers; values are lists of field names.
# Only steps with version > old_version are applied to a given file.
SCHEMA_VERSION_FIELDS = {
    2: ['gain'],  # v1 -> v2: GAIN FITS header (camera gain setting, ADU units)
}


def schema_upgrade_worker(task, fits_root):
    """Lightweight multiprocessing worker for schema version upgrades.

    Reads only the FITS/XISF header — does NOT load pixel data or regenerate
    thumbnails.

    Args:
        task: Tuple of (full_path: str, old_schema_version: int)
        fits_root: Root directory of the FITS file tree

    Returns:
        dict with 'status', 'path', 'old_version', and one key per extracted
        field. On error: {'status': 'error', 'path': ..., 'reason': ...}
    """
    full_path, old_version = task
    rel_path = os.path.relpath(full_path, fits_root)
    file_lower = full_path.lower()

    # Determine which fields need to be extracted for this upgrade path
    needed_fields = set()
    for step_v in sorted(SCHEMA_VERSION_FIELDS.keys()):
        if step_v > old_version:
            needed_fields.update(SCHEMA_VERSION_FIELDS[step_v])

    try:
        result = {'status': 'success', 'path': rel_path, 'old_version': old_version}

        if needed_fields:
            header = None
            get_value = None
            if file_lower.endswith(('.fits', '.fit')):
                with fits.open(full_path, ignore_missing_end=True) as hdul:
                    header = hdul[0].header
                    get_value = get_header_value
            elif file_lower.endswith('.xisf'):
                xisf_file = XISF(full_path)
                images_meta = xisf_file.get_images_metadata()
                if images_meta:
                    header = images_meta[0].get('FITSKeywords', {})
                    get_value = get_xisf_header_value

            if header is not None and get_value is not None:
                # v1 -> v2
                if 'gain' in needed_fields:
                    result['gain'] = get_value(header, 'GAIN', None, float)
                # v2 -> v3: add new fields here in the future

        return result
    except Exception as e:
        logger.error(f"Schema upgrade error for {rel_path}: {e}")
        return {'status': 'error', 'path': rel_path, 'reason': str(e)}
