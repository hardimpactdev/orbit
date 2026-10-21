# Program: tools.publish-recipe-node
# Uses: lib/program.py lib/vp-store.py
# Outcomes: published inspected failed lock_timeout
# Timeout: 600
# Input: {"store": absolute store, "mode": inspect|publish, "version": exact Node, "archive": verified archive path, "sha256": recipe checksum}.
# Result: inspected: {"version": current recipe Node or null}. Other outcomes: no data.
# Runs as the enrolled account; the store library owns migration, verification, locking and publication.


def main(payload):
    try:
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
    except TimeoutError:
        return 'lock_timeout', {}
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError):
        return 'failed', {}


run_program(main)
