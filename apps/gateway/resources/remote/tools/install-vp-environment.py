# Program: tools.install-vp-environment
# Uses: lib/program.py lib/vp-store.py
# Outcomes: installed published inspected failed lock_timeout
# Timeout: 600
# Input: exact vp environment plus npmrc/lock_timeout, or recipe mode inspect|publish with store/version/archive/sha256.
# Result: installed: {"verified": checksum ledger}; inspected: {"version": current recipe Node or null}. Others: no data.
# Runs only as the store owner. New immutable versions are staged and renamed atomically.


def main(payload):
    try:
        if 'mode' in payload:
            home = pathlib.Path(payload['store'])
            if payload['mode'] == 'inspect':
                try:
                    version = recipe_node_version(home)
                except (OSError, ValueError, KeyError, TypeError):
                    version = None
                return 'inspected', {'version': version}
            if payload['mode'] != 'publish':
                return 'failed', {}
            publish_verified_node(home, payload['version'], payload['archive'], payload['sha256'])
            return 'published', {}
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
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError):
        # Do not leak registry credentials or private URLs through error text.
        return 'failed', {}


run_program(main)
