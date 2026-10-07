<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Normalize the two legacy values the projects matcher compares exactly.
 *
 * normalize_imgtype() and the OBJECT bucket were introduced with this feature and
 * are applied at ingest, so rows indexed before it still carry what the header
 * said: 'Light Frame', 'DarkFrame', 'BIAS ' and so on. Both project paths gate on
 * the four canonical values (projects.py ELIGIBLE_IMGTYPES and the imgtype IN (...)
 * of the backfill, projects_functions.php projectFetchEligibleRow), so those rows
 * were skipped in silence and only recovered if the file happened to be re-read.
 *
 * Same for project_panels.label_object: panels were stored with the raw OBJECT
 * string while the matcher compares it against an uppercased, space-collapsed
 * bucket, so a panel created from 'ngc 7000' could never match 'NGC 7000'.
 *
 * The imgtype rules mirror normalize_imgtype() term for term, including the order
 * of the tests: 'DARKFLAT' contains both DARK and FLAT and resolves to DARK.
 * Anything still unrecognized is left untouched, exactly as ingest does.
 */
final class NormalizeProjectsMatchFields extends AbstractMigration
{
    public function change(): void
    {
        if ($this->hasTable('files') && $this->table('files')->hasColumn('imgtype')) {
// Derived column first, then compare. Heredoc because the SQL mixes
            // quote styles heavily and escaping them inline is unreadable.
            $this->execute(<<<'SQL'
                UPDATE files f
                JOIN (
                    SELECT id,
                           REPLACE(REPLACE(REPLACE(UPPER(TRIM(imgtype)), ' ', ''), '_', ''), '-', '') AS t
                    FROM files
                ) n ON n.id = f.id
                SET f.imgtype = CASE
                    WHEN n.t = '' THEN 'UNKNOWN'
                    WHEN n.t LIKE '%DARK%' THEN 'DARK'
                    WHEN n.t LIKE '%FLAT%' THEN 'FLAT'
                    WHEN n.t LIKE '%BIAS%' THEN 'BIAS'
                    WHEN n.t LIKE 'LIGHT%' OR n.t = 'SCIENCE' THEN 'LIGHT'
                    WHEN n.t = 'UNKNOWN' THEN 'UNKNOWN'
                    ELSE n.t
                END
                WHERE f.imgtype IS NOT NULL AND f.imgtype <> n.t
SQL);
        }

        if ($this->hasTable('project_panels') && $this->table('project_panels')->hasColumn('label_object')) {
            // UPPER + TRIM + collapse inner whitespace, matching the PHP and
            // Python bucket helpers. NULL and empty are left alone so the matcher
            // still falls back to its UNKNOWN bucket.
            $this->execute(<<<'SQL'
                UPDATE project_panels
                SET label_object = UPPER(TRIM(REGEXP_REPLACE(TRIM(label_object), '[[:space:]]+', ' ')))
                WHERE label_object IS NOT NULL
                  AND TRIM(label_object) <> ''
                  AND label_object <> UPPER(TRIM(REGEXP_REPLACE(TRIM(label_object), '[[:space:]]+', ' ')))
SQL);
        }
    }
}