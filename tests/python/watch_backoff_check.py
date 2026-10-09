# Check §10 — the watcher must not retry the reindex every second.
#
# Before the fix last_reindex was only updated on success, so with a
# failed reindex the cooldown condition was always true and every second
# a full archive + DB scan started: exactly when the DB is down.
# Also only CalledProcessError was caught, so a missing script or a
# timeout terminated the watcher process.
#
# The test replaces subprocess.run with a stub, so no broken DB is needed and
# no real process is started.
#
# Usage:  docker cp tmp/watch_backoff_check.py awi-python:/tmp/
#         docker exec awi-python sh -c 'cd /tmp && python watch_backoff_check.py'

import importlib.util
import os
import subprocess
import sys

WATCH = os.environ.get("AWI_WATCH_FS", "/opt/scripts/watch_fs.py")
spec = importlib.util.spec_from_file_location("watch_fs", WATCH)
watch_fs = importlib.util.module_from_spec(spec)
spec.loader.exec_module(watch_fs)

failed = []


def check(label, cond, detail=""):
    print(f"  {label:<38} {detail}  {'OK' if cond else '<<< FAILED'}")
    if not cond:
        failed.append(label)


def make_handler():
    h = watch_fs.FitsHandler.__new__(watch_fs.FitsHandler)
    h.fits_dir = None
    h.reindex_script = None
    h.db_params = {"host": "h", "user": "u", "password": "p", "database": "d"}
    h.debug = False
    h.retention_days = 30
    h.thumb_size = 300
    h.pending_reindex = True
    h.last_reindex = 0
    h.cooldown = 10
    h.cooldown_max = 300
    h.reindex_failures = 0
    h.reindex_reason = "test"
    h.rescan_interval = 5.0
    h.last_scan = 0.0
    h.known_files = set()
    return h


# global stub: counts the attempts and raises the configured exception
state = {"n": 0, "exc": subprocess.CalledProcessError(1, "reindex.py")}
_real_run = watch_fs.subprocess.run


def stub_run(cmd, check=False, **kw):
    state["n"] += 1
    if state["exc"] is not None:
        raise state["exc"]
    return 0


watch_fs.subprocess.run = stub_run

# fake clock: the cooldown is measured in seconds of time, not in iterations
clock = {"t": 1000.0}
watch_fs.time.time = lambda: clock["t"]

# ---------------------------------------------------------------------------
# 1. reindex exiting non-zero: how many attempts in 60 seconds?
# ---------------------------------------------------------------------------
h = make_handler()
state["n"] = 0
per_second = []
for _ in range(60):
    clock["t"] += 1.0
    h.check_and_reindex()
    per_second.append(state["n"])

attempts = state["n"]
print(f"  consecutive failures: {h.reindex_failures}")
print(f"  current cooldown: {h.cooldown}s")
print(f"  attempts in 60 seconds: {attempts}")
check("does not retry every second", attempts <= 12, f"{attempts} attempts in 60s")
check("backoff grew", h.cooldown > 10, f"cooldown={h.cooldown}s")
check("cooldown under the cap", h.cooldown <= h.cooldown_max,
      f"{h.cooldown} <= {h.cooldown_max}")
check("pending_reindex stays True", h.pending_reindex is True,
      "the work is not lost: it will be retried")

# ---------------------------------------------------------------------------
# 2. after the backoff, a successful reindex resets everything
# ---------------------------------------------------------------------------
state["exc"] = None
before = state["n"]
clock["t"] += h.cooldown + 1
h.check_and_reindex()
check("successful attempt", state["n"] == before + 1, f"attempts={state['n']}")
check("reset on success",
      h.reindex_failures == 0 and h.cooldown == 10 and h.pending_reindex is False,
      f"failures={h.reindex_failures} cooldown={h.cooldown} pending={h.pending_reindex}")

# ---------------------------------------------------------------------------
# 3. exceptions that were not caught before must not propagate
# ---------------------------------------------------------------------------
for name, exc in [("FileNotFoundError", FileNotFoundError("reindex.py")),
                  ("PermissionError", PermissionError("denied")),
                  ("TimeoutExpired", subprocess.TimeoutExpired("reindex.py", 600))]:
    h2 = make_handler()
    state["exc"] = exc
    clock["t"] += 1000
    try:
        h2.check_and_reindex()
        ok, why = True, ""
    except Exception as e:
        ok, why = False, f"propagated {type(e).__name__}"
    check(f"{name} handled", ok, why or "caught and recorded")

# ---------------------------------------------------------------------------
# 4. the 300s cap holds even with repeated failures
# ---------------------------------------------------------------------------
h3 = make_handler()
state["exc"] = subprocess.CalledProcessError(1, "reindex.py")
for _ in range(40):
    clock["t"] += 10000
    h3.check_and_reindex()
check("backoff capped", h3.cooldown == h3.cooldown_max,
      f"cooldown={h3.cooldown}s after {h3.reindex_failures} failures")

watch_fs.subprocess.run = _real_run
print("\nRESULT: " + ("all checks passed" if not failed
                      else f"FAILED: {failed}"))
sys.exit(0 if not failed else 1)