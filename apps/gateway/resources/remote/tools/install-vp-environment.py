# Program: tools.install-vp-environment
# Uses: lib/program.py lib/vp-store.py
# Outcomes: installed failed lock_timeout
# Timeout: 600
# Input: exact vp environment record plus npmrc and optional lock_timeout (seconds, at most 120).
# Result: installed: {"verified": ledger of newly installed paths and checksums}. Other outcomes: no data.
# Runs only as the store owner. New immutable versions are staged and renamed atomically.


def main(payload):
    try:
        home, _, _ = store_paths(payload)
        # Fast path remains strictly read-only, even when another installation holds the lock.
        if installed(payload):
            return 'installed', {'verified': {}}
        with locked_store(home, payload.get('lock_timeout', 120)):
            if installed(payload):
                return 'installed', {'verified': {}}
            return 'installed', {'verified': install(payload)}
    except TimeoutError:
        return 'lock_timeout', {}
    except (OSError, ValueError, KeyError, TypeError):
        # Do not leak registry credentials or private URLs through error text.
        return 'failed', {}


run_program(main)
