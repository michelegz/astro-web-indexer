#!/bin/sh
# Regression suite: runs the tests/{php,python,js} gates inside the containers.
#
# Usage:  sh tests/run.sh [pattern ...]
#   The optional arguments filter the tests by name (e.g. sh tests/run.sh escape).
# Requires: running awi-php and awi-python containers (development docker stack
#   with src/ mounted and a populated DB); node for the .mjs checks.
# Exit: 0 if everything is green, 1 if at least one gate fails.
#
# Conventions (from tests/README.md):
# - PHP tests run in /tmp/harness inside awi-php (a copy of api+includes+
#   assets+languages+*.php from /var/www/html): this covers both the
#   "standalone" and the "with-tree" ones, a single path, fewer ways to go wrong;
# - Python tests run in /tmp inside awi-python with
#   AWI_PROJECTS_LIB=/opt/scripts (never write into the mounts: /tmp is not mounted);
# - tree_render_escape_check.php runs in THREE processes (hypo 1, 0 and review 2);
# - hash_parity is a two-halves gate: php + python + diff;
# - stale_check.py / stale_resurrect.py are manual tools (they want a
#   file_id): they are skipped with a note.

# Git Bash on Windows converts arguments that look like POSIX paths
# (-e AWI_PROJECTS_LIB=/opt/scripts arrived as C:/Program Files/...):
# disable the conversion for every docker exec below.
MSYS_NO_PATHCONV=1
export MSYS_NO_PATHCONV

PASS=0
FAIL=0
SKIP=0
FAILED_LIST=""

match() {
    # $1 = basename; true if there is no filter or if a filter matches
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
    # $1 = name, $2 = exit code
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
    echo "FATAL: cannot prepare /tmp/harness in awi-php"
    exit 1
}

echo "=== gate PHP ==="
for f in tests/php/*.php; do
    base=$(basename "$f" .php)
    case "$base" in
        hash_parity) continue ;; # two-halves gate, below
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
        docker exec awi-php sh -c "cd /tmp/harness && php $base.php 2"
        record "$base review=2" $?
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
        continue # runs inside the parity gate, below
    fi
    docker cp "$f" awi-python:/tmp/ > /dev/null || {
        record "$base (docker cp)" 1
        continue
    }
    docker exec -e AWI_PROJECTS_LIB=/opt/scripts awi-python sh -c "cd /tmp && python $base.py"
    record "$base" $?
done
if match "stale_check" "$@"; then
    skip "stale_check.py" "manual: requires <file_id>"
fi
if match "stale_resurrect" "$@"; then
    skip "stale_resurrect.py" "manual: requires <file_id>"
fi

echo "=== parity gate PHP<->Python ==="
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
    skip "tests/js/*" "node not found"
fi

echo "=== result: PASS=$PASS FAIL=$FAIL SKIP=$SKIP ==="
if [ -n "$FAILED_LIST" ]; then
    echo "failed:$FAILED_LIST"
fi
[ "$FAIL" -eq 0 ]
