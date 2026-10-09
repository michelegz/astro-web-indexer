# Project regression suite

Verification gates promoted out of `tmp/` (gitignored): only the tests with a
documented verdict below. They all run with `sh tests/run.sh` (requires the
development docker stack: `awi-php`, `awi-python`, `awi-mariadb` with data).
The rest of `tmp/` (probes without a verdict, helpers, manual tools, plans)
stays there.

## How to run them

PHP tests run inside `awi-php`, which has `src/` mounted at `/var/www/html` and
access to the DB. Python tests run inside `awi-python`.

One section needs a deployment mode: **§13.39** (`security_batch_check.php`) only
means something when directory permissions are enforced, i.e. with `AUTH_MODE=full`.
With the default `AUTH_MODE=none`, `isAuthEnabled()` is false, `getAllowedDirs()`
returns `null` and `buildDirPermissionFilter()` emits no filter on purpose — so a
user restricted to one root still gets every row, and the check would report a leak
that cannot exist. The test now skips that section with a note instead of failing.
Run the stack with `AUTH_MODE=full` to actually cover it.

There are **two ways**, and using the wrong one makes a test fail for a reason
that has nothing to do with the code:

| Type | When | Command |
|------|--------|---------|
| standalone | does not need `includes/` (for example `sff_filter_live_check.php`) | `docker cp tests/php/<s>.php awi-php:/tmp/` then `docker exec awi-php php /tmp/<s>.php` |
| with-tree | uses `__DIR__ . '/includes/…'` | copy the tree into `/tmp/harness` and run from there |
| manual tool | requires an argument (for example `stale_check.py <file_id>`) | same as above, but with the argument |

The scripts with a tree are the ones loading `config.php` / `db_functions.php` /
`project_export.php`: `export_regression_probe.php`, `export_dup_scenario.php`,
`hash_parity.php`, `resuggest_check.php`, `api_bootstrap_all_check.php`. With
`src/` mounted, the simplest way to run them is from a copy:

```bash
docker exec awi-php sh -c 'rm -rf /tmp/harness && mkdir -p /tmp/harness && \
  cp -r /var/www/html/api /var/www/html/includes /var/www/html/assets \
        /var/www/html/languages /tmp/harness/ && cp /var/www/html/*.php /tmp/harness/'
docker cp tests/php/<s>.php tests/php/_old_export_probe.php awi-php:/tmp/harness/
docker exec awi-php sh -c 'cd /tmp/harness && php <s>.php'
```

JS tests run locally with Node.

| Test | Verifies | Fixture |
|------|----------|---------|
| `cols_alignment_check.php` | §4 — `<th>`/`<td>` aligned in the integration groups | no writes |
| `frozen_check.php` | §6 — frozen mode does not write | creates and removes 2 test projects |
| `zip_guard_check.php` | §1 — the ZIP export respects `can_download` | creates and removes 1 test user |
| `numbering_check.php` | §9, §8 — numbering without collisions, `session_no` per night | creates and removes 1 test project |
| `export_basename_check.php` | §11 — Windows-extractable basenames, case-insensitive collisions | no writes |
| `addmsg_check.php` | §12 — the error message is outside the hidden steps | creates and removes 1 test user |
| `legacy_normalize_check.php` | §13.6, §13.7 — imgtype and label_object normalization | plants and restores 1 panel |
| `project_visibility_check.php` | §13.8 — the project stays visible, the queue is filtered | creates and removes 1 project + 2 suggestions |
| `thumb_render_check.php` | §13.16 — thumbnails stay detected with `OCTET_LENGTH` | no writes |
| `blob_weight_check.php` | §13.16 — the saving on blobs is real | no writes |
| `api_bootstrap_check.php` | §13.41, §13.2 — the 5 endpoints with the slim bootstrap, no manifest, contract keys | creates and removes 1 user |
| `bootstrap_cost_check.php` | §13.41 — cost of the init.php data block | creates and removes 1 user |
| `security_batch_check.php` | §13.36, §13.37, §13.38, §13.39 — CSRF on the ZIP form, path boundary, JSON encoding, AstroBin permissions | creates 2 users, test tree in `/tmp` |
| `api_contract_check.php` | §13.33, §13.34, §13.35 — 400 with `error` key, 401 JSON, 404 on a nonexistent project, HTML without warnings | creates and removes 1 user |
| `threshold_key_check.php` | §13.1 — one row per group, the constraint rejects duplicates with NULL | rolled-back transaction |
| `write_atomicity_check.php` | §13.3, §13.4, §13.32 — aliases without partial application, one pending per project, `catch (Throwable)` | rolled-back transactions, creates and removes 1 user |
| `export_manifest_check.php` | §13.24, §13.25, §13.26, §13.40 — non-numeric rotation, tolerances, manifest filtered on disk | restores `projects.tolerances` |
| `suggest_parity_check.py` | §13.25, §13.26, §13.27 — `_num_prefix`, uppercase RA/Dec, `get_globals` that propagates | no writes |
| `export_ids_check.php` | §13.10 — 4000 ids via body (GET gives 414), permissions on `get_unmapped_filters.php` | creates and removes 2 users |
| `watcher_queue_check.py` | §13.28, §13.29 — rescan invoked, one connection and one reset | fake connection in `sys.modules` |
| `ux_feedback_check.php` | §13.9, §13.11, §13.13, §13.18 — chart with a hidden column, declared truncation, errors without a query, double space | creates and removes 1 user |
| `igroup_total_check.php` | §13.14, §13.15 — the groups are a partition, also in tile mode | no writes |
| `duplicates_handler_check.php` | §13.44, §13.45 — a single script block, a true `escapeHTML` | creates and removes 1 user |
| `docblock_owner_check.php` | §13.21 — every docblock documents the function that follows, and its claims are true | no writes |
| `export_smoke_check.php` | §11 — `buildProjectExportMap` on a real project, sanitized basenames do not break the map | no writes |
| `resuggest_check.php` | §13 — `resuggestDismissed()` and `getDismissedCount()` on the real path, only dismissed rows are touched | rolled-back transaction (synthetic project when every project has suggestions) |
| `stale_check.py` | §13 — consistency of the suggestion payloads between PHP and Python | no writes |
| `calib_suggest_check.py` | §7 — DARK/BIAS linked to a setup, no orphan sessions | rolled-back transactions (synthetic DARK/BIAS when the archive has none) |
| `session_numbering_check.py` | §8 — `session_no` follows the night on the Python side | rolled-back transaction |
| `watch_backoff_check.py` | §10 — the watcher does not retry every second | no writes (subprocess stub) |
| `imgtype_parity_check.py` | §13.6 — the migration SQL matches Python | no writes |
| `check_chart_ids.mjs` | §3 — canvas ids per metric key | none |
| `check_escattr.mjs` | §5 — `escAttr` closes the `title` attribute | none |
| `api_bootstrap_all_check.php` | the 4 non-projects endpoints migrated to `api_bootstrap.php` no longer load `init.php` | creates and removes 1 user |
| `api_bootstrap_sweep.php` | the same 4 endpoints on **happy and error paths**; no PHP diagnostic in the body | no writes |
| `calib_filter_id_check.php` | the filter column name arrives from the JSON body and is validated | no writes |
| `sff_filter_live_check.php` | the filter-based calibration search really returns valid JSON, and the whitelist rejects | no writes |
| `sff_filter_http_escape_check.php` | the filter panel that `sff.js:53` assigns to `innerHTML` does not execute a FITS header, **on real HTTP** | inserts and deletes 1 `files` row and 1 test user; counts verified |
| `sff_results_http_escape_check.php` | the results table that `sff.js:152` assigns to `innerHTML` does not execute the archive's `name`/`path`, and no PHP diagnostic reaches the panel | inserts and deletes 2 `files` rows and 1 user; counts verified |
| `sff_payload_check.php` | `find_calibration_files.php` no longer carries the blobs in the response, and the thumbnails go through `image.php` | no writes |
| `cols_test.php` | column registry: the toggleable set and the groups describe each other, `name` is not hideable | no writes |
| `lang_selector_sink_check.php` | renders the real `language_selector.php` with a hostile `$_GET` and checks the parsed DOM | no writes |
| `reindex_batch_continue_check.py` | a record the DB rejects does not stop the rest of the pass: 4 blocks of 50, 150 files committed | writes synthetic rows into `files` with a unique prefix, then removes them; counts verified back to the initial state |
| `file_cells_escape_check.php` | `file_cells.php` escapes text, attributes and href in both scopes (`main` and `project`): 62 payload occurrences, none raw | no writes (synthetic row) |
| `table_escape_check.php` | `table.php` escapes in **both views**, with two distinct named sinks verified by position, and no PHP diagnostic reaches the HTML it parses | no writes (synthetic row) |
| `tree_render_escape_check.php` | `projects_tree.php` escapes text, attribute and tooltip in **all three** modes (`hypoMode` 1 and 0, review 2 with `suggestion_ids[]` as an integer and the reason escaped) | no writes (synthetic tree) |
| `sff_filter_escape_check.php` | `sff_filter_template.php` escapes the reference value in text, `value=` and `data-unit=` | no writes |
| `tree_preview_escape_check.php` | the real HTTP chain `project_tree_preview.php` → JSON `html` → `main.js:646` | creates and removes 1 user; no writes (the preview rolls back the transaction) |
| `export_regression_probe.php` | a frame of one type lives under that type's folder; no `fid` in two folders without being a declared duplicate, and the old builder must violate the invariant or the probe reports that it proves nothing | rolled-back transaction (synthetic DARK + BIAS) |
| `export_dup_scenario.php` | a calibration linked to two setups: the new builder emits both copies and declares them | rolled-back transaction (synthetic BIAS) |
| `hash_parity.php` + `hash_parity.py` + `hash_parity_diff.py` | **PHP<->Python parity gate**: `config_hash` and `match_inputs` must match, or every dismissal goes stale on every pass | no writes |
| `stale_check.py`, `stale_resurrect.py` | manual diagnostic tools, require `<file_id>` | rolled-back transaction |

### The remaining JS `innerHTML` sinks: verified and refuted

The client has four `innerHTML = <HTML built by the server>`. All of them were
audited, and none is a defect. It is worth writing down, because three of the
four *looked* suspicious and two are refutations that need a measurement to be
credible.

| Sink | Source | Outcome |
|---|---|---|
| `main.js:646` | `api/project_tree_preview.php` → `includes/projects_tree.php` | complete escaping, covered by `tree_render_escape_check.php` and `tree_preview_escape_check.php` |
| `sff.js:152` | `api/find_calibration_files.php` → `sff_results_table.php` | already verified in a previous session |
| `sff.js:53` | `api/sff_get_filters.php` → `sff_filter_template.php` | complete escaping, covered at harness level by `sff_filter_escape_check.php` and **on real HTTP** by `sff_filter_http_escape_check.php` |
| `main.js:490` | `api/project_preview.php`, HTML built **client-side** with `escHtml`/`escAttr` | safe by construction |

The three unescaped `${error.message}` interpolations were the other suspect.
None is controllable from the server, and two close without even looking at the PHP:

- **`sff.js:57`** — the error comes from `throw new Error('Network response was not ok')`,
  a literal client-side string. No server text reaches that branch.
- **`main.js:288`** — `get_duplicates.php` never puts `$_GET['hash]` in the response:
  on success it returns the array of duplicates (which has no `error` key), and on
  error only constants and `__('error_fetching_duplicates')`.
- **`sff.js:164`** — here the suspicion was real: `find_calibration_files.php:223` does
  `json_encode(['error' => 'Database query failed: ' . $e->getMessage()])`, so the
  driver message was entering the HTML. **Refuted by measuring**: a
  `PDOException` on this configuration (`ATTR_EMULATE_PREPARES => false`) reports
  only the driver diagnostic and the query trace, **never the values of bound
  parameters**. Tried with three shapes, among them a payload bound as a parameter
  to a failing query: none made it reappear. And the query text is
  built only from filter ids and `search_type` values that already passed the whitelist.

`main.js:350` (`return d.innerHTML` inside `escHtml`) is not a defect: it is the
standard DOM-based escaping idiom, and `main.js:352` already documents its limit —
it escapes `& < >` and not quotes — with `escAttr()` next to it for attributes. That
distinction is already covered by `check_escattr.mjs`.

### What in `tests/` is not a test
`tests/` contains other things too, and not all of them should run in the suite:

- **helper**: `php/_old_export_probe.php` is the frozen pre-fix builder (functions with
  an `OLD_` prefix so they do not collide, plus `buildProjectExportMapOld()`), used as a
  comparison baseline. It is **versioned**: it used to live in `tmp/`, which is gitignored,
  so on a fresh checkout it was simply absent and both export probes died on the missing
  `require` — a test that cannot run is not a test. `run.sh` copies every `tests/php/*.php`
  into the harness, so placing it there makes the dependency travel with the suite; the
  runner skips `_`-prefixed files so the helper is not recorded as a green gate over a file
  with no assertions.
- **diagnostic probes** (`imgtype_probe.php`, `schema_probe.php`, `phinxlog_probe.php`,
  `snr_diag.py`, `frame_smoke.py`): they print tables, they have no verdict.
- **throwaway** (`usa-e-getta`): `dbg.php`, `dupdebug.php`, `cksec.php`, `srrender2.php`,
  `refactorcheck.php` — `refactorcheck.php` and `dupdebug.php` declare themselves
  as «Throwaway, deleted after run» and are still there: candidates for deletion.
- **plans**: the `*-plan.md` files and the `scan_php.ps1` that generated them.
- **threshold experiments**: `sep_dense.py`, `sep_smoke.py` reach an `AssertionError`
  on a calibration threshold: they are measurement probes, not gates. `snr_diag.py`
  and `frame_smoke.py` need FITS files on disk.

Note: `frame_smoke.py`, `sep_grid.py`, `sep_hot.py` and `sep_poison.py` were **dead**
silently. They imported from `/pkg` and `/mod`, i.e. from the library layout that
predates `projects.py` and `star_analysis.py` moving under `indexer_lib`. They were
already in `tests/` and showed up nowhere: no assertion, no visible error, just the
absence of output.

### PHP<->Python parity gate

`hash_parity.php` and `hash_parity.py` emit the same JSON shape on stdout; the
comparison is the gate. It has to run end to end, because it is precisely the
manual capture of the two halves that had left the gate rotten:

```bash
docker exec awi-php sh -c 'cd /tmp/harness && php hash_parity.php' > /tmp/php.json
docker cp /tmp/php.json awi-python:/tmp/php.json
docker exec awi-python sh -c 'cd /tmp && python hash_parity.py' > /tmp/py.json
docker cp /tmp/py.json awi-python:/tmp/py.json
docker exec awi-python sh -c 'cd /tmp && python hash_parity_diff.py php.json py.json'
```

It deliberately tolerates five keys that Python emits and PHP does not
(`globals/tol_*`, except those already listed): both hashes iterate the **same**
fixed list of keys — `PROJECT_SUGGEST_TOL_KEYS` in `projects_functions.php` and
`SUGGEST_TOL_KEYS` in `indexer_lib/projects.py` — so a richer `globals` does not
enter the hash. Any other difference is a real parity break.

`AWI_PROJECTS_LIB` is the **parent** directory that contains `indexer_lib`, i.e.
`/opt/scripts`, not `/opt/scripts/indexer_lib`: `calib_suggest_check.py` does
`from indexer_lib.projects import …`. The tests that did `import projects` with
`sys.path` on `/opt/scripts` died with `ModuleNotFoundError`.

Python tests run inside `awi-python`:

```bash
docker cp tests/<script>.py awi-python:/tmp/
docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python sh -c 'cd /tmp && python <script>.py'
```

> **Why `/tmp` and not the mounted directory.** `src/` and `docker/python/indexer_lib`
> are bind mounts inside the containers: writing `/var/www/html/...` or
> `/opt/scripts/indexer_lib/...` from `docker exec` **overwrites the local file
> too**. It happened three times and each time the fix was lost. The tests must
> therefore be copied into `/tmp` inside the container.
>
> If you need a regression against a source file, **save it outside the container
> before** overwriting it, and reapply it locally afterwards.

## Regression

`cols_alignment_check.php`, `frozen_check.php` and `calib_suggest_check.py` also
verify the behavior *before* the fix.

### The export probes get their DARK and BIAS frames from a fixture

`export_regression_probe.php` and `export_dup_scenario.php` both compare the shipped
builder against `php/_old_export_probe.php`, the frozen pre-fix copy whose bug is a
**duplicated, empty** `elseif ($kind === 'bias')` branch: `$leaf` keeps the value left by
the previous group, and `groupCalibrations()` always emits darks before bias — so a node
holding both a DARK and a BIAS makes the bias frames land under `DARK/EXPS_600_TEMPC_m10/`
instead of `BIAS/`.

That branch is unreachable without a bias group, and this archive has FLAT and LIGHT
frames only: no DARK, no BIAS. Both probes used to refuse to run, or skip their case,
reporting PASS while testing nothing. They now build the frames themselves through
`php/_export_fixture.php`, inside the transaction that was already there:

- `exportFixtureDarkAndBias()` adds one DARK and one BIAS at the project's first setup,
  linked so the builder picks them up. The **pair** is deliberate — a BIAS alone either
  lands under an unrelated leaf or, as the first group of the node, leaves `$leaf`
  undefined (`SETUP_Sn//frame.fits`, visible as a `Warning`).
- `export_dup_scenario.php` only needs a BIAS to share between two setups, so it calls
  `exportFixtureFrame('BIAS', ...)` directly.

Measured with the fixture in place, the old builder now emits
`SETUP_S1/DARK/EXPS_600_TEMPC_m10/frame_1.fits` for a `kind=bias` entry and the probe says
`pre-fix: the old builder violates the invariant on 1 entries`. That is the failure the
comparison exists to detect, so it is worth checking it still appears: a probe whose
pre-fix side stops breaking proves nothing.

`calib_suggest_check.py` and `resuggest_check.php` had the same shape of gap and were
fixed the same way: a synthetic DARK and BIAS for the branch the §7 fix is about, and a
throwaway project when every project in the archive already carries suggestions. Both
create their fixture inside the transaction the test already rolled back, so nothing
survives. When the archive does provide the rows, they are used as they are.

The lesson is trap #32 applied to fixtures: **a probe that cannot reach the broken code
must fail, not pass.** Creating the input is what turns these three gates back into tests.

Both export probes had **no terminal `exit()`**, so a real assertion failure still returned
0 and `run.sh` recorded PASS. `export_regression_probe.php` additionally did `exit(0)` on an
empty archive, which is the same silent pass by another route. Both now exit on the
failure count.

```bash
# §4: remove the star gate in file_cells.php
docker exec awi-php sh -c 'cd /var/www/html && cp includes/file_cells.php /tmp/fc.bak && \
  sed -i "/if (\$groupKey === .star. && empty(\$pStar)) {/,+2d" includes/file_cells.php'
docker exec awi-php php /var/www/html/cols_alignment_check.php   # expected: star OFF MISALIGNED
docker exec awi-php sh -c 'cp /tmp/fc.bak /var/www/html/includes/file_cells.php'

# §6: remove the frozen guard in projects_functions.php
docker exec awi-php sh -c 'cd /var/www/html && cp includes/projects_functions.php /tmp/pf.bak && \
  sed -i "/Frozen means locked. Checked here as well/,/^    }$/d" includes/projects_functions.php'
docker exec awi-php php /var/www/html/frozen_check.php          # expected: case B "CHANGED <<< BUG"
docker exec awi-php sh -c 'cp /tmp/pf.bak /var/www/html/includes/projects_functions.php'

# §7 and §8 (Python): copy the library with the PRE-fix version, then test against the copy
docker exec awi-python sh -c 'rm -rf /tmp/oldlib && mkdir -p /tmp/oldlib && \
  cp -r /opt/scripts/indexer_lib /tmp/oldlib/ && rm -rf /tmp/oldlib/indexer_lib/__pycache__'
git show HEAD~1:docker/python/indexer_lib/projects.py | \
  docker exec -i awi-python sh -c 'cat > /tmp/oldlib/indexer_lib/projects.py'
docker exec -e AWI_PROJECTS_LIB=/tmp/oldlib    awi-python sh -c 'cd /tmp && python calib_suggest_check.py'
docker exec -e AWI_PROJECTS_LIB=/opt/scripts  awi-python sh -c 'cd /tmp && python calib_suggest_check.py'
docker exec -e AWI_PROJECTS_LIB=/tmp/oldlib    awi-python sh -c 'cd /tmp && python session_numbering_check.py'
docker exec -e AWI_PROJECTS_LIB=/opt/scripts  awi-python sh -c 'cd /tmp && python session_numbering_check.py'
```

`numbering_check.php` needs the migrations applied (the unique indexes): without
`uq_project_setups_no` / `uq_project_panels_no` the concurrency test proves
nothing, and it says so at the start of the output.

### The three escaping tests must be tried red, not just green

An escaping test that has never been seen failing proves nothing. The three
(`tree_render_escape_check.php`, `sff_filter_escape_check.php`,
`tree_preview_escape_check.php`) were broken one at a time to see which checks
light up, then restored. The method used is editing the file in the
repo followed by `git checkout --`: **`sed` is not suitable for these PowerShell-written
lines**, because the pattern contains `<?=` and the `<` is read as an operator
(trap #6, already hit). The bind mount does the rest: modifying the file in the repo
is visible to the container, so it is enough to wait for the opcache.

```bash
# 1. remove the escaping of ONE context, run the tests, restore
#    (the edit happens in the repo, not inside the container)
# 2. wait for the opcache: opcache.revalidate_freq=2, trap #5
sleep 3
docker exec awi-php sh -c 'cd /tmp && php tree_render_escape_check.php 1; \
  php tree_render_escape_check.php 0; \
  php tree_render_escape_check.php 2; \
  php sff_filter_escape_check.php; \
  php tree_preview_escape_check.php'
# 3. restore and recheck that they are green again
git checkout -- ../src/includes/projects_tree.php ../src/includes/sff_filter_template.php
```

The three cases tried, with the expected verdict, so the check is repeatable:

| Break introduced | Test to run | Expected |
|---|---|---|
| `projects_tree.php:250` — `data-setup-name` without `htmlspecialchars` | `tree_render_escape_check.php 1` | **green**: the attribute is in the `!$hypoMode` branch, the preview does not reach it |
| same, same file | `tree_render_escape_check.php 0` | **red**: `every occurrence has '<' as an entity` with `1 raw`, `attribute onload on <button>`, `and its value stays closed` |
| `projects_tree.php:252` — `renderSetupFingerprint` without `htmlspecialchars` | `tree_preview_escape_check.php` | **red**: occurrences 1/2 escaped, `<img>` and `<svg onload>` injected, HTML 22 bytes shorter |
| `sff_filter_template.php:38` — `value=` without `htmlspecialchars` | `sff_filter_escape_check.php` | **red**: 4 checks, among which `the value is escaped in value= (line 38)` |
| `sff_filter_template.php:37` — text without `htmlspecialchars` | `sff_filter_escape_check.php` | **red**: `element <img>`, `attribute onerror on <img>` |

The three tests share three things that make them credible, and they must be kept
if one of them is modified:

- **positivity check before the verdict.** The payload must have *arrived*
  in the HTML (`substr_count` > 0) and, better, every one of its occurrences must be followed by
  `&lt;`. Without this, «no injection» is true even when the page was empty.
- **second opinion from the parsed DOM.** `DOMDocument` + `DOMXPath` look for an element
  or an `on*` attribute that the template did not write. It is the only one of the two checks
  that catches the attribute break, because there the character after the payload is `<`
  and not `"` (trap #39).
- **positional check of the context.** «The payload is escaped somewhere» is not
  enough: the position is checked (`text-gray-300">PAYLOAD&lt;`,
  `value="PAYLOAD&lt;`, `data-setup-name="PAYLOAD&lt;`). A global check
  would pass even if the uncovered half were unprotected.

### `reindex_batch_continue_check.py`: the pre-fix case

This runs against the real `files` table, because `awi_user` cannot create an isolated
schema. To try the **pre-fix** code you must not write on the `/opt/scripts`
mount (trap #38): copy the tree elsewhere and point `REINDEX_PY` and `AWI_PROJECTS_LIB`
there. The two parameters are printed at the start, and their absence from the output is the
signal that you are running the old copy of the probe (trap #47).

```bash
# pre-fix tree, outside the mount
docker exec awi-python sh -c 'rm -rf /tmp/prefix_lib && mkdir -p /tmp/prefix_lib && \
  cp -r /opt/scripts/indexer_lib /tmp/prefix_lib/ && rm -rf /tmp/prefix_lib/indexer_lib/__pycache__'
git show "d4ac075^:docker/python/reindex.py" | \
  docker exec -i awi-python sh -c 'cat > /tmp/prefix_lib/reindex.py'
docker exec awi-python sh -c 'printf "pre-fix : "; grep -c batch_params.clear /tmp/prefix_lib/reindex.py'
docker exec awi-python sh -c 'printf "post-fix: "; grep -c batch_params.clear /opt/scripts/reindex.py'
# 1 vs 2: the pre-fix empties batch_params only after a successful flush

docker cp tests/reindex_batch_continue_check.py awi-python:/tmp/
# post-fix: green
docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python \
  sh -c 'cd /tmp && python reindex_batch_continue_check.py'
# pre-fix: red
docker exec -e AWI_PROJECTS_LIB=/tmp/prefix_lib -e REINDEX_PY=/tmp/prefix_lib/reindex.py \
  awi-python sh -c 'cd /tmp && python reindex_batch_continue_check.py'
```

What distinguishes the two, measured:

| | post-fix | pre-fix |
|---|---|---|
| exit code | 0 | **1** |
| committed rows | **150** out of 199 | 100 out of 199 |
| lost blocks | 1 out of 4 | **2 out of 4** |
| error rows in the log | 1 | one per file, from that moment on |

The test asserts **both** the count and the exit code, and the exit code is not
decorative: the tail flush is outside the `try`, so with the poisonous record in the last
block the pre-fix would still commit 150 files and fail only on the `sys.exit(1)`.
With the count alone the test would be ambiguous in that ordering.

Note that the case described here is not the most severe possible: the **current** code
is not even able to notice the «poisonous record in the last block» case other than
because the tail flush propagates. It is a deliberate choice (see the message of
`d4ac075`: the work already committed is safe and the failure is noisy), so the
test does not put it under examination, but the limit is written here because the test
does not cover it.

### The PHP partials rendered by the server

The audit of the client `innerHTML` (above) only covers the HTML arriving via
`fetch`. The partials the server prints directly are a separate surface, and
the largest one was untouched. `file_cells.php` draws **all** the rows
of the archive: on this copy 365 files, each with name, object, filter, instrume,
cameraid, telescop and forty other FITS headers. Whoever deposits a file checks it.

`file_cells_escape_check.php` drives the real partial with a synthetic row in which every
string column of the archive contains the payload, in both scopes:

- `main` — `table.php:103`, the main table of the home page
- `project` — `igroup_files_table.php:29`, the project integration group tables

Outcome: **62 payload occurrences, all escaped or percent-encoded, zero raw.** The
three contexts are distinct and are checked separately:

| Context | How it writes it | What the payload produces |
|---|---|---|
| text | `htmlspecialchars()` on every string column | `XSS&lt;img …` |
| attribute | `htmlspecialchars()` in `fileCellAttrs()` (line 71), on the raw `data-val` | `XSS&lt;img …` |
| href | `rawurlencode()` on the path (line 228) | `DIR%2FXSS%3Cimg%20…` |

The test counts **three shapes** of the payload — raw, HTML entities, percent-encoded — and
requires the raw one to be 0 and the other two to sum to the total. If the path
reached the href without encoding, the raw shape would rise and the test would say so.

Points that reading alone was not enough to close, and that the test blocks:

- **`$nameSuffix` (line 230) is the only point that prints without `htmlspecialchars`.** The
  only caller, `igroup_files_table.php:21-26`, passes it fixed markup around a
  translation, so it is not controlled by the archive. The test verifies that invariant
  (`$nameSuffix contiene solo il markup e la traduzione del chiamante` / `  e nessun payload ci passa dentro`) instead of taking it for
  granted: it is the kind of argument that holds until someone adds a caller.
- **`getMoonPhaseMarkup(?float, ?float)`** has the declared type, so a non-numeric string
  becomes a `TypeError` instead of ending up in the HTML: safe, but for a reason that
  is not escaping.
- **`resolution`, `fov_w`, `fov_h` and `file_size`** are formatted **without a cast**
  (`number_format($f['fov_w'], 1)`, `$f['file_size'] / 1048576`). Here the test puts
  numbers, not the payload, and the reason is written in the file: they are not controlled
  by the archive (`resolution = (xpixsz * xb / focallen) * 206.265`, `file_size =
  os.stat().st_size`), and with a string the test would die of `TypeError` without saying
  anything about escaping. It is a fragility, not a demonstrated defect, and therefore not a
  fix: if one day those fields arrived from a non-numeric source, the symptom
  would be a 500 and not an escape.

The pre-fix case is tried by removing a single `htmlspecialchars` and waiting 3 seconds
for the opcache:

| Break introduced | Expected |
|---|---|
| line 229, `name` without `htmlspecialchars` | **red**: `no raw occurrence` goes red with `raw=1`, plus `element <img>` and `<svg onload>` injected, in both scopes |
| line 71, `data-val` without `htmlspecialchars` | **red in `project` only**: `raw=20`, `attribute onload on <td>`; in `main` it stays green, because that branch does not carry `data-val` |

The second line is the most informative: an `innerHTML` check or a `main`-only field
would not have seen it, because the incriminated branch exists only in `project`.

### How the filter panel was closed on real HTTP

The hole that `sff_filter_http_escape_check.php` closes was declared in this section
on security: the reference value of `render_sff_filter()` comes from
`files.<column>`, i.e. from the FITS header, and **there is no way to render it
controllable by the client without writing a row into `files`**. As long as DB writes
were forbidden the check could only exist at harness level.

Now the real path is proven end to end:

```
GET /api/sff_get_filters.php?id=<id>&type=lights        (real session, real CSRF)
  -> SELECT * FROM files WHERE id = :id AND imgtype = 'LIGHT'
  -> render_sff_filter($config, $referenceFile[$key])   for each active key
  -> echo, with Content-Type: text/html
  -> sff.js:53   sffFiltersPanel.innerHTML = html
```

With `type=lights` the active columns are `object`, `filter`, `instrume`, `cameraid`,
`exptime`, `ccd_temp`, `xbinning`, `ybinning`, `ra`, `dec`, `objctrot`, `fov_w`, `fov_h`,
`moon_phase`, `width`, `height`, `date_obs`. The strings are the first four.

Outcome: **8 payload occurrences, all escaped, zero raw**, and the two contexts
shown at byte level (`text-gray-300">XSS&lt;img …` and `value="XSS&lt;img …`).

The test file is **not written to disk**: only a row in `files`, deleted by
exact `id`. No reindex, no file in `/var/fits`, no other row touched. The
`finally` deletes row and user even when the test dies halfway, and this is verified:
during development one version died with `1406 Data too long` and the count
went back to 365 all the same.

Three things that the HTTP path made necessary and that a harness would not have
asked for:

- **`filter` is `varchar(50)` with `STRICT_TRANS_TABLES`.** The short copy of the payload
  had to fit in 50 characters, otherwise the `INSERT` died with `1406` and the test
  measured the database constraint instead of the escaping. That is why it has its own
  marker (`Z9`): without it, it was indistinguishable from the other three copies, which
  the counts report only for `XSS`.
- **The positivity check belongs on the endpoint, not on the template.** Before the hostile
  request the test asks for the same panel for the archive's first real LIGHT and requires
  200 with non-empty HTML. «The payload is not injected» is true even when the endpoint
  returned nothing, and over HTTP that distinction costs one request.
- **`imgtype` must be exactly `'LIGHT'`**, otherwise the `WHERE` does not find the row
  and the test would measure a 404 disguised as an escaping check.

The pre-fix case, by removing a single `htmlspecialchars`:

| Break introduced | Expected |
|---|---|
| line 38, `value=` without `htmlspecialchars` | **red**: `raw=3` on `XSS` and `raw=1` on `Z9`, `attribute onload on <input>`, `the hidden value= is escaped (line 38 of the template)` |
### `table.php`: two views, two distinct sinks

`table.php` is the shell of the main table and contains **two** renderings of the same
data: the list view, which delegates the cells to `renderFileTableCells` (covered by
`file_cells_escape_check.php`), and the **cards view**, which rewrites from scratch name, path,
object, filter, exptime, imgtype and date_obs. They are separate code: a fix to the
first does not touch the second.

`table_escape_check.php` covers both: **14 payload occurrences, 12 escaped and 2
percent-encoded, zero raw**, with the two named sinks verified by position
(`class="thumb-title"` for the cards, the delegated cell for the list) because a global check
would pass even if only one of the two were uncovered.

The pre-fix case, by removing the `htmlspecialchars` of the name in the cards view (line 146):

| Break introduced | Expected |
|---|---|
| `htmlspecialchars($f['name'])` → `$f['name']` in the cards view | **red**: `every occurrence is escaped or percent-encoded` goes red with `raw=1`, `<img onerror>` and `<svg onload>` injected, and `cards view: the name is escaped in its own <a>` goes red with the detail «the thumb-title block does not contain the escaped name» |

One thing the test discovers and that is easy to take for granted: **the `viewMode` cookie does not
choose which view gets rendered**, it only changes the `hidden` class of a
container. The partial always prints both, so the two executions give the same
counts but **different bytes** (40111 in both, but not identical). An invariant written
on bytes would have failed on the correct code; the right invariant is «same payload
occurrences, and all escaped».

Not covered here: the project names in the modal's `<option>` (line 36). They are
`htmlspecialchars`, but testing them would require a hand-inserted project, and so far it has been
preferred not to write project rows.

## Test traps (not production-code traps)

1. **`projects.php` answers `302 → /?panel=projects` even with a valid session.**
   You cannot deduce "not authenticated" from the 302: the signal is a body that contains
   the login form (`name="password"`).
2. **`project_files` is keyed `(file_id, level, node_id)`.** There is no
   `project_files.setup_id`, and lights get linked at `filter` level, not
   `setup`. Counting only `level='setup'` gives a false "0 added".
3. **In the dataset the DARK/BIAS belong to a different telescope than the LIGHTs**
   (the setup fingerprint includes `TELESCOP`), so the §7 test uses two
   test projects. Also `already_processed` answers `skipped` if the
   same file is proposed again: for two probes on the same file the suggestion row
   must be deleted in between.
4. **`astro_night` comes back as `datetime.date`**, not a string: dictionary keys
   must be normalized with `str()`.
5. **`opcache.revalidate_freq=2`.** After modifying a PHP it still serves the
   cached markup: a regression on `addmsg_check.php` falsely passed
   the first time for this reason, and only failed after `sleep 4`. Before comparing
   pre-fix and post-fix, **wait at least 3 seconds**.
6. **`json_encode` can return `false`** on real data (non-UTF-8 bytes in the
   `files` rows), and `echo false` produces an empty string. `project_visibility_check.php` was
   its victim: the JSON comparison was always false, so the test would
   have passed for the wrong reason. Collect the values directly from the structures.
7. **`CURLOPT_POSTFIELDS` with an array** sends `multipart/form-data`, not
   `application/x-www-form-urlencoded`. `login.php` and the form endpoints read from
   `$_POST`, so the login fails **silently** (HTTP 200 with the login page
   inside) and every subsequent request comes out unauthenticated. Use
   `http_build_query()` explicitly.
8. **A failed `require_once` is fatal and `@` does not silence it.** A test meant to run
   both on the pre-fix code and on the correct one cannot `require` a file that
   does not exist in the pre-fix: `if (file_exists(...))` is needed. With the shim in `security_batch_check.php`
   the same test reports the 13 defects on the old code and passes on the new one.
9. **Do not buffer a ZIP archive to test it.** The export of a real project
   exceeds the 512M `memory_limit` and the test dies of memory exhaustion instead of
   reporting the defect. `httpPostHead()` in `security_batch_check.php` collects the
   first bytes with `CURLOPT_WRITEFUNCTION`.
10. **Missing keys in PHP are `null`, not an error.** The contract of
    `parseProjectAddRequest` / `projectAddPrepare` is partly camelCase
    (`customSetups`, `groupFpOverrides`, `customSkipped`) and partly snake_case
    (`project_id`, `new_project`), so a style refactor that "aligns" the names
    breaks the branches with nothing signalling it. `api_bootstrap_check.php` compares the keys
    read by the endpoints with those returned by the functions.
11. **`main.js` only looks at `data.error`.** The wizard's `fetch` calls do
    `.then(r => r.json())` and then `if (data.error) throw`, without checking `r.ok`.
    So a `{"success":false}` with HTTP 400 still produces no useful error: the next
    branch was building an empty preview. The error responses of the four JSON endpoints
    must have the `error` key, not just the status.
12. **A `require_once` of a partial that renders markup executes the markup.** `igroup_files_table.php`
    is a partial that prints immediately and expects `$grp`/`$gi`: requiring it at bootstrap level
    produces warnings that end up in the JSON body (`401` preceded by 2.7 KB of
    junk HTML). Partials must be included by the caller in the right context.
    `api_contract_check.php` checks that no `Warning`/`Notice`/`Deprecated` reaches
    the HTML of the home page and of the project detail.
13. **A text search on the body of a function can find its own comment.**
    `threshold_key_check.php` verified that `getProjectThresholds` sorted by
    `ORDER BY id DESC`, and it passed even without it: the searched string was in the comment
    explaining why. The SQL string extracted from the `prepare()` must be checked, not the
    body. It is the same class as #11, and like that one I found it because the test
    passed where it should have failed.
14. **`change()` cannot invert a raw `execute()`.** The first version of the migration
    `FixGroupThresholdsUniqueKey` said "reverted" without having touched anything, leaving
    a schema that matched no version: explicit `down()`.
    `20261019120000_add_numbering_unique_indexes.php` has the same limit.
15. **Before declaring "pre-fix fails", remove the migration too.** With the new
    schema and the old code `threshold_key_check.php` passed: the upsert started
    working again. The two halves of the fix were independent and the new schema masked the
    code defect. The proof needs the original schema *and* the original code.
16. **Do not infer the request format from the argument type.** The first
    `httpPost` of `write_atomicity_check.php` sent JSON to the login too (trap #7,
    already documented: the login fails silently). After correcting it by deducing "array = form",
    the endpoints started receiving urlencoded, because they too send arrays.
    The format is now an explicit flag.
17. **A single-threaded test cannot demonstrate a race.** "Two calls to
    `enqueueSuggestRequest` produce one row" passes even with code that protects
    nothing, because the two calls are sequential. The proof is the direct double
    insert, which simulates the two writers and fails without the constraint. This holds in general:
    a green test on a race is not proof of the race.
18. **PDO has no nested transactions.** To verify that a function does not commit
    on its own when the caller has already opened a transaction, open **one** transaction
    and call the function inside it: do not nest `beginTransaction`.
19. **You cannot launch `docker` from the container.** `export_manifest_check.php` wanted to compare
    the PHP results with the Python ones by calling `docker exec`: inside `awi-php` there is no
    docker CLI, so the comparison was mute. Replaced by anchoring both tests to the same
    expected values, computed from first principles in both: if one half implements
    it differently, its check fails anyway.
20. **A test that dies at the first failure informs less than one that lists them.**
    `suggest_parity_check.py` pre-fix did `abs(None - x)` and died of `TypeError`, so
    of 16 defects only one was visible. Now it has `close()` and `fmt()` that tolerate `None` and
    the complete list is what counts in the pre-fix run.
21. **`''` inside a PHP string is not an apostrophe.** `'a''{value}''b'` is read as
    the string `'a'` then `{value}` then `'b'`: parse error. If you need an apostrophe in an
    i18n message, do not use `''`.
22. **Tolerances have a unit, they are not numbers.** The defaults are `'1%'`, `'2C'`,
    `'3deg'`, `'10%'`, so a validation like `/^\d+(\.\d+)?$/` on the saved value
    rejects every legitimate value and breaks the feature. The right condition is that the string
    **starts** with a non-negative number. `export_manifest_check.php` covers the branch with
    units explicitly, because it is the risk a reasonable validation introduces.
23. **After a Docker Desktop restart the bind mounts are served from a stale cache.**
    The container kept showing the *pre-fix* version of `watch_fs.py` while the local file
    was already correct, and `watcher_queue_check.py` therefore **passed pre-fix** without
    looking at the correct code. Restart the container before trusting a pre-fix check.
    In the same group nginx had resolved `php` when it was at `.4` while the
    container had moved to `.5`, with 502 on every request: restarting nginx fixes it.
24. **In Python, assigning a variable at module level makes it local.** In the new
    `process_suggest_queue()` I wrote `_suggest_conn = None` to handle a dropped
    connection: from that point Python treats it as local and the first read raises
    `UnboundLocalError`. `global` is needed.
25. **An i18n key never reaches the HTML, its value does.** Looking for
    `projects_error_name` in the page body, the assertion was a false pass: the key
    stays in the source, in the HTML there is the translated text. The value of the language
    actually in use must be looked for.
26. **A trailing space in a translation string is an invisible contract.**
    `filter_mapping_unmapped` ended with `": "` and the JS added `" (" + nomi + ")"`,
    giving two spaces. The space was removed from the template rather than from the JS: spacing is
    invisible in review, the separator in the code is not.
27. **Do not write PowerShell syntax inside a PHP file.** In a test I wrote
    `$i -ge`, `$lines.Count` and `[Math]::Max(...)`: in PHP `-ge` becomes the constant `ge`,
    `.Count` becomes the constant `Count`, and you get `Undefined constant` instead of a
    useful syntax error. The check failed for three lines before I noticed.
28. **Moving a comment is not enough if the comment was lying.** Before moving the orphan
    docblock I checked its three claims against the code (return shape,
    deletion of the suggestions, chain creation): all three held. If
    they had been false, the fix would only have moved a wrong description.

29. **A `?>` inside a `//` comment ends PHP mode.** `lang_selector_sink_check.php`
     had in its header an example of the sink with the closing tag: from there on PHP
     left PHP mode and **printed the rest of the file** instead of running a
     single check. The file looked like a test because it was a test. If a PHP file «never
     fails», check that it produces a verdict.
30. **The method and the parameter name must be read in the endpoint's source.**
     `sff_get_filters.php` and `get_duplicates.php` read from `INPUT_GET`/`$_GET`; sending
     a POST produces `400` on every request, and the test was measuring the rejection path under
     the label «happy path». The first two versions of `api_bootstrap_sweep.php` also
     had `$traces === ''` on a function that returns an array: impossible green.
31. **A nonexistent column dies as «Unknown column», not as an endpoint defect.**
     `get_duplicates` looks in `files.file_hash`, not `files.hash`. I had written it wrong and
     had written in the summary that «the hash is derived»: an invented fact, propagated
     into a test too. Three times in one session I guessed a column name before
     reading `information_schema`.
32. **Do not compare byte-for-byte with an already buggy predecessor.**
     `export_regression_probe.php` verified that `entries` was identical to the pre-fix builder,
     and the pre-fix had a duplicated and empty `elseif ($kind === 'bias')` branch:
     the BIAS ended up under the DARK folder of the previous group. The probe encoded the
     bug as an invariant and reported the correct output as a regression. The right invariant
     is «a frame of one type lives under that type's folder». And the probe must require that
     the old builder **violates** the invariant, otherwise one day it would stop proving
     anything while staying green.
33. **A test that asserts the absence of an escaping fails the day the escaping is added.**
     The old `lang_selector_sink_check.php` verified that the template
     «does not escape», and the template was hardened precisely with `htmlspecialchars`. The test
     gave full credit to the defect that was about to be corrected.
34. **Searching for a substring to prove a handler is not injectable is wrong.**
     `onmouseover` legitimately appears as percent-encoded data inside `data-return`.
     The right level is the **attribute name in the parsed DOM**, and the invariant is that
     `onchange` is the only handler, not that there are none.
35. **Absolute counts are fragile invariants.** `cols_test.php` asked for 68 keys and 9
     groups: they had become 76 and 10, so the test failed while being right about the code. The
     property that holds is the correspondence between the toggleable set and the groups, and it must be verified
     that the new invariant reacts to a break, otherwise it is decorative.
36. **The symptom of an uninitialized variable depends on the order.** The same empty `bias`
     branch produced a stale `$leaf` (BIAS after DARK → `SETUP_Sn/DARK/…`) or
     `$leaf` never defined (BIAS first → `SETUP_Sn//…`, with a `Warning`). A comment that
     documents only one of the two forms is half wrong.
37. **`AWI_PROJECTS_LIB` is the parent of `indexer_lib`, not the library.** Passing
     `/opt/scripts/indexer_lib` to `calib_suggest_check.py` breaks with
     `ModuleNotFoundError: No module named 'indexer_lib'`; passing `/opt/scripts` to a
     script that does `import projects` breaks it the same way. The two conventions coexisted and
     the three scripts with the old one were dead without anyone noticing, because the
     parity gate depends on the Python side and the Python side simply never started.
38. **`/opt/scripts` is mounted, but propagation is one-way.**
     `/opt/scripts/watch_fs.py`, `/opt/scripts/reindex.py` and `/opt_scripts/indexer_lib`
     are **grpcfuse** mounts, not copies in the container layer:
     ```
     grpcfuse /opt/scripts/watch_fs.py fuse.grpcfuse rw,...
     grpcfuse /opt/scripts/indexer_lib fuse.grpcfuse rw,...
     ```
     Writing into the mount **writes into the repo** (a `cat old > /opt/scripts/watch_fs.py`
     to try the pre-fix case deleted the fix I had just made, and I had to
     redo it), while modifying the file in the repo **does not** update the mount: the mount
     keeps serving the previous version until something writes into it. So:
     - to bring a fix into the container you write **from the container**, you do not use
       `docker cp` on the mount (`unlinkat: device or resource busy` on a single-file
       mount);
     - to try the pre-fix case **do not** write the old file onto the mount: copy it
       elsewhere, run from there, and restore;
     - `docker cp` to `/tmp` inside the container is safe: `/tmp` is not mounted.
     The running watcher (`python watch_fs.py /var/fits`) keeps the module
     loaded at boot in memory: after a fix it is **not** reloaded, the process must be restarted for
     the change to take effect.
From the CLI `session_start()` fails (`/tmp` is not writable): for end-to-end tests
you must log in via `login.php` the way `zip_guard_check.php`,
`addmsg_check.php`, `api_bootstrap_check.php`, `bootstrap_cost_check.php` and
`security_batch_check.php` do.

39. **A regex delimiter inside a group truncates it and the test goes green.**
     To look for `<img` after the payload I had written
     `'/…' . $NEEDLE . '\s*<(img|svg|script)/i'`: the `/` before `i` closed the
     pattern, PHP emitted a `Warning` and returned `false`, and `!$liveTag` meant
     «no leak». The test passed while broken, and it also passed **after** I
     had removed the escaping. Now the delimiter is `#` and the two checks are
     backed by the parsed DOM, which does not suffer from this error: in the attribute
     case it was the parser that found `onload` on `<button>`, because
     there the character after the payload is `<` and not `"`, i.e. what the regex was watching.
     In general a `preg_match` that returns `false` is a test that verified
     nothing: it must be treated as a failure, not as the absence of a leak.

40. **A partial that declares functions cannot be included twice in one process.**
     `projects_tree.php` defines `fmtExp()`, `linkKey()`, `diagBox()` and others at
     file level. Trying it in the three modes in a single `include` gives
     `Cannot redeclare fmtExp()`, a fatal error that has nothing to do with
     escaping. The three modes are therefore three separate processes
     (`php tree_render_escape_check.php 1`, `… 0` and `… 2`), and it is a parameter, not a
     `foreach` flag: a test that declares itself in the three modes but runs only two
     leaves branches unvisited without saying so.

41. **From the CLI, `language.php` must be preceded by `language_functions.php`.**
     `language.php` calls `getBestLanguage()` at line 3, so loading it alone
     gives `Call to undefined function`. And the language file must be included by hand
     (`$strings = include '…/languages/en.php'`) because it uses `HEADER_TITLE`, which in the
     CLI is not defined. With `config.php` before the two includes the boilerplate is:
     ```php
     $lang = DEFAULT_LANGUAGE;
     $strings = include '/var/www/html/languages/' . $lang . '.php';
     require_once '/var/www/html/includes/language_functions.php';
     require_once '/var/www/html/includes/language.php';
     ```

42. **The right line to test a chain's escaping is not always the same one.**
     `data-setup-name` is inside `if (!$hypoMode)` and `project_tree_preview.php` always
     runs in hypoMode: that branch is never rendered by the endpoint, so
     an end-to-end test requiring it would fail for the wrong reason. The
     HTTP test checks the contexts that endpoint actually reaches and **asserts**
     that the attribute branch was not visited; the attribute context is covered by
     the harness test, which exercises `hypoMode` false too. The same applies to
     `render_sff_filter()`: the `toggle` branch has no slider, so a hostile `unit`
     put there does not reach `data-unit` and the check would pass without covering anything.

43. **`&pm;` stays literal in the output.** The template writes it raw and the browser
     decodes it, so looking for the `±` character in the rendering finds nothing and the
     check goes red with the escaping intact. Look for `&pm;0` plus the value.

44. **`mysql.connector` has `autocommit=False` by default, and a long-lived test connection
     reads stale data.** The implicit transaction opened by the first SELECT holds a
     REPEATABLE READ snapshot: subsequent SELECTs see the database as it was at that
     moment, **not** the one updated by the process under test. In
     `reindex_batch_continue_check.py` the reindex was writing 4 rows, the SELECT counted 0,
     and the DELETE on the same prefix deleted 4: the same query with two different truths.
     It is not a bug: locking reads (DELETE, UPDATE, SELECT ... FOR UPDATE)
     always use the last committed version, plain ones do not. With `autocommit=True` the
     contradiction is impossible.

45. **`os.walk` returns directories in filesystem order, so which batch
     inherits the case is not an invariant.** The first version of the test asserted that the
     lost block was `d0`, the one of the poisonous file: it passed, and in the next
     run the poisonous one had ended up in `d1` and the check would have gone red **on
     correct code**. The number of failed flushes is the invariant (one out of four, always);
     *which* block disappears is information. Verified with two runs on different temporary
     directories.

46. **Two connections plus an explicit `commit` can lock a shared database
     and take the site down.** A probe left a transaction open: the `commit`
     hung for 246 seconds, the site answered 504 and then stopped responding,
     until Docker was restarted. The exact cause was not isolated and is not
     invented: what is certain is that a failed `executemany` inside a transaction, another
     connection on the same table and a `commit` after a `SELECT` compose the
     combination that made it happen. Since then the probe uses a single connection,
     `autocommit=True`, `innodb_lock_wait_timeout = 5` (the default is 50 s: contention
     must raise, not wait) and `max_statement_time = 120` — which on MariaDB
     is called that way, `max_execution_time` answers `1193 Unknown system variable`.
     Every probe that writes must also end with **a check that the site answers** and
     with **the verification that the counts went back**: a probe that leaves the database
     locked can have all its assertions green.

47. **The negative check must prove it started.** The first «pre-fix» run gave exactly
     the same output as the post-fix one — 150 rows, exit 0 —
     because I had edited the probe to print the path of `reindex.py` and **had not
     copied it into the container**: it was still running the previous version, with the path
     hardcoded. The two log lines that should have identified the binary used were
     simply absent from the output, and that is what unmasked it. The same
     applies to `docker cp` after every edit: trap #23 applies to `/tmp` too, not only
     to the grpcfuse mounts.

48. **`path` is relative to `fits_root`, so the directory *outside* the test tree
     must be passed.** Passing the subdirectory containing the files, `path` became just the
     file name (`000.fits`) and the expected prefix never matched: the reindex
     succeeded, the SELECT found nothing, and the cleanup deleted nothing. The 4 rows
     left inside had to be removed by hand, identified by `instrume = 'BATCHPROBE'` and
     not with a very broad `LIKE` that would have touched the real archive.

49. **A regex-based detector produces a false positive when looking for `onerror=` inside
     a correctly escaped attribute** — it is trap #34, which happened to me again. In `file_cells_escape_check.php` the check
     `preg_match('#duplicate-badge[^>]*on[a-z]+=#')` reported «injected» on the
     duplicates badge, when the text `onerror=alert(1)` was simply **inside** the value
     of `data-hash`, with `&lt;` in place of the angle brackets. `[^>]*` does not cross the
     closing `>` of the tag, so the regex was not looking outside the attributes:
     it was reading the content of one. The only valid check is the attribute name
     in the parsed DOM.

50. **In the DOM the attributes are already decoded, so looking there for the escaped
     shape is wrong.** `getAttribute('data-hash')` returns
     `XSS<img src=x onerror=alert(1)>…`, not `XSS&lt;img…`. The first version of the check
     asserted `str_starts_with($hash, 'XSS&lt;')` and went red with the **correct**
     code, because it was asking the parser not to have resolved the entities that by
     definition it resolves. The right invariant on the DOM is that the value **comes back identical
     to the original**: escaping without losses, the attribute boundary intact, the payload
     carried as a value and not as markup. The escaped shape is verified on the source
     (`str_contains($html, 'data-hash="XSS&lt;')`), which is the only place where it exists.

51. **The allow-list of tags is not enough when the partial deliberately writes `<img>`.**
     In the other escaping tests the list of tags the template must never produce
     could be «none»: here `file_cells.php` legitimately draws `<img>` for the
     thumbnails, `<div>`, `<span>`, `<a>`, `<td>`, and the empty deny-list reported
     the whole table as injection. Here the list is a **deny-list** (`svg`, `script`,
     `iframe`, `object`, `embed`, `form`) plus «every `on*` attribute on any element»,
     and the two legitimate `<img>` are checked separately: their `src` must be
     `/image.php?id=<integer>&type=thumb|crop` and not contain the payload.

52. **`edit` on the repo, `docker cp`, then run: in that order, always.** After a modification
     to the probe I ran it twice without copying it, and the second one showed the output
     identical to a previous case. It is trap #47 applied to itself: the copy into the
     container is a step, not a detail.

53. **Git Bash converts arguments that look like POSIX paths.** `docker exec -e
    AWI_PROJECTS_LIB=/opt/scripts ...` launched from Git Bash arrives in the container
    as `C:/Program Files/Git/opt/scripts`, and the test dies of `ModuleNotFoundError`
    even with the variable "set". From PowerShell it does not happen. `tests/run.sh`
    exports `MSYS_NO_PATHCONV=1` for this; running the commands by hand from Git Bash
    needs the same variable (or the double slash `//opt/scripts`).

54. **A check on the raw response body can be vacuous, because `json_encode`
    escapes slashes into `\/`.** «No absolute server path exposed» checked
    `str_contains($body, '/var/www/html/')` on the HTTP body: that sequence can never
    be there, not even when the diagnostic containing it is present. The check
    passed even on broken code, and I left it as it was for a while because it passed.
    The **decoded** field must be checked, which is what the browser sees. It is the
    same family as #33: a test that cannot fail is not a test.

55. **The positivity check must be done BEFORE inserting the test data.** With
    `filters` empty the SFF search has `imgtype = 'LIGHT'` as its only WHERE, so it
    returns **the whole** archive. The first version inserted the hostile rows and then
    asked for the check on a "real" LIGHT: that check also reported the rows
    just created, and the test flagged as a defect that its own payload had
    arrived in the response. The order of operations is part of the invariant.

56. **`preg_match` wants the third argument by reference.** The `<img>` check
    passed `'#'` as the matches argument:
    `Error: preg_match(): Argument #3 ($matches) could not be passed by reference`.
    The check **died** instead of verifying, and the next line never reached
    the output, so it looked like the test was simply silent.

57. **Putting the payload only in `name` is not enough to cover the contexts.** `path` is
    the column that ends up in the checkbox `value=` and in the `/fits/` `href`, and it is the only
    one of the two that goes through `rawurlencode` instead of `htmlspecialchars`. With a clean
    `path` those two checks failed **for absence of payload**, not because of a defect: a test
    that reports «there is no payload» when the payload was never put there.

### `sff_results_table.php`: a real defect, found by the escaping check

The escaping audit produced here a defect that is not about escaping.

`sff_results_table.php:58` did `substr($file['date_obs'], 0, 10)` without checking for
NULL. In PHP 8.1+ `substr(null)` is deprecated, and **`reindex.py` writes `date_obs = NULL`
when DATE-OBS is not parseable**, so the branch is reachable from the archive: a
FITS with a malformed DATE-OBS is enough.

The diagnostic was printed while the buffer wrapping the partial was open, so it
ended up in the `html` JSON field instead of breaking the response — the JSON stayed
valid, and that is why it had never been seen as a client-side error. But `sff.js:152`
assigns that field to `sffResultsPanel.innerHTML`, so the user read, in the middle of the table:

```
Deprecated: substr(): Passing null to parameter #1 ($string) of type string is
deprecated in /var/www/html/includes/sff_results_table.php on line 58
```

A line that looks broken and **disclosure of the server's absolute path with the line
number**. Measured before the fix: once inside `html`, at byte 1523 of a 338 KB
response.

On this copy the defect was **latent**: all 236 LIGHTs have `date_obs`. And
`grep` over all the `substr()` in `src/` confirms this was the only one applied to a
nullable database column; the others work on already normalized or checked values.

The fix (`68b4f41`) is one line, and reuses the convention of the three cells below, which
already print `N/A` for an absent value instead of leaving the cell empty.

`tests/php/sff_results_http_escape_check.php` carries the regression: it inserts two LIGHTs, one with
`date_obs` NULL, and verifies that no PHP diagnostic reaches the response and that the
absolute path does not appear in the decoded field. Both checks go red
on the pre-fix code.

58. **A partial that deliberately writes `<script>`, `onclick` and `<svg>` cannot be verified
    with a flat deny-list.** `table.php` contains a `<script>` block (the duplicates handler),
    `template_functions.php:55` puts `onclick="sortTable(...)"` on the
    headings that sort, and the list/thumbnail toggle buttons carry an `<svg>` icon each.
    Banning them produced three false positives on correct code.
    The invariant is not «no handler» — which here would be false — but «no handler that
    the template does not write itself»: `onclick` on `<th>` is tolerated and the rest
    is banned.
    `<svg>` needed the same treatment for the same reason, and the fix is the same shape:
    the two icons are tolerated **inside the containers the template owns**
    (`#list-view-btn`, `#thumbnail-view-btn`) and an `<svg>` anywhere else is still flagged.
    A blanket ban is replaced by a precise one, never removed — an `<svg onload>` planted
    inside a legitimate container is still caught by the `on*` rule. The seven cases of this
    refinement are checked as a negative control, so the narrowing cannot have silently
    cost a detection.
    Note that if the template ever gains another icon the check goes **red** until its id is
    added: a new icon prompts the update instead of passing silently.
    And it is checked that the payload did not end up **inside** the `<script>`, because there
    escaping would protect nothing: an injected `<script>` executes even with everything
    else escaped.

59. **In the DOM the payload *always* appears in the text, even when escaped.** The parser
    returns the values **decoded**, and `XSS&lt;img` arrives as text `XSS<img`. A
    check «the payload does not appear in the text of the nodes» is therefore only true for
    broken code: the exact opposite of a test. It is trap #50 in a different outfit, and I
    ran into it twice in the same session. The only place where comparing the
    content makes sense is inside `<script>`, because its content is raw text and the
    parser does **not** resolve entities there. Elsewhere the proof that the payload was carried
    as a value and not as markup is the raw/entity count on the source, plus the absence of
    new elements and handlers in the DOM.

60. **`thumb-title>` does not exist: there is an extra quote.** The test pattern of the
    cards block was `#thumb-title>\s*<a[^>]*>\s*…#`, and it found nothing **on correct
    code**. In the HTML there is `class="thumb-title">`: the closing quote of the
    attribute sits between the word and the angle bracket. The right pattern hooks
    `class="thumb-title">`. Verified with five incremental patterns instead of
    guessing: `#thumb-title>#` gives 0 matches, `#<a[^>]*>\s*XSS&lt;#s` gives 1 — i.e. the
    code was correct and the pattern was not.

61. **An `include` inside a function sees the local scope, not `$GLOBALS`.** In the first
    attempt `render($mode)` put `$files` only in `$GLOBALS['files']` and then included
    `table.php`: inside the function `$files` was *undefined*, the partial drew
    no rows, and the result was zero payload occurrences — which the test could
    have read as «clean». The real test passes `$files` as a parameter, and it is the
    parameter that makes it visible. If a harness «finds nothing», first check that it is
    drawing something: it is trap #3 in a new form.

62. **A PHP diagnostic must be recognized by its shape, not by a bare keyword.**
    `table_escape_check.php` renders `table.php` in a synthetic context with
    `display_errors=1`, so any diagnostic lands inside the HTML it then parses. The first
    version of the new check looked for `#(Warning|Notice|Deprecated|Fatal error|Parse
    error)#i` and went **red on correct code**: the template legitimately contains
    `astrobinMappingWarningText`, an element id, and the word "Warning" matched inside
    it. A diagnostic has a recognisable shape — `Kind: <text> in <file> on line <N>` —
    and requiring both ends of it fixes the false positive without losing a true one.
    Checked against five cases: two real diagnostics match, and three pieces of ordinary
    markup (`astrobinMappingWarningText`, prose containing "Notice:", a class named
    `NoticeBoard`) do not.

    The reason this matters more than a plain false positive: this diagnostic check
    exists precisely because the *absence* of a check was the bug. With the warning
    printed into the HTML, the DOM parser reads the text of "…on line 14" as attributes
    on the surrounding `<option>`, and the word `on` in "on line 14" was then reported as
    an **injected handler** — a security alarm caused by a missing fixture variable. The
    same family as #50: a check that cannot distinguish the two cases reports one of them
    as the other.

    The fixture variables the check depends on are the five that `init.php` sets for the
    real flow (`$page` 40, `$perPage` 41, `$totalRecords` 82, `$totalExposure` 83,
    `$totalPages` 93): `table.php` includes `pagination.php` unconditionally, so inside
    `renderTable()` all five were undefined and produced 15 diagnostics. Their values are
    irrelevant, but their absence is not.