#!/usr/bin/env python3
import os
import mysql.connector
from astropy.io import fits
import numpy as np
from PIL import Image
import argparse
from io import BytesIO

# --- Parameter parser ---
parser = argparse.ArgumentParser(
    description="Reindex FITS files in MariaDB and generate thumbnails."
)
parser.add_argument("fits_root", help="Root directory containing FITS files")
parser.add_argument("--host", default="mariadb", help="MariaDB host")
parser.add_argument("--user", default="awi_user", help="Database username")
parser.add_argument("--password", default="awi_password", help="Database password")
parser.add_argument("--database", default="awi_db", help="Database name")
parser.add_argument("--force", action="store_true", help="Force reindexing of existing files")
parser.add_argument("--thumb-size", default="300x300", help="Thumbnail size WxH, e.g. 400x400")
args = parser.parse_args()

fits_root = args.fits_root
force_reindex = args.force

try:
    thumb_w, thumb_h = map(int, args.thumb_size.lower().split("x"))
    thumb_size = (thumb_w, thumb_h)
except Exception:
    print("Error: --thumb-size format must be WxH (e.g. 400x400)")
    exit(1)

if not os.path.isdir(fits_root):
    print(f"Error: directory {fits_root} does not exist")
    exit(1)

commit_interval = 50  # commit every N files

# --- Funzione thumbnail ---
def make_thumbnail(data, size=thumb_size):
    data = np.nan_to_num(data)
    p_low, p_high = np.percentile(data, [0.5, 99.5])
    stretched = np.clip((data - p_low) / (p_high - p_low), 0, 1)
    img = (stretched * 255).astype(np.uint8)
    image = Image.fromarray(img)
    image.thumbnail(size)
    
    buf = BytesIO()
    image.save(buf, format='PNG')
    return buf.getvalue()

# --- DB ---
conn = mysql.connector.connect(
    host=args.host,
    user=args.user,
    password=args.password,
    database=args.database
)
cur = conn.cursor()

# --- Scansione FITS ---
count = 0
for root, dirs, files in os.walk(fits_root):
    for file in files:
        if file.lower().endswith('.fits'):
            full_path = os.path.join(root, file)
            rel_path = os.path.relpath(full_path, fits_root)
            mtime = os.path.getmtime(full_path)

            cur.execute("SELECT updated_at FROM files WHERE path=%s", (rel_path,))
            row = cur.fetchone()
            if row and not force_reindex:
                print(f'Skipping {rel_path}, already exists.')
                continue

            try:
                with fits.open(full_path) as hdul:
                    header = hdul[0].header
                    data = hdul[0].data

                    object_name = header.get('OBJECT', '').strip() or 'Unknown'
                    date_obs = header.get('DATE-OBS', '')
                    exptime = header.get('EXPTIME', 0)
                    filt = header.get('FILTER', '')
                    imgtype = header.get('IMAGETYP', '').upper() or 'UNKNOWN'

                    thumb = make_thumbnail(data) if data is not None else None

                    cur.execute('''
                        INSERT INTO files
                        (path, name, object, date_obs, exptime, filter, imgtype, thumb)
                        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
                        ON DUPLICATE KEY UPDATE
                        name=%s, object=%s, date_obs=%s, exptime=%s, 
                        filter=%s, imgtype=%s, thumb=%s
                    ''', (rel_path, file, object_name, date_obs, exptime, filt, imgtype, thumb,
                          file, object_name, date_obs, exptime, filt, imgtype, thumb))
                    
                    count += 1
                    if count % commit_interval == 0:
                        conn.commit()
                        print(f'Committed after {count} files.')

                    print(f'Processed: {rel_path}')

            except Exception as e:
                print(f'Error processing {rel_path}: {e}')

conn.commit()
conn.close()
print("Database updated successfully.")