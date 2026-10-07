# Suite di regressione del progetto

Gate di verifica promossi da `tmp/` (gitignorato): solo i test con verdetto
documentato sotto. Si eseguono tutti con `sh tests/run.sh` (serve lo stack
docker di sviluppo: `awi-php`, `awi-python`, `awi-mariadb` con dati). Il resto
di `tmp/` (sonde senza verdetto, helper, strumenti manuali, piani) resta lì.

## Come eseguirli

I test PHP girano dentro `awi-php`, che ha `src/` montato in `/var/www/html` e l'accesso
al DB. I test Python girano dentro `awi-python`.

Esistono **due modi**, e usare quello sbagliato fa fallire il test per un motivo che non
ha niente a che fare con il codice:

| Tipo | Quando | Comando |
|------|--------|---------|
| autonomo | non richiede `includes/` (per esempio `sff_filter_live_check.php`) | `docker cp tests/php/<s>.php awi-php:/tmp/` poi `docker exec awi-php php /tmp/<s>.php` |
| con albero | usa `__DIR__ . '/includes/…'` | copiare l'albero in `/tmp/harness` e rieseguire da lì |
| strumento manuale | richiede un argomento (per esempio `stale_check.py <file_id>`) | come sopra, ma con l'argomento |

Gli script con albero sono quelli che caricano `config.php` / `db_functions.php` /
`project_export.php`: `export_regression_probe.php`, `export_dup_scenario.php`,
`hash_parity.php`, `resuggest_check.php`, `api_bootstrap_all_check.php`. Con `src/`
montato, il modo più semplice è eseguirli da una copia:

```bash
docker exec awi-php sh -c 'rm -rf /tmp/harness && mkdir -p /tmp/harness && \
  cp -r /var/www/html/api /var/www/html/includes /var/www/html/assets \
        /var/www/html/languages /tmp/harness/ && cp /var/www/html/*.php /tmp/harness/'
docker cp tests/php/<s>.php tmp/_old_export_probe.php awi-php:/tmp/harness/
docker exec awi-php sh -c 'cd /tmp/harness && php <s>.php'
```

I test JS girano in locale con Node.

| Test | Verifica | Fixture |
|------|----------|---------|
| `cols_alignment_check.php` | §4 — `<th>`/`<td>` allineati nelle integration group | nessuna scrittura |
| `frozen_check.php` | §6 — la modalità frozen non scrive | crea e rimuove 2 progetti di prova |
| `zip_guard_check.php` | §1 — l'export ZIP rispetta `can_download` | crea e rimuove 1 utente di prova |
| `numbering_check.php` | §9, §8 — numerazione senza collisioni, `session_no` per notte | crea e rimuove 1 progetto di prova |
| `export_basename_check.php` | §11 — basename estraibili su Windows, collisioni case-insensitive | nessuna scrittura |
| `addmsg_check.php` | §12 — il messaggio d'errore è fuori dagli step nascosti | crea e rimuove 1 utente di prova |
| `legacy_normalize_check.php` | §13.6, §13.7 — normalizzazione imgtype e label_object | pianta e ripristina 1 panel |
| `project_visibility_check.php` | §13.8 — il progetto resta visibile, la coda è filtrata | crea e rimuove 1 progetto + 2 suggestion |
| `thumb_render_check.php` | §13.16 — le miniature restano rilevate con `OCTET_LENGTH` | nessuna scrittura |
| `blob_weight_check.php` | §13.16 — il risparmio sui blob è reale | nessuna scrittura |
| `api_bootstrap_check.php` | §13.41, §13.2 — i 5 endpoint col bootstrap slim, niente manifest, chiavi del contratto | crea e rimuove 1 utente |
| `bootstrap_cost_check.php` | §13.41 — costo del blocco dati di init.php | crea e rimuove 1 utente |
| `security_batch_check.php` | §13.36, §13.37, §13.38, §13.39 — CSRF sul form ZIP, confine di percorso, codifica JSON, permessi AstroBin | crea 2 utenti, albero di prova in `/tmp` |
| `api_contract_check.php` | §13.33, §13.34, §13.35 — 400 con chiave `error`, 401 JSON, 404 su progetto inesistente, HTML senza warning | crea e rimuove 1 utente |
| `threshold_key_check.php` | §13.1 — una riga per gruppo, il vincolo respinge i duplicati con NULL | transazione annullata |
| `write_atomicity_check.php` | §13.3, §13.4, §13.32 — alias senza applicazione parziale, un pending per progetto, `catch (Throwable)` | transazioni annullate, crea e rimuove 1 utente |
| `export_manifest_check.php` | §13.24, §13.25, §13.26, §13.40 — rotazione non numerica, tolleranze, manifest filtrato sul disco | ripristina `projects.tolerances` |
| `suggest_parity_check.py` | §13.25, §13.26, §13.27 — `_num_prefix`, RA/Dec con maiuscoli, `get_globals` che propaga | nessuna scrittura |
| `export_ids_check.php` | §13.10 — 4000 id via body (il GET dà 414), permessi su `get_unmapped_filters.php` | crea e rimuove 2 utenti |
| `watcher_queue_check.py` | §13.28, §13.29 — riscan chiamato, una connessione e un reset | connessione finta in `sys.modules` |
| `ux_feedback_check.php` | §13.9, §13.11, §13.13, §13.18 — chart con colonna nascosta, troncamento dichiarato, errori senza query, doppio spazio | crea e rimuove 1 utente |
| `igroup_total_check.php` | §13.14, §13.15 — i gruppi sono una partizione, anche in modalità tile | nessuna scrittura |
| `duplicates_handler_check.php` | §13.44, §13.45 — un solo blocco script, `escapeHTML` vero | crea e rimuove 1 utente |
| `docblock_owner_check.php` | §13.21 — ogni docblock documenta la funzione che segue, e le sue affermazioni sono vere | nessuna scrittura |
| `export_smoke_check.php` | §11 — `buildProjectExportMap` su un progetto reale, i basename sanificati non rompono la mappa | nessuna scrittura |
| `resuggest_check.php` | §13 — `resuggestDismissed()` e `getDismissedCount()` sul percorso reale, si toccano solo i dismissed | transazione annullata |
| `stale_check.py` | §13 — coerenza dei payload di suggerimento tra PHP e Python | nessuna scrittura |
| `calib_suggest_check.py` | §7 — DARK/BIAS linkati a setup, senza sessioni orfane | transazioni annullate |
| `session_numbering_check.py` | §8 — `session_no` segue la notte lato Python | transazione annullata |
| `watch_backoff_check.py` | §10 — il watcher non riprova ogni secondo | nessuna scrittura (stub di subprocess) |
| `imgtype_parity_check.py` | §13.6 — la SQL della migration coincide con Python | nessuna scrittura |
| `check_chart_ids.mjs` | §3 — id dei canvas per chiave metrica | nessuna |
| `check_escattr.mjs` | §5 — `escAttr` chiude l'attributo `title` | nessuna |
| `api_bootstrap_all_check.php` | i 4 endpoint non-projects migrati ad `api_bootstrap.php` non caricano più `init.php` | crea e rimuove 1 utente |
| `api_bootstrap_sweep.php` | gli stessi 4 endpoint su percorsi **felici e di errore**; nessuna diagnostica PHP nel corpo | nessuna scrittura |
| `calib_filter_id_check.php` | il nome di colonna dei filtri arriva dal body JSON ed è validato | nessuna scrittura |
| `sff_filter_live_check.php` | la ricerca calibrazioni con filtri risponde davvero JSON valido, e la whitelist respinge | nessuna scrittura |
| `sff_filter_http_escape_check.php` | il pannello filtri che `sff.js:53` mette in `innerHTML` non esegue un header FITS, **su HTTP reale** | inserisce e cancella 1 riga `files` e 1 utente di prova; conteggi verificati |
| `sff_results_http_escape_check.php` | la tabella risultati che `sff.js:152` mette in `innerHTML` non esegue `name`/`path` dell'archivio, e nessuna diagnostica PHP raggiunge il pannello | inserisce e cancella 2 righe `files` e 1 utente; conteggi verificati |
| `sff_payload_check.php` | `find_calibration_files.php` non porta più i blob nella risposta, e le miniature passano da `image.php` | nessuna scrittura |
| `cols_test.php` | registro colonne: insieme toggleable e gruppi si descrivono a vicenda, `name` non nascondibile | nessuna scrittura |
| `lang_selector_sink_check.php` | renderizza il vero `language_selector.php` con `$_GET` ostile e verifica il DOM parsato | nessuna scrittura |
| `reindex_batch_continue_check.py` | un record che il DB rifiuta non ferma il resto della passata: 4 blocchi da 50, 150 file committati | scrive righe sintetiche in `files` con prefisso univoco, poi le rimuove; conteggi verificati tornati allo stato iniziale |
| `file_cells_escape_check.php` | `file_cells.php` escapa testo, attributi e href in entrambi gli ambiti (`main` e `project`): 62 occorrenze del payload, nessuna grezza | nessuna scrittura (riga sintetica) |
| `table_escape_check.php` | `table.php` escapa in **entrambe le viste**, con due sink di nome distinti verificati per posizione | nessuna scrittura (riga sintetica) |
| `tree_render_escape_check.php` | `projects_tree.php` escapa testo, attributo e tooltip in **entrambe** le modalità (`hypoMode` 1 e 0) | nessuna scrittura (albero sintetico) |
| `sff_filter_escape_check.php` | `sff_filter_template.php` escapa il valore di riferimento in testo, `value=` e `data-unit=` | nessuna scrittura |
| `tree_preview_escape_check.php` | la catena HTTP reale `project_tree_preview.php` → JSON `html` → `main.js:646` | crea e rimuove 1 utente; nessuna scrittura (la preview annulla la transazione) |
| `export_regression_probe.php` | un frame di un tipo vive sotto la cartella di quel tipo; nessun `fid` in due cartelle senza essere duplicato dichiarato | nessuna scrittura |
| `export_dup_scenario.php` | una calibrazione linkata a due setup: il builder nuovo emette entrambe le copie e le dichiara | transazione annullata |
| `hash_parity.php` + `hash_parity.py` + `hash_parity_diff.py` | **gate di parità PHP<->Python**: `config_hash` e `match_inputs` devono coincidere, o ogni dismiss diventa stale a ogni passata | nessuna scrittura |
| `stale_check.py`, `stale_resurrect.py` | strumenti manuali di diagnostica, richiedono `<file_id>` | transazione annullata |

### I sink `innerHTML` del JS residuo: verificati e refutati

Il cliente ha quattro `innerHTML = <HTML costruito dal server>`. Sono stati auditati
tutti, e nessuno è un difetto. Vale la pena scriverlo, perché tre dei quattro
*sembravano* sospetti e due sono refutazioni che richiedono una misura per essere
credibili.

| Sink | Sorgente | Esito |
|---|---|---|
| `main.js:646` | `api/project_tree_preview.php` → `includes/projects_tree.php` | escaping completo, coperto da `tree_render_escape_check.php` e `tree_preview_escape_check.php` |
| `sff.js:152` | `api/find_calibration_files.php` → `sff_results_table.php` | già verificato in una sessione precedente |
| `sff.js:53` | `api/sff_get_filters.php` → `sff_filter_template.php` | escaping completo, coperto a harness da `sff_filter_escape_check.php` e **su HTTP reale** da `sff_filter_http_escape_check.php` |
| `main.js:490` | `api/project_preview.php`, HTML costruito **lato client** con `escHtml`/`escAttr` | sicuro per costruzione |

Le tre interpolazioni di `${error.message}` senza escaping erano l'altro sospetto.
Nessuna è controllabile dal server, e due si chiudono senza nemmeno guardare il PHP:

- **`sff.js:57`** — l'errore nasce da `throw new Error('Network response was not ok')`,
  una stringa letterale del client. Nessun testo del server raggiunge quel ramo.
- **`main.js:288`** — `get_duplicates.php` non mette mai `$_GET['hash]` nella risposta:
  in successo restituisce l'array dei duplicati (che non ha una chiave `error`), e in
  errore solo costanti e `__('error_fetching_duplicates')`.
- **`sff.js:164`** — qui il sospetto era reale: `find_calibration_files.php:223` fa
  `json_encode(['error' => 'Database query failed: ' . $e->getMessage()])`, quindi il
  messaggio del driver entrava nell'HTML. **Refutato misurando**: un
  `PDOException` su questa configurazione (`ATTR_EMULATE_PREPARES => false`) riporta
  solo la diagnosi del driver e la traccia della query, **mai i valori dei parametri
  legati**. Provato con tre forme, fra cui un payload legato come parametro a una
  query che fallisce: nessuna l'ha fatto riapparire. E il testo della query è
  costruito solo da id di filtro e valori di `search_type` già passati per whitelist.

`main.js:350` (`return d.innerHTML` dentro `escHtml`) non è un difetto: è l'idioma
standard di escaping via DOM, e `main.js:352` documenta già il suo limite — escapa
`& < >` e non le virgolette — con `escAttr()` accanto per gli attributi. Il
distinguo è già coperto da `check_escattr.mjs`.

### Cosa in `tests/` non è un test
`tests/` contiene anche altro, e non tutto deve girare nella suite:

- **helper**: `_old_export_probe.php` è il builder pre-fix congelato (funzioni con prefisso
  `OLD_` per non collidere), serve da baseline di confronto.
- **sonde diagnostiche** (`imgtype_probe.php`, `schema_probe.php`, `phinxlog_probe.php`,
  `snr_diag.py`, `frame_smoke.py`): stampano tabelle, non hanno verdetto.
- **usa-e-getta**: `dbg.php`, `dupdebug.php`, `cksec.php`, `srrender2.php`,
  `refactorcheck.php` — `refactorcheck.php` e `dupdebug.php` si dichiarano da soli
  «Throwaway, deleted after run» e sono ancora lì: candidati alla cancellazione.
- **piani**: i `*-plan.md` e il `scan_php.ps1` che li ha generati.
- **esperimenti con soglia**: `sep_dense.py`, `sep_smoke.py` arrivano a un `AssertionError`
  su una soglia di taratura: sono probe di misura, non gate. `snr_diag.py` e `frame_smoke.py`
  hanno bisogno di file FITS su disco.

Attenzione: `frame_smoke.py`, `sep_grid.py`, `sep_hot.py` e `sep_poison.py` erano **morti**
in silenzio. Importavano da `/pkg` e `/mod`, cioè dalla disposizione della libreria prima
che `projects.py` e `star_analysis.py` arrivassero sotto `indexer_lib`. Erano già in
`tests/` e non risultavano da nessuna parte: nessuna asserzione, nessun errore visibile, solo
l'assenza di output.

### Gate di parità PHP<->Python

`hash_parity.php` e `hash_parity.py` emettono la stessa forma JSON su stdout; il confronto
è il gate. Va eseguito end to end, perché è proprio la cattura manuale delle due metà che
aveva lasciato il gate marcio:

```bash
docker exec awi-php sh -c 'cd /tmp/harness && php hash_parity.php' > /tmp/php.json
docker cp /tmp/php.json awi-python:/tmp/php.json
docker exec awi-python sh -c 'cd /tmp && python hash_parity.py' > /tmp/py.json
docker cp /tmp/py.json awi-python:/tmp/py.json
docker exec awi-python sh -c 'cd /tmp && python hash_parity_diff.py php.json py.json'
```

Tollera di proposito cinque chiavi che Python emette e PHP no
(`globals/tol_*`, tranne quelle già in lista): entrambi gli hash iterano la **stessa**
lista fissa di chiavi — `PROJECT_SUGGEST_TOL_KEYS` in `projects_functions.php` e
`SUGGEST_TOL_KEYS` in `indexer_lib/projects.py` — quindi un `globals` più ricco non
entra nell'hash. Qualsiasi altra differenza è una rottura di parità reale.

`AWI_PROJECTS_LIB` è la directory **genitore** che contiene `indexer_lib`, cioè
`/opt/scripts`, non `/opt/scripts/indexer_lib`: `calib_suggest_check.py` fa
`from indexer_lib.projects import …`. I test che facevano `import projects` con
`sys.path` su `/opt/scripts` sono morti con `ModuleNotFoundError`.

I test Python girano dentro `awi-python`:

```bash
docker cp tests/<script>.py awi-python:/tmp/
docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python sh -c 'cd /tmp && python <script>.py'
```

> **Perché `/tmp` e non la directory montata.** `src/` e `docker/python/indexer_lib`
> sono bind mount dentro i container: scrivere `/var/www/html/...` o
> `/opt/scripts/indexer_lib/...` da `docker exec` **sovrascrive anche il file locale**.
> È successo tre volte e in ogni caso il fix era andato perso. I test vanno quindi
> copiati in `/tmp` dentro il container.
>
> Se serve una regressione su un sorgente, **salvalo fuori dal container prima** di
> sovrascriverlo, e riapplicarlo in locale dopo.

## Regressione

`cols_alignment_check.php`, `frozen_check.php` e `calib_suggest_check.py` verificano
anche il comportamento *prima* del fix.

```bash
# §4: rimuovere il gate star in file_cells.php
docker exec awi-php sh -c 'cd /var/www/html && cp includes/file_cells.php /tmp/fc.bak && \
  sed -i "/if (\$groupKey === .star. && empty(\$pStar)) {/,+2d" includes/file_cells.php'
docker exec awi-php php /var/www/html/cols_alignment_check.php   # atteso: star OFF DISALLINEATO
docker exec awi-php sh -c 'cp /tmp/fc.bak /var/www/html/includes/file_cells.php'

# §6: rimuovere il guard frozen in projects_functions.php
docker exec awi-php sh -c 'cd /var/www/html && cp includes/projects_functions.php /tmp/pf.bak && \
  sed -i "/Frozen means locked. Checked here as well/,/^    }$/d" includes/projects_functions.php'
docker exec awi-php php /var/www/html/frozen_check.php          # atteso: caso B "MUTATO <<< BUG"
docker exec awi-php sh -c 'cp /tmp/pf.bak /var/www/html/includes/projects_functions.php'

# §7 e §8 (Python): copia della libreria con la versione PRE-fix, poi test contro la copia
docker exec awi-python sh -c 'rm -rf /tmp/oldlib && mkdir -p /tmp/oldlib && \
  cp -r /opt/scripts/indexer_lib /tmp/oldlib/ && rm -rf /tmp/oldlib/indexer_lib/__pycache__'
git show HEAD~1:docker/python/indexer_lib/projects.py | \
  docker exec -i awi-python sh -c 'cat > /tmp/oldlib/indexer_lib/projects.py'
docker exec -e AWI_PROJECTS_LIB=/tmp/oldlib    awi-python sh -c 'cd /tmp && python calib_suggest_check.py'
docker exec -e AWI_PROJECTS_LIB=/opt/scripts  awi-python sh -c 'cd /tmp && python calib_suggest_check.py'
docker exec -e AWI_PROJECTS_LIB=/tmp/oldlib    awi-python sh -c 'cd /tmp && python session_numbering_check.py'
docker exec -e AWI_PROJECTS_LIB=/opt/scripts  awi-python sh -c 'cd /tmp && python session_numbering_check.py'
```

`numbering_check.php` ha bisogno delle migration applicate (gli indici unici): senza
`uq_project_setups_no` / `uq_project_panels_no` il test di concorrenza non dimostra
nulla, e lo segnala a inizio output.

### I tre test di escaping vanno provati rossi, non solo verdi

Un test di escaping che non è mai stato visto fallire non dimostra niente. I tre
(`tree_render_escape_check.php`, `sff_filter_escape_check.php`,
`tree_preview_escape_check.php`) sono stati tolti di mezzo uno alla volta per vedere
quali verifiche si accendono, poi ripristinati. Il metodo usato è l'edit del file nel
repo seguito da `git checkout --`: **`sed` non va bene per queste righe da
PowerShell**, perché il pattern contiene `<?=` e il `<` viene letto come operatore
(trappola #6, già capita). Il bind mount fa il resto: modificare il file nel repo è
visibile al container, quindi basta attendere l'opcache.

```bash
# 1. togliere l'escaping di UN contesto, eseguire i test, ripristinare
#    (l'edit si fa nel repo, non dentro il container)
# 2. attendere l'opcache: opcache.revalidate_freq=2, trappola #5
sleep 3
docker exec awi-php sh -c 'cd /tmp && php tree_render_escape_check.php 1; \
  php tree_render_escape_check.php 0; \
  php sff_filter_escape_check.php; \
  php tree_preview_escape_check.php'
# 3. ripristinare e ricontrollare che siano tornati verdi
git checkout -- ../src/includes/projects_tree.php ../src/includes/sff_filter_template.php
```

I tre casi provati, con il verdetto atteso, così la verifica è ripetibile:

| Rottura introdotta | Test da eseguire | Atteso |
|---|---|---|
| `projects_tree.php:250` — `data-setup-name` senza `htmlspecialchars` | `tree_render_escape_check.php 1` | **verde**: l'attributo è nel ramo `!$hypoMode`, la preview non lo raggiunge |
| idem, stesso file | `tree_render_escape_check.php 0` | **rossa**: `1 grezze`, `attribute onload su <button>`, `il suo valore resta chiuso` |
| `projects_tree.php:252` — `renderSetupFingerprint` senza `htmlspecialchars` | `tree_preview_escape_check.php` | **rossa**: occorrenze 1/2 escaped, `<img>` e `<svg onload>` iniettati, HTML 22 byte più corto |
| `sff_filter_template.php:38` — `value=` senza `htmlspecialchars` | `sff_filter_escape_check.php` | **rossa**: 4 verifiche, fra cui `il valore e' escaped in value= (riga 38)` |
| `sff_filter_template.php:37` — testo senza `htmlspecialchars` | `sff_filter_escape_check.php` | **rossa**: `element <img>`, `attribute onerror su <img>` |

I tre test hanno in comune tre cose che li rendono credibili, e vanno mantenute se
uno viene modificato:

- **controllo di positività prima del verdetto.** Il payload deve essere *arrivato*
  nell'HTML (`substr_count` > 0) e, meglio, ogni sua occorrenza deve essere seguita da
  `&lt;`. Senza questo, «nessuna iniezione» è vero anche quando la pagina era vuota.
- **secondo parere dal DOM parsato.** `DOMDocument` + `DOMXPath` cercano un elemento
  o un attributo `on*` che il template non ha scritto. È l'unico dei due controlli
  che becca la rottura dell'attributo, perché lì il carattere dopo il payload è `<`
  e non `"` (trappola #39).
- **verifica posizionale del contesto.** Non basta «il payload è escaped da qualche
  parte»: si controlla la posizione (`text-gray-300">PAYLOAD&lt;`,
  `value="PAYLOAD&lt;`, `data-setup-name="PAYLOAD&lt;`). Una verifica globale
  passerebbe anche se la metà scoperta non fosse protetta.

### `reindex_batch_continue_check.py`: il caso pre-fix

Questo gira contro la tabella `files` vera, perché `awi_user` non può creare uno schema
isolato. Per provare il codice **pre-fix** non si scrive sul mount `/opt/scripts`
(trappola #38): si copia l'albero altrove e si punta `REINDEX_PY` e `AWI_PROJECTS_LIB`
lì. I due parametri sono stampati all'inizio, e la loro assenza nell'output è il
segnale che stai girando la copia vecchia della sonda (trappola #47).

```bash
# albero pre-fix, fuori dal mount
docker exec awi-python sh -c 'rm -rf /tmp/prefix_lib && mkdir -p /tmp/prefix_lib && \
  cp -r /opt/scripts/indexer_lib /tmp/prefix_lib/ && rm -rf /tmp/prefix_lib/indexer_lib/__pycache__'
git show "d4ac075^:docker/python/reindex.py" | \
  docker exec -i awi-python sh -c 'cat > /tmp/prefix_lib/reindex.py'
docker exec awi-python sh -c 'printf "pre-fix : "; grep -c batch_params.clear /tmp/prefix_lib/reindex.py'
docker exec awi-python sh -c 'printf "post-fix: "; grep -c batch_params.clear /opt/scripts/reindex.py'
# 1 contro 2: il pre-fix svuota batch_params solo dopo un flush riuscito

docker cp tests/reindex_batch_continue_check.py awi-python:/tmp/
# post-fix: verde
docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python \
  sh -c 'cd /tmp && python reindex_batch_continue_check.py'
# pre-fix: rosso
docker exec -e AWI_PROJECTS_LIB=/tmp/prefix_lib -e REINDEX_PY=/tmp/prefix_lib/reindex.py \
  awi-python sh -c 'cd /tmp && python reindex_batch_continue_check.py'
```

Cosa distingue i due, misurato:

| | post-fix | pre-fix |
|---|---|---|
| exit code | 0 | **1** |
| righe committate | **150** su 199 | 100 su 199 |
| blocchi persi | 1 su 4 | **2 su 4** |
| righe di errore nel log | 1 | una per file, da quel momento in poi |

Il test asserisce **sia** il conteggio sia il codice di uscita, e il codice di uscita non
è decorativo: il flush di coda sta fuori dal `try`, quindi col velenoso nell'ultimo
blocco il pre-fix committerebbe comunque 150 file e fallirebbe solo sul `sys.exit(1)`.
Con il conteggio da solo il test sarebbe ambiguo in quell'ordine.

Da notare che il caso qui descritto non è il più grave possibile: il codice **attuale**
non è nemmeno in grado di accorgersi del caso «velenoso nell'ultimo blocco» se non per
il fatto che il flush di coda propaga. È una scelta deliberata (vedi il messaggio di
`d4ac075`: il lavoro già committato è al sicuro e il fallimento è rumoroso), quindi il
test non la mette sotto esame, ma il limite è scritto qui perché il test non lo copre.

### I partial PHP renderizzati dal server

L'audit degli `innerHTML` del client (sopra) copre solo l'HTML che arriva via
`fetch`. I partial che il server stampa direttamente sono una superficie separata, e
quella piu' grande era intatta. `file_cells.php` disegna **tutte** le righe
dell'archivio: su questa copia 365 file, ciascuno con nome, object, filter, instrume,
cameraid, telescop e una quarantina di altri header FITS. Chi deposita un file lo
controlla.

`file_cells_escape_check.php` guida il partial vero con una riga sintetica in cui ogni
colonna stringa dell'archivio contiene il payload, in entrambi gli ambiti:

- `main` — `table.php:103`, la tabella principale della home
- `project` — `igroup_files_table.php:29`, le tabelle integration group dei progetti

Esito: **62 occorrenze del payload, tutte escaped o percent-encoded, zero grezze.** I
tre contesti sono distinti e vengono controllati separatamente:

| Contesto | Come lo scrive | Cosa produce col payload |
|---|---|---|
| testo | `htmlspecialchars()` su ogni colonna stringa | `XSS&lt;img …` |
| attributo | `htmlspecialchars()` in `fileCellAttrs()` (riga 71), sul `data-val` grezzo | `XSS&lt;img …` |
| href | `rawurlencode()` sul path (riga 228) | `DIR%2FXSS%3Cimg%20…` |

Il test conta **tre forme** del payload — grezza, entità HTML, percent-encoded — e
pretende che la grezza sia 0 e che le altre due sommino al totale. Se il path
arrivasse nell'href senza codifica, la forma grezza salirebbe e il test lo direbbe.

Punti che la lettura non bastava a chiudere, e che il test blocca:

- **`$nameSuffix` (riga 230) è l'unico punto che stampa senza `htmlspecialchars`.** Il
  solo chiamante, `igroup_files_table.php:21-26`, ci passa markup fisso attorno a una
  traduzione, quindi non è controllato dall'archivio. Il test verifica quell'invariante
  («uno span inserito a mano, e il payload non ci passa dentro») invece di darlo per
  scontato: è il tipo di argomento che regge finché qualcuno non aggiunge un chiamante.
- **`getMoonPhaseMarkup(?float, ?float)`** ha il tipo dichiarato, quindi una stringa non
  numerica diventa `TypeError` invece di finire nell'HTML: sicuro, ma per un motivo che
  non è l'escaping.
- **`resolution`, `fov_w`, `fov_h` e `file_size`** sono formattati **senza cast**
  (`number_format($f['fov_w'], 1)`, `$f['file_size'] / 1048576`). Qui il test mette dei
  numeri, non il payload, e il motivo è scritto nel file: non sono controllati
  dall'archivio (`resolution = (xpixsz * xb / focallen) * 206.265`, `file_size =
  os.stat().st_size`), e con una stringa il test morirebbe di `TypeError` senza dire
  nulla sull'escaping. È una fragilità, non un difetto dimostrato, e quindi non è una
  correzione: se un giorno quei campi arrivassero da una fonte non numerica, il sintomo
  sarebbe un 500 e non un'escape.

Il caso pre-fix si prova togliendo una singola `htmlspecialchars` e aspettando 3 secondi
per l'opcache:

| Rottura introdotta | Atteso |
|---|---|
| riga 229, `name` senza `htmlspecialchars` | **rossa**: `grezze=1`, `<img onerror>` e `<svg onload>` iniettati, in entrambi gli ambiti |
| riga 71, `data-val` senza `htmlspecialchars` | **rossa in `project` solo**: `grezze=20`, `attributo onload su <td>`; in `main` resta verde, perché quel ramo non porta `data-val` |

La seconda riga è la più informativa: un controllo di `innerHTML` o un campo di solo
`main` non l'avrebbe vista, perché il ramo incriminato esiste solo in `project`.

### Come il pannello filtri è stato chiuso su HTTP reale

Il buco che `sff_filter_http_escape_check.php` colma era dichiarato in questa sezione
sulla sicurezza: il valore di riferimento di `render_sff_filter()` viene da
`files.<colonna>`, cioè dall'header FITS, e **non esiste un modo per renderlo
controllabile dal client senza scrivere una riga in `files`**. Finché le scritture sul DB
erano vietate la verifica poteva esistere solo a harness.

Ora il percorso vero è provato end to end:

```
GET /api/sff_get_filters.php?id=<id>&type=lights        (sessione reale, CSRF vero)
  -> SELECT * FROM files WHERE id = :id AND imgtype = 'LIGHT'
  -> render_sff_filter($config, $referenceFile[$key])   per ogni chiave attiva
  -> echo, con Content-Type: text/html
  -> sff.js:53   sffFiltersPanel.innerHTML = html
```

Con `type=lights` le colonne attive sono `object`, `filter`, `instrume`, `cameraid`,
`exptime`, `ccd_temp`, `xbinning`, `ybinning`, `ra`, `dec`, `objctrot`, `fov_w`, `fov_h`,
`moon_phase`, `width`, `height`, `date_obs`. Le stringhe sono le prime quattro.

Esito: **8 occorrenze del payload, tutte escaped, zero grezze**, e i due contesti
mostrati a byte (`text-gray-300">XSS&lt;img …` e `value="XSS&lt;img …`).

Il file di prova **non viene messo su disco**: solo una riga in `files`, cancellata per
`id` esatto. Nessun reindex, nessun file in `/var/fits`, nessun'altra riga toccata. Il
`finally` cancella riga e utente anche quando il test muove a metà, ed è verificato:
durante lo sviluppo una versione è morta con `1406 Data too long` e il conteggio è
tornato a 365 lo stesso.

Tre cose che il percorso HTTP ha reso necessarie e che un harness non avrebbe chiesto:

- **`filter` è `varchar(50)` con `STRICT_TRANS_TABLES`.** La copia corta del payload
  doveva stare in 50 caratteri, altrimenti l'`INSERT` moriva con `1406` e il test
  misurava il vincolo del database invece dell'escaping. Per questo ha un marcatore
  proprio (`Z9`): senza, non si distingueva dalle altre tre copie, che i conteggi
  riportano solo per `XSS`.
- **Il controllo di positività va sull'endpoint, non sul template.** Prima della richiesta
  ostile il test chiede lo stesso pannello per il primo LIGHT vero dell'archivio e pretende
  200 con HTML non vuoto. «Il payload non è iniettato» è vero anche quando l'endpoint non
  ha restituito niente, e su HTTP quella distinzione costa una richiesta.
- **`imgtype` deve essere esattamente `'LIGHT'`**, altrimenti la `WHERE` non trova la riga
  e il test misurerebbe un 404 travestito da prova di escaping.

Il caso pre-fix, togliendo una sola `htmlspecialchars`:

| Rottura introdotta | Atteso |
|---|---|
| riga 38, `value=` senza `htmlspecialchars` | **rossa**: `grezze=3` su `XSS` e `grezze=1` su `Z9`, `attributo onload su <input>`, `il value= nascosto e' escaped` |

53. **Un controllo sul corpo grezso della risposta può essere vacuo, perché `json_encode`
    escapa le barre in `\/`.** «Nessun percorso assoluto del server esposto» verificava
    `str_contains($body, '/var/www/html/')` sul corpo HTTP: quella sequenza non ci può
    mai essere, nemmeno quando la diagnostica che la contiene è presente. Il check
    passava anche su codice rotto, e l'ho lasciato com'era per un po' perché passava.
    Va controllato il campo **decodificato**, che è quello che il browser vede. È la
    stessa famiglia della #33: un test che non può fallire non è un test.

54. **Il controllo di positività va fatto PRIMA di inserire i dati della prova.** Con
    `filters` vuoto la ricerca SFF ha come unico WHERE `imgtype = 'LIGHT'`, quindi
    restituisce **tutto** l'archivio. La prima versione inseriva le righe ostili e poi
    chiedeva il controllo su un LIGHT "vero": quel controllo riportava anche le righe
    appena create, e il test segnalava come difetto che il proprio payload fosse
    arrivato nella risposta. L'ordine delle operazioni è parte dell'invariante.

55. **`preg_match` vuole il terzo argomento per riferimento.** La verifica delle `<img>`
    passava `'#'` come argomento delle corrispondenze:
    `Error: preg_match(): Argument #3 ($matches) could not be passed by reference`.
    Il check **moriva** invece di verificare, e la riga dopo non è mai arrivata
    all'output, quindi sembrava che il test fosse semplicemente silenzioso.

56. **Mettere il payload solo in `name` non basta per coprire i contesti.** `path` è la
    colonna che finisce nel `value=` della checkbox e nell'`href /fits/`, ed è l'unica
    delle due che passa da `rawurlencode` invece che da `htmlspecialchars`. Con il `path`
    pulito quei due check fallivano **per assenza di payload**, non per difetto: un test
    che segnala «non c'è il payload» quando il payload non è mai stato messo lì.

### `sff_results_table.php`: un difetto vero, trovato dalla verifica dell'escaping

L'audit dell'escaping ha prodotto qui un difetto che non è di escaping.

`sff_results_table.php:58` faceva `substr($file['date_obs'], 0, 10)` senza controllare il
NULL. In PHP 8.1+ `substr(null)` è deprecato, e **`reindex.py` scrive `date_obs = NULL`
quando il DATE-OBS non è parsabile**, quindi il ramo è raggiungibile dall'archivio: basta
un FITS con un DATE-OBS malformato.

Ladiagnostica veniva stampata mentre era aperto il buffer che racchiude il partial, quindi
finiva nel campo JSON `html` invece di rompere la risposta — il JSON restava valido, ed è
per questo che non si era mai visto come errore lato client. Ma `sff.js:152` assegna quel
campo a `sffResultsPanel.innerHTML`, quindi l'utente leggeva, in mezzo alla tabella:

```
Deprecated: substr(): Passing null to parameter #1 ($string) of type string is
deprecated in /var/www/html/includes/sff_results_table.php on line 58
```

Riga dall'aspetto rotta e **divulgazione del percorso assoluto del server con il numero di
riga**. Misurato prima del fix: una volta dentro `html`, al byte 1523 di una risposta da
338 KB.

Su questa copia il difetto era **latente**: tutti i 236 LIGHT hanno `date_obs`. E
`grep` su tutti i `substr()` di `src/` conferma che questo era l'unico applicato a una
colonna nullable del database; gli altri lavorano su valori già normalizzati o controllati.

Il fix (`68b4f41`) è una riga, e riusa la convenzione delle tre celle sotto, che stampano
già `N/A` per un valore assente invece di lasciare la cella vuota.

`tmp/sff_results_http_escape_check.php` porta la regressione: inserisce un LIGHT con
`date_obs` NULL e verifica che nessuna diagnostica PHP raggiunga la risposta e che il
percorso assoluto non compaia nel campo decodificato. Entrambe le verifiche vanno rosse
sul codice pre-fix.

57. **Un partial che scrive `<script>` e `onclick` di proposito non si verifica con una
    lista di divieto secca.** `table.php` contiene un blocco `<script>` (il gestore dei
    duplicati) e `template_functions.php:55` mette `onclick="sortTable(...)"` sulle
    intestazioni che ordinano. Vietarli produceva due falsi positivi su codice corretto.
    L'invariante non è «nessun handler» — che qui sarebbe falso — ma «nessun handler che
    il template non scrive di suo»: si tollera `onclick` su `<th>` e si vieta il resto.
    E si controlla che il payload non sia finito **dentro** lo `<script>`, perché lì
    l'escaping non proteggerebbe nulla: uno `<script>` iniettato esegue anche con tutto il
    resto escapato.

58. **Sul DOM il payload compare *sempre* nel testo, anche quando è escaped.** Il parser
    restituisce i valori **decodificati**, e `XSS&lt;img` arriva come testo `XSS<img`. Un
    check «il payload non compare nel testo dei nodi» è quindi vero solo per codice
    rotto: l'esatto opposto di un test. È la trappola #50 in un vestito diverso, e me la
    sono ritrovata due volte nella stessa sessione. L'unico posto dove il confronto del
    contenuto ha senso è dentro `<script>`, perché il suo contenuto è testo grezzo e il
    parser **non** vi risolve le entità. Altrove la prova che il payload sia stato portato
    come valore e non come markup è la conta grezza/entità sul sorgente, più l'assenza di
    elementi e handler nuovi nel DOM.

59. **`thumb-title>` non esiste: c'è una virgoletta di troppo.** Il pattern di prova del
    blocco schede era `#thumb-title>\s*<a[^>]*>\s*…#`, e non trovava nulla **su codice
    corretto**. Nell'HTML c'è `class="thumb-title">`: la virgoletta di chiusura
    dell'attributo sta fra la parola e l'angolo. Il pattern giusto aggancia
    `class="thumb-title">`. Verificato con cinque pattern incrementali invece di
    indovinare: `#thumb-title>#` dà 0 matches, `#<a[^>]*>\s*XSS&lt;#s` ne dà 1 — cioè il
    codice era corretto e il pattern no.

60. **Un `include` dentro una funzione vede l'ambito locale, non `$GLOBALS`.** Nel primo
    tentativo `render($mode)` metteva `$files` solo in `$GLOBALS['files']` e poi includeva
    `table.php`: dentro la funzione `$files` era *undefinita*, il partial non disegnava
    nessuna riga, e il risultato era zero occorrenze del payload — che il test avrebbe
    potuto leggere come «pulito». Il test vero passa `$files` come parametro, ed è il
    parametro a renderlo visibile. Se un harness «non trova nulla», controllare prima che
    stia disegnando qualcosa: è la trappola #3 in forma nuova.

### `table.php`: due viste, due sink distinti

`table.php` è il guscio della tabella principale e contiene **due** rendering degli stessi
dati: la vista a elenco, che delega le celle a `renderFileTableCells` (coperta da
`file_cells_escape_check.php`), e la **vista a schede**, che riscrive da capo nome, path,
object, filter, exptime, imgtype e date_obs. Sono codice separato: una correzione alla
prima non tocca la seconda.

`table_escape_check.php` copre entrambe: **14 occorrenze del payload, 12 escaped e 2
percent-encoded, zero grezze**, con i due sink di nome verificati per posizione
(`class="thumb-title"` per la schede, la cella delegata per l'elenco) perché un controllo
globale passerebbe anche se solo uno dei due fosse scoperto.

Il caso pre-fix, togliendo la `htmlspecialchars` del nome nella vista a schede (riga 146):

| Rottura introdotta | Atteso |
|---|---|
| `htmlspecialchars($f['name'])` → `$f['name']` nella vista schede | **rossa**: `grezze=1`, `<img onerror>` e `<svg onload>` iniettati, e `vista schede: il nome e' escaped` va rosso con il dettaglio «il blocco thumb-title non contiene il nome escaped» |

Una cosa che il test scopre e che è facile dare per scontata: **il cookie `viewMode` non
sceglie quale vista venga renderizzata**, cambia solo la classe `hidden` di un
contenitore. Il partial stampa sempre entrambe, quindi le due esecuzioni danno gli stessi
conteggi ma **byte diversi** (40111 in entrambe, ma non identici). Un invariante scritto
sui byte sarebbe fallito sul codice giusto; l'invariante giusto è «stesse occorrenze del
payload, e tutte escaped».

Non coperto qui: i nomi dei progetti nel `<option>` della modale (riga 36). Sono
`htmlspecialchars`, ma per provarli servirebbe un progetto inserito a mano, e finora si è
preferito non scrivere righe di progetto.

## Trappole dei test (non del codice di produzione)

1. **`projects.php` risponde `302 → /?panel=projects` anche con sessione valida.**
   Non si può dedurre "non autenticato" dal 302: il segnale è un body che contiene
   il form di login (`name="password"`).
2. **`project_files` è chiazzato `(file_id, level, node_id)`.** Non esiste
   `project_files.setup_id`, e i light vengono linkati a livello `filter`, non
   `setup`. Contare i soli `level='setup'` dà un falso "0 aggiunti".
3. **Nel dataset i DARK/BIAS appartengono a un telescopio diverso dai LIGHT**
   (il fingerprint del setup include `TELESCOP`), quindi il test di §7 usa due
   progetti di prova. Inoltre `already_processed` fa rispondere `skipped` se si
   ripropone lo stesso file: per due sonde sullo stesso file la riga di suggestion
   va cancellata in mezzo.
4. **`astro_night` torna come `datetime.date`**, non stringa: le chiavi dei dizionari
   vanno normalizzate con `str()`.
5. **`opcache.revalidate_freq=2`.** Dopo aver modificato un PHP serve comunque il
   markup in cache: una regressione su `addmsg_check.php` è passata falsamente la
   prima volta per questo, e ha solo fallito dopo `sleep 4`. Prima di confrontare
   pre-fix e post-fix, **attendere almeno 3 secondi**.
6. **`json_encode` può restituire `false`** su dati reali (byte non UTF-8 nelle righe
   di `files`), e `echo false` produce stringa vuota. `project_visibility_check.php` ne
   è stato vittima: il confronto su JSON era sempre falso, quindi il test sarebbe
   passato per il motivo sbagliato. Raccogliere i valori direttamente dalle strutture.
7. **`CURLOPT_POSTFIELDS` con un array** invia `multipart/form-data`, non
   `application/x-www-form-urlencoded`. `login.php` e gli endpoint form leggono da
   `$_POST`, quindi il login fallisce **in silenzio** (HTTP 200 con la pagina di login
   dentro) e ogni richiesta successiva risulta non autenticata. Usare
   `http_build_query()` esplicitamente.
8. **`require_once` fallito è fatale e `@` non lo silenzia.** Un test destinato a girare
   sia sul codice pre-fix sia su quello corretto non può fare `require` di un file che
   nel pre-fix non esiste: serve `if (file_exists(...))`. Con lo shim in `security_batch_check.php`
   lo stesso test riporta i 13 difetti sul codice di prima e passa su quello dopo.
9. **Non bufferizzare un archivio ZIP per testarlo.** L'export di un progetto reale
   supera i 512M di `memory_limit` e il test muore esaurendo la memoria invece di
   riportare il difetto. `httpPostHead()` in `security_batch_check.php` raccoglie i
   primi byte con `CURLOPT_WRITEFUNCTION`.
10. **Le chiavi inesistenti in PHP valgono `null`, non errore.** Il contratto di
   `parseProjectAddRequest` / `projectAddPrepare` è in parte camelCase
   (`customSetups`, `groupFpOverrides`, `customSkipped`) e in parte snake_case
   (`project_id`, `new_project`), quindi un refactor di stile che "allinea" i nomi
   rompe i rami senza che nulla lo segnali. `api_bootstrap_check.php` confronta le chiavi
   lette dagli endpoint con quelle restituite dalle funzioni.
11. **`main.js` guarda solo `data.error`.** I `fetch` del wizard fanno
   `.then(r => r.json())` e poi `if (data.error) throw`, senza controllare `r.ok`.
   Quindi un `{"success":false}` con HTTP 400 non produce comunque un errore utile: il
   ramo successivo costruiva una preview vuota. Le risposte d'errore dei quattro endpoint
   JSON devono avere la chiave `error`, non solo lo status.
12. **Un `require_once` di un partial che rende markup esegue il markup.** `igroup_files_table.php`
   è un partial che stampa subito e si aspetta `$grp`/`$gi`: richiederlo a livello di
   bootstrap produce warning che finiscono nel corpo JSON (`401` preceduto da 2.7 KB di
   HTML spazzatura). I partial vanno inclusi dal chiamante nel contesto giusto.
   `api_contract_check.php` controlla che nessun `Warning`/`Notice`/`Deprecated` arrivi
   nell'HTML della home e del dettaglio progetto.
13. **Una ricerca testuale sul corpo di una funzione può trovare il proprio commento.**
   `threshold_key_check.php` verificava che `getProjectThresholds` ordinasse per
   `ORDER BY id DESC`, e passava anche senza: la stringa cercata era nel commento che
   spiega il perché. Va controllata la stringa SQL estratta dal `prepare()`, non il
   corpo. È la stessa classe della #11, e come quella l'ho trovata perché il test
   passava dove doveva fallire.
14. **`change()` non sa invertire un `execute()` raw.** La prima versione della migration
   `FixGroupThresholdsUniqueKey` diceva "reverted" senza aver toccato niente, lasciando
   una schema che non corrispondeva a nessuna versione: `down()` esplicito.
   `20261019120000_add_numbering_unique_indexes.php` ha lo stesso limite.
15. **Prima di dichiarare "pre-fix fallisce", togli anche la migration.** Con la schema
   nuova e il codice di prima `threshold_key_check.php` passava: l'upsert ricominciava a
   scattare. Le due metà del fix erano indipendenti e la schema nuova mascherava il
   difetto del codice. Per la prova serve schema di origine *e* codice di origine.
16. **Non dedurre il formato della richiesta dal tipo dell'argomento.** Il primo
   `httpPost` di `write_atomicity_check.php` mandava JSON anche al login (trappola #7,
   già documentata: il login fallisce in silenzio). Corretto deducendo "array = form",
   gli endpoint hanno cominciato a ricevere urlencoded, perché anche loro mandano array.
   Il formato è ora un flag esplicito.
17. **Un test a thread singolo non può dimostrare una corsa.** "due chiamate a
   `enqueueSuggestRequest` producono una riga" passa anche col codice che non protegge
   niente, perché le due chiamate sono sequenziali. La prova è l'inserimento doppio
   diretto, che simula i due scrittori e fallisce senza il vincolo. Vale in generale:
   un test verde su una race non è una prova della race.
18. **PDO non ha transazioni annidate.** Per verificare che una funzione non committi
   da sola quando il chiamante ha già aperto una transazione, si apre **una** transazione
   e si chiama la funzione dentro: non si annida `beginTransaction`.
19. **Dal container non si lancia `docker`.** `export_manifest_check.php` voleva confrontare
   i risultati PHP con quelli Python chiamando `docker exec`: dentro `awi-php` non c'è il
   docker CLI, quindi il confronto era muto. Sostituito ancorando i due test agli stessi
   valori attesi, calcolati dai primi principi in entrambi: se una metà implementa
   diversamente, la sua verifica fallisce comunque.
20. **Un test che muore al primo fallimento informa meno di uno che li elenca.**
   `suggest_parity_check.py` pre-fix faceva `abs(None - x)` e moriva di `TypeError`, quindi
   dei 16 difetti se ne vedeva uno. Ora ha `close()` e `fmt()` che tollerano `None` e
   l'elenco completo è quello che conta nella prova pre-fix.
21. **`''` dentro una stringa PHP non è un apostrofo.** `'a''{value}''b'` viene letto come
   stringa `'a'` poi `{value}` poi `'b'`: parse error. Se serve un apostrofo in un
   messaggio i18n, non usare `''`.
22. **Le tolleranze hanno un'unità, non sono numeri.** I default sono `'1%'`, `'2C'`,
   `'3deg'`, `'10%'`, quindi una validazione come `/^\d+(\.\d+)?$/` sul valore salvato
   rifiuta ogni valore legittimo e rompe la feature. La condizione giusta è che la stringa
   **inizi** con un numero non negativo. `export_manifest_check.php` copre il ramo con le
   unità esplicitamente, perché è il rischio che una validazione ragionevole introduce.
23. **Dopo un riavvio di Docker Desktop i bind mount sono serviti da una cache stale.**
   Il container continuava a mostrare la versione *pre-fix* di `watch_fs.py` mentre il file
   locale era già corretto, e `watcher_queue_check.py` è quindi **passato pre-fix** senza
   guardare il codice giusto. Va riavviato il container prima di fidarsi di una verifica
   pre-fix. Nello stesso gruppo nginx aveva risolto `php` quando era a `.4` mentre il
   container era passato a `.5`, con 502 su tutte le richieste: un riavvio di nginx risolve.
24. **In Python, assegnare una variabile a livello di modulo la rende locale.** Nella nuova
   `process_suggest_queue()` scrivevo `_suggest_conn = None` per gestire una connessione
   caduta: da quel punto Python la tratta come locale e la prima lettura solleva
   `UnboundLocalError`. Serve `global`.
25. **Una chiave i18n non finisce mai nell'HTML, il suo valore sì.** Cercando
   `projects_error_name` nel corpo della pagina l'asserzione era un falso pass: la chiave
   resta nel sorgente, nell'HTML c'è il testo tradotto. Va cercato il valore della lingua
   effettivamente in uso.
26. **Uno spazio finale in una stringa di traduzione è un contratto invisibile.**
   `filter_mapping_unmapped` finiva con `": "` e il JS aggiungeva `" (" + nomi + ")"`,
   dando due spazi. Tolto lo spazio dal template invece che dal JS: la spaziatura è
   invisibile in review, il separatore nel codice no.
27. **Non scrivere sintassi PowerShell dentro un file PHP.** In un test ho scritto
   `$i -ge`, `$lines.Count` e `[Math]::Max(...)`: in PHP `-ge` diventa la costante `ge`,
   `.Count` diventa la costante `Count`, e si ottiene `Undefined constant` invece di un
   errore di sintassi utile. Il controllo è fallito per tre righe prima che me ne accorgessi.
28. **Spostare un commento non basta se il commento menteva.** Prima di spostare il docblock
   orfano ho verificato le sue tre affermazioni contro il codice (forma di ritorno,
   cancellazione dei suggerimenti, creazione della catena): tutte e tre reggevano. Se
   fossero state false, il fix avrebbe solo spostato una descrizione sbagliata.

29. **Un `?>` dentro un commento `//` chiude la modalità PHP.** `lang_selector_sink_check.php`
    aveva nella testata un esempio del sink con il tag di chiusura: da lì in poi PHP
    usciva dalla modalità PHP e **stampava il resto del file** invece di eseguire una
    sola verifica. Il file sembrava un test perché era un test. Se un file PHP «non
    fallisce mai», controllare che produca verdetto.
30. **Il metodo e il nome del parametro vanno letti nella sorgente dell'endpoint.**
    `sff_get_filters.php` e `get_duplicates.php` leggono da `INPUT_GET`/`$_GET`; mandare
    un POST produce `400` a ogni richiesta, e il test misurava il percorso di rifiuto sotto
    l'etichetta «happy path». Le prime due versioni di `api_bootstrap_sweep.php` avevano
    anche `$traces === ''` su una funzione che restituisce un array: verde impossibile.
31. **Una colonna inesistente muore come «Unknown column», non come difetto dell'endpoint.**
    `get_duplicates` cerca in `files.file_hash`, non `files.hash`. Lo avevo scritto male e
    avevo scritto nel riassunto che «l'hash viene derivato»: un fatto inventato, propagato
    anche in un test. Tre volte in una sessione ho indovinato il nome di una colonna prima
    di leggere `information_schema`.
32. **Non confrontare byte-per-byte con un precedente già buggy.**
    `export_regression_probe.php` verificava che `entries` fosse identico al builder
    pre-fix, e il pre-fix aveva un ramo `elseif ($kind === 'bias')` duplicato e vuoto:
    i BIAS finivano sotto la cartella DARK del gruppo precedente. Il probe codificava il
    bug come invariante e segnalava come regressione l'output corretto. L'invariante giusta
    è «un frame di un tipo vive sotto la cartella di quel tipo». E il probe deve esigere che
    il vecchio builder **violi** l'invariante, altrimenti un giorno smetterebbe di provare
    nulla restando verde.
33. **Un test che asserisce l'assenza di un escaping fallisce il giorno in cui l'escaping
    viene aggiunto.** Il vecchio `lang_selector_sink_check.php` verificava che il template
    «non escapa», e il template è stato proprio irrobustito con `htmlspecialchars`. Il test
    rendeva pieno crediti al difetto che stava per essere corretto.
34. **Cercare una sottostringa per provare che un handler non è iniettabile è sbagliato.**
    `onmouseover` compare legittimamente come dato percent-encoded dentro `data-return`.
    Il livello giusto è il **nome dell'attributo nel DOM parsato**, e l'invariante è che
    `onchange` sia l'unico gestore, non che non ce ne siano.
35. **I conteggi assoluti sono invarianti fragili.** `cols_test.php` chiedeva 68 chiavi e 9
    gruppi: erano diventati 76 e 10, quindi il test falliva avendo ragione sul codice. La
    proprietà che vale è la corrispondenza fra insieme toggleable e gruppi, e va verificato
    che l'invariante nuovo reagisca a una rottura, altrimenti è decorativo.
36. **Il sintomo di una variabile non inizializzata dipende dall'ordine.** Lo stesso ramo
    `bias` vuoto produceva `$leaf` obsoleto (BIAS dopo DARK → `SETUP_Sn/DARK/…`) oppure
    `$leaf` mai definito (BIAS per primo → `SETUP_Sn//…`, con `Warning`). Un commento che
    documenta solo una delle due forme è sbagliato a metà.
37. **`AWI_PROJECTS_LIB` è il genitore di `indexer_lib`, non la libreria.** Passare
    `/opt/scripts/indexer_lib` a `calib_suggest_check.py` rompe con
    `ModuleNotFoundError: No module named 'indexer_lib'`; passare `/opt/scripts` a uno
    script che fa `import projects` lo rompe lo stesso. Le due convenzioni convivevano e i
    tre script con quella vecchia erano morti senza che nessuno lo notasse, perché il
    gate di parità dipende dal lato Python e il lato Python semplicemente non partiva.
38. **`/opt/scripts` è montato, ma la propagazione è a senso unico.**
    `/opt/scripts/watch_fs.py`, `/opt/scripts/reindex.py` e `/opt_scripts/indexer_lib`
    sono mount **grpcfuse**, non copie nel layer del container:
    ```
    grpcfuse /opt/scripts/watch_fs.py fuse.grpcfuse rw,...
    grpcfuse /opt/scripts/indexer_lib fuse.grpcfuse rw,...
    ```
    Scrivere nel mount **scrive nel repo** (una `cat vecchio > /opt/scripts/watch_fs.py`
    per provare il caso pre-fix mi ha cancellato la correzione appena fatta, e ho dovuto
    rifarla), mentre modificare il file nel repo **non** aggiorna il mount: il mount
    continua a servire la versione precedente finché non ci si scrive dentro. Quindi:
    - per portare una correzione nel container si scrive **dal container**, non si usa
      `docker cp` sul mount (`unlinkat: device or resource busy` su un mount di un singolo
      file);
    - per provare il caso pre-fix **non** si scrive il file vecchio sul mount: si copia
      altrove, si esegue da lì, e si ripristina;
    - `docker cp` su `/tmp` dentro il container è sicuro: `/tmp` non è montato.
    Il watcher in esecuzione (`python watch_fs.py /var/fits`) tiene in memoria il modulo
    caricato al boot: dopo una correzione **non** è ricaricato, serve un riavvio del
    processo perché la modifica valga.
Da CLI `session_start()` fallisce (`/tmp` non scrivibile): per test end-to-end
occorre fare login via `login.php` come fanno `zip_guard_check.php`,
`addmsg_check.php`, `api_bootstrap_check.php`, `bootstrap_cost_check.php` e
`security_batch_check.php`.

39. **Il delimitatore di una regex dentro un gruppo la tronca e il test va verde.**
    Per cercare `<img` dopo il payload avevo scritto
    `'/…' . $NEEDLE . '\s*<(img|svg|script)/i'`: lo `/` davanti a `i` chiudeva il
    pattern, PHP emetteva un `Warning` e restituiva `false`, e `!$liveTag` valeva
    «nessuna fuga». Il test passava mentre era rotto, ed è passato anche **dopo** che
    avevo tolto l'escaping. Ora il delimitatore è `#` e i due controlli sono
    affiancati dal DOM parsato, che non soffre di questo errore: nel caso
    dell'attributo è stato proprio il parser a trovare `onload` su `<button>`, perché
    lì il carattere dopo il payload è `<` e non `"`, cioè quello che la regex guardava.
    In generale `preg_match` che ritorna `false` è un test che non ha verificato
    niente: va trattato come fallimento, non come assenza di fuga.

40. **Un partial che dichiara funzioni non si può includere due volte in un processo.**
    `projects_tree.php` definisce `fmtExp()`, `linkKey()`, `diagBox()` e altre a
    livello di file. Per provarlo nelle due modalità in un solo `include` si ottiene
    `Cannot redeclare fmtExp()`, un errore fatale che non ha a che fare con
    l'escaping. Le due modalità sono quindi due processi separati
    (`php tree_render_escape_check.php 1` e `… 0`), ed è un parametro, non un flag
    `foreach`: un test che si autodichiara nelle due modalità ma ne esegue una sola
    lascia metà dei rami non visitati senza dirlo.

41. **Da CLI, `language.php` va preceduto da `language_functions.php`.**
    `language.php` chiama `getBestLanguage()` alla riga 3, quindi caricarlo da solo
    dà `Call to undefined function`. E il file di lingua va incluso a mano
    (`$strings = include '…/languages/en.php'`) perché usa `HEADER_TITLE`, che nella
    CLI non è definito. Con `config.php` prima dei due include il boilerplate è:
    ```php
    $lang = DEFAULT_LANGUAGE;
    $strings = include '/var/www/html/languages/' . $lang . '.php';
    require_once '/var/www/html/includes/language_functions.php';
    require_once '/var/www/html/includes/language.php';
    ```

42. **La riga giusta per provare lo escaping di una catena non è sempre la stessa.**
    `data-setup-name` sta dentro `if (!$hypoMode)` e `project_tree_preview.php` gira
    **sempre** in hypoMode: quel ramo non viene mai renderizzato dall'endpoint, quindi
    un test end-to-end che lo pretenderebbe fallirebbe per il motivo sbagliato. Il
    test HTTP verifica i contesti che quell'endpoint raggiunge davvero e **afferma**
    che il ramo attributo non è stato visitato; il contesto attributo è coperto dal
    test a harness, che esercita `hypoMode` anche falso. Lo stesso vale per
    `render_sff_filter()`: il ramo `toggle` non ha slider, quindi un `unit` ostile
    messo lì non raggiunge `data-unit` e la verifica passerebbe senza coprire niente.

43. **`&pm;` resta letterale nell'output.** Il template lo scrive grezzo e il browser
    lo decodifica, quindi cercare il carattere `±` nel rendering non trova niente e il
    check va rosso con l'escaping intatto. Cercare `&pm;0` più il valore.

44. **`mysql.connector` ha `autocommit=False` per default, e una connessione di test lunga
    legge dati vecchi.** La transazione implicita aperta dalla prima SELECT tiene uno
    snapshot REPEATABLE READ: le SELECT successive vedono il database com'era in quel
    momento, **non** quello aggiornato dal processo sotto test. In
    `reindex_batch_continue_check.py` il reindex scriveva 4 righe, la SELECT ne contava 0,
    e la DELETE sullo stesso prefisso ne cancellava 4: la stessa query con due verità
    diverse. Non è un bug: le letture con blocco (DELETE, UPDATE, SELECT ... FOR UPDATE)
    usano sempre l'ultima versione committata, le semplici no. Con `autocommit=True` la
    contraddizione non è possibile.

45. **`os.walk` restituisce le directory nell'ordine del filesystem, quindi quale batch
    erediti il caso non è un'invariante.** La prima versione del test asseriva che il
    blocco perso fosse `d0`, quello del file velenoso: è passata, e nella passata
    successiva il velenoso era finito in `d1` e il check sarebbe andato rosso **sul
    codice corretto**. Il numero di flush falliti è l'invariante (uno su quattro, sempre);
    *quale* blocco sparisca è un'informazione. Verificato con due passate su directory
    temporanee diverse.

46. **Due connessioni più un `commit` esplicito possono bloccare un database condiviso
    e tirar giù il sito.** Una sonda ha lasciato aperta una transazione: il `commit` è
    rimasto appeso 246 secondi, il sito ha risposto 504 e poi ha smesso di rispondere,
    fino al riavvio di Docker. Non è stato isolata la causa esatta e non viene
    inventata: cosa è certo è che `executemany` fallito dentro una transazione, un'altra
    connessione sulla stessa tabella e un `commit` dopo una `SELECT` compongono la
    combinazione che l'ha fatto accadere. Da allora la sonda usa una connessione sola,
    `autocommit=True`, `innodb_lock_wait_timeout = 5` (il default è 50 s: la contenzione
    deve sollevare, non mettere in attesa) e `max_statement_time = 120` — che su MariaDB
    si chiama così, `max_execution_time` risponde `1193 Unknown system variable`.
    Ogni sonda che scrive deve inoltre finire con **un controllo che il sito risponde** e
    con **la verifica che i conteggi siano tornati**: una sonda che lascia il database
    bloccato può avere tutte le asserzioni verdi.

47. **Il controllo negativo deve dimostrare di essere partito.** La prima esecuzione
    «pre-fix» ha dato esattamente lo stesso output di quella post-fix — 150 righe, exit 0 —
    perché avevo editato la sonda per stampare il percorso di `reindex.py` e **non l'avevo
    copiata nel container**: stava girando ancora la versione precedente, con il path
    hardcoded. Le due righe di log che dovevano identificare il binario usato erano
    semplicemente assenti dall'output, ed è quello che ha smascherato la cosa. Lo stesso
    vale per `docker cp` dopo ogni edit: la trappola #23 vale anche per `/tmp`, non solo
    per i mount grpcfuse.

48. **`path` è relativo a `fits_root`, quindi va passata la directory *esterna* all'albero
    di prova.** Passando la sottodirectory che contiene i file, `path` diventava il solo
    nome del file (`000.fits`) e il prefisso atteso non corrispondeva mai: il reindex
    riusciva, la SELECT non trovava nulla, e la pulizia non cancellava niente. Le 4 righe
    rimaste dentro andovano rimosse a mano, individuate per `instrume = 'BATCHPROBE'` e
    non con un `LIKE` larghissimo che avrebbe toccato l'archivio vero.

49. **Un detector basato su regex produce un falso positivo quando cerca `onerror=` dentro
    un attributo correttamente escapato** — è la trappola #34, che mi è capitata di
    ripetere. In `file_cells_escape_check.php` il check
    `preg_match('#duplicate-badge[^>]*on[a-z]+=#')` segnalava «iniettato» sul badge
    duplicati, quando il testo `onerror=alert(1)` era semplicemente **dentro** il valore
    di `data-hash`, con `&lt;` al posto delle parentesi acute. `[^>]*` non attraversa il
    `>` di chiusura del tag, quindi la regex non stava guardando fuori dagli attributi:
    stava leggendo il contenuto di uno. L'unico controllo valido è il nome dell'attributo
    nel DOM parsato.

50. **Sul DOM gli attributi sono già decodificati, quindi cercarci dentro la forma
    escaped è sbagliato.** `getAttribute('data-hash')` restituisce
    `XSS<img src=x onerror=alert(1)>…`, non `XSS&lt;img…`. La prima versione del check
    asseriva `str_starts_with($hash, 'XSS&lt;')` ed è andata rossa con il codice
    **corretto**, perché stava chiedendo al parser di non aver risolto le entità che per
    definizione risolve. L'invariante giusto sul DOM è che il valore **torni identico
    all'originale**: escaping senza perdite, confine dell'attributo integro, payload
    portato come valore e non come markup. La forma escaped si verifica sul sorgente
    (`str_contains($html, 'data-hash="XSS&lt;')`), che è l'unico posto dove esiste.

51. **La lista dei tag consentiti non basta quando il partial scrive `<img>` di proposito.**
    Negli altri test di escaping l'elenco dei tag che il template non deve mai produrre
    poteva essere «nessuno»: qui `file_cells.php` disegna legittimamente `<img>` per le
    miniature, `<div>`, `<span>`, `<a>`, `<td>`, e la lista di divieto vuota segnalava
    tutta la tabella come iniezione. Qui la lista è di **divieto** (`svg`, `script`,
    `iframe`, `object`, `embed`, `form`) più «ogni attributo `on*` su qualunque elemento»,
    e le due `<img>` legittime vengono controllate a parte: il loro `src` deve essere
    `/image.php?id=<intero>&type=thumb|crop` e non contenere il payload.

52. **`edit` sul repo, `docker cp`, poi eseguire: nell'ordine, sempre.** Dopo una modifica
    alla sonda l'ho eseguita due volte senza copiarla, e la seconda mostrava l'output
    identico di un caso precedente. È la trappola #47 applicata a se stessa: la copia nel
    container è un passo, non un dettaglio.

53. **Git Bash converte gli argomenti che sembrano path POSIX.** `docker exec -e
    AWI_PROJECTS_LIB=/opt/scripts ...` lanciato da Git Bash arriva nel container
    come `C:/Program Files/Git/opt/scripts`, e il test muore di `ModuleNotFoundError`
    pur avendo la variabile "settata". Da PowerShell non succede. `tests/run.sh`
    esporta `MSYS_NO_PATHCONV=1` per questo; lanciando i comandi a mano da Git Bash
    serve la stessa variabile (o il doppio slash `//opt/scripts`).