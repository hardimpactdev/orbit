# Program: tools.prune-vp-store
# Uses: lib/program.py lib/vp-store.py
# Outcomes: pruned failed
# Timeout: 120
# Input: {"store": absolute store path, "references": all retained vp_environment records}.
# Result: pruned: {"removed": paths}. failed: no data.
# Caller holds the Gateway manager scope lock while reading references and invoking this program.
import fcntl


def main(payload):
    try:
        home = pathlib.Path(payload['store'])
        if home.is_symlink() or not home.is_absolute() or home.stat().st_uid != os.getuid():
            return 'failed', {}
        with (home / '.orbit-store.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            return 'pruned', {'removed': prune(home, payload['references'])}
    except (OSError, ValueError, KeyError, TypeError):
        return 'failed', {}


run_program(main)
