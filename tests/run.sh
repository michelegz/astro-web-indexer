#!/bin/sh
# Suite di regressione: esegue i gate di tests/{php,python,js} nei container.
#
# Uso:  sh tests/run.sh [pattern ...]
#   Gli argomenti opzionali filtrano i test per nome (es: sh tests/run.sh escape).
# Richiede: container awi-php e awi-python avviati (stack docker di sviluppo
#   con src/ montato e DB con dati); node per i check .mjs.
# Uscita: 0 se tutto verde, 1 se almeno un gate fallisce.
#
# Convenzioni (da tests/README.md):
# - i test PHP girano in /tmp/harness dentro awi-php (copia di api+includes+
#   assets+languages+*.php da /var/www/html): vale sia per gli "autonomi" che
#   per quelli "con albero", un solo percorso, meno modi di sbagliare;
# - i test Python girano in /tmp dentro awi-python con
#   AWI_PROJECTS_LIB=/opt/scripts (mai scrivere nei mount: /tmp non e' montato);
# - tree_render_escape_check.php gira in DUE processi (hypo 1 e 0);
# - hash_parity e' un gate a due meta': php + python + diff;
# - stale_check.py / stale_resurrect.py sono strumenti manuali (vogliono un
#   file_id): vengono saltati con nota.

# Git Bash su Windows converte gli argomenti che sembrano path POSIX
# (-e AWI_PROJECTS_LIB=/opt/scripts arrivava come C:/Program Files/...):
# disattiva la conversione per tutti i docker exec sotto.
MSYS_NO_PATHCONV=1
export MSYS_NO_PATHCONV

PASS=0
FAIL=0
SKIP=0
FAILED_LIST=""

match() {
    # $1 = basename; true se nessun filtro o se un filtro matcha
    if [ "$#" -eq 1 ]; then
        return 0
    fi
    name="$1"
    shift
    for pat in "$@"; do
        case "$name" in
            *"$pat"*) return 0 ;;
        esac
    done
    return 1
}

record() {
    # $1 = nome, $2 = exit code
    if [ "$2" -eq 0 ]; then
        PASS=$((PASS + 1))
        echo "PASS $1"
    else
        FAIL=$((FAIL + 1))
        FAILED_LIST="$FAILED_LIST $1"
        echo "FAIL $1 (exit $2)"
    fi
}

skip() {
    SKIP=$((SKIP + 1))
    echo "SKIP $1 ($2)"
}

echo "=== setup: harness PHP in awi-php ==="
docker exec awi-php sh -c 'rm -rf /tmp/harness && mkdir -p /tmp/harness && cp -r /var/www/html/api /var/www/html/includes /var/www/html/assets /var/www/html/languages /tmp/harness/ && cp /var/www/html/*.php /tmp/harness/' || {
    echo "FATAL: impossibile preparare /tmp/harness in awi-php"
    exit 1
}

echo "=== gate PHP ==="
for f in tests/php/*.php; do
    base=$(basename "$f" .php)
    case "$base" in
        hash_parity) continue ;; # gate a due meta', sotto
    esac
    if ! match "$base" "$@"; then
        continue
    fi
    docker cp "$f" awi-php:/tmp/harness/ > /dev/null || {
        record "$base (docker cp)" 1
        continue
    }
    if [ "$base" = "tree_render_escape_check" ]; then
        docker exec awi-php sh -c "cd /tmp/harness && php $base.php 1"
        record "$base hypo=1" $?
        docker exec awi-php sh -c "cd /tmp/harness && php $base.php 0"
        record "$base hypo=0" $?
    else
        docker exec awi-php sh -c "cd /tmp/harness && php $base.php"
        record "$base" $?
    fi
done

echo "=== gate Python ==="
for f in tests/python/*.py; do
    base=$(basename "$f" .py)
    case "$base" in
        hash_parity|stale_check|stale_resurrect) continue ;;
    esac
    if ! match "$base" "$@"; then
        continue
    fi
    if [ "$base" = "hash_parity_diff" ]; then
        continue # gira dentro il gate di parita', sotto
    fi
    docker cp "$f" awi-python:/tmp/ > /dev/null || {
        record "$base (docker cp)" 1
        continue
    }
    docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python sh -c "cd /tmp && python $base.py"
    record "$base" $?
done
if match "stale_check" "$@"; then
    skip "stale_check.py" "manuale: richiede <file_id>"
fi
if match "stale_resurrect" "$@"; then
    skip "stale_resurrect.py" "manuale: richiede <file_id>"
fi

echo "=== gate parita' PHP<->Python ==="
if match "hash_parity" "$@"; then
    docker cp tests/php/hash_parity.php awi-php:/tmp/harness/ > /dev/null
    docker cp tests/python/hash_parity.py awi-python:/tmp/ > /dev/null
    docker cp tests/python/hash_parity_diff.py awi-python:/tmp/ > /dev/null
    docker exec awi-php sh -c 'cd /tmp/harness && php hash_parity.php' > /tmp/awi_php.json
    rc1=$?
    record "hash_parity.php" $rc1
    if [ $rc1 -eq 0 ]; then
        docker cp /tmp/awi_php.json awi-python:/tmp/php.json > /dev/null
        docker exec awi-python sh -c 'cd /tmp && python hash_parity.py' > /tmp/awi_py.json
        rc2=$?
        record "hash_parity.py" $rc2
        if [ $rc2 -eq 0 ]; then
            docker cp /tmp/awi_py.json awi-python:/tmp/py.json > /dev/null
            docker exec awi-python sh -c 'cd /tmp && python hash_parity_diff.py php.json py.json'
            record "hash_parity_diff" $?
        fi
    fi
    rm -f /tmp/awi_php.json /tmp/awi_py.json
fi

echo "=== check JS (node locale) ==="
if command -v node > /dev/null 2>&1; then
    for f in tests/js/*.mjs; do
        base=$(basename "$f" .mjs)
        if ! match "$base" "$@"; then
            continue
        fi
        node "$f"
        record "$base" $?
    done
else
    skip "tests/js/*" "node non trovato"
fi

echo "=== risultato: PASS=$PASS FAIL=$FAIL SKIP=$SKIP ==="
if [ -n "$FAILED_LIST" ]; then
    echo "falliti:$FAILED_LIST"
fi
[ "$FAIL" -eq 0 ]
