import logging
from datetime import datetime
import mysql.connector

logger = logging.getLogger('reindex.db_utils')

def soft_delete_missing_files(conn, cur, db_files, disk_files):
    logger.info("Marking missing files as deleted (soft delete)...")
    db_paths = {p for p, f in db_files.items() if f['deleted_at'] is None}
    disk_paths = set(disk_files.keys())
    missing_paths = db_paths - disk_paths
    
    if not missing_paths:
        logger.info("Soft delete complete. No missing files to mark.")
        return 0

    batch_size = 500
    missing_paths_list = list(missing_paths)
    update_time = datetime.now()
    hashes_to_update = set()

    for i in range(0, len(missing_paths_list), batch_size):
        batch_paths = missing_paths_list[i:i+batch_size]
        format_strings = ','.join(['%s'] * len(batch_paths))
        cur.execute(f"SELECT file_hash FROM files WHERE path IN ({format_strings})", tuple(batch_paths))
        for row in cur.fetchall():
            hashes_to_update.add(row[0])

    for i in range(0, len(missing_paths_list), batch_size):
        batch = [(update_time, path) for path in missing_paths_list[i:i+batch_size]]
        cur.executemany("UPDATE files SET deleted_at = %s WHERE path = %s", batch)

    if hashes_to_update:
        logger.info(f"Updating duplicate counts for {len(hashes_to_update)} unique hashes...")
        for file_hash in hashes_to_update:
            update_duplicate_counts(conn, cur, file_hash)

    conn.commit()
    logger.info(f"Soft delete complete. Marked {len(missing_paths)} files as deleted.")
    return len(missing_paths)

def purge_deleted_files(conn, cur, retention_days):
    if retention_days <= 0:
        logger.info("Purge skipped as retention_days is zero or less.")
        return 0
        
    logger.info(f"Purging files deleted more than {retention_days} days ago...")
    cur.execute("SELECT DISTINCT file_hash FROM files WHERE deleted_at < NOW() - INTERVAL %s DAY", (retention_days,))
    hashes_to_update = [row[0] for row in cur.fetchall()]

    if not hashes_to_update:
        logger.info("Purge complete. No old files to remove.")
        return 0

    cur.execute("DELETE FROM files WHERE deleted_at < NOW() - INTERVAL %s DAY", (retention_days,))
    removed_count = cur.rowcount
    
    if removed_count > 0:
        logger.info(f"Permanently removed {removed_count} files. Updating duplicate counts...")
        for file_hash in hashes_to_update:
            update_duplicate_counts(conn, cur, file_hash)
        conn.commit()
        logger.info(f"Duplicate counts updated for {len(hashes_to_update)} unique hashes.")
    else:
        logger.info("Purge complete. No old files to remove.")
    
    return removed_count

def update_duplicate_counts(conn, cur, file_hash):
    if not file_hash:
        return
    try:
        cur.execute("SELECT COUNT(*) FROM files WHERE file_hash = %s AND deleted_at IS NULL", (file_hash,))
        total_count = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM files WHERE file_hash = %s AND deleted_at IS NULL AND is_hidden = 0", (file_hash,))
        visible_count = cur.fetchone()[0]
        cur.execute("UPDATE files SET total_duplicate_count = %s, visible_duplicate_count = %s WHERE file_hash = %s", (total_count, visible_count, file_hash))
    except mysql.connector.Error as err:
        logger.error(f"Error updating duplicate count for hash {file_hash}: {err}")
