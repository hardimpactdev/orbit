# Program: database-servers.write-valkey-acl
# Uses: lib/program.py
# Outcomes: written failed
# Timeout: 300
# Input: {"volume": Docker volume name, "image": "valkey/valkey:<tag>", "acl": the ACL file}
# Result: no data. failed logs the check or the docker error.
# Writes /data/users.acl into the Docker volume of a Valkey Database server, before Valkey starts from it, or again to
# repair it (ADR 0221). A short-lived container without a network mounts the volume and reads the file from standard
# input, so the file never reaches an argument list, and replaces the old file in one rename. The first line is
# `default` with the SHA-256 hash of the admin password and every right, or nopass during explicit adoption of an open
# server. Each other line is a managed user in Orbit's frame: one password hash, key patterns, no channels, and the
# allowed commands between `-@all` and the final `-@admin -@dangerous -function -script`.
import re
import subprocess

VOLUME = re.compile(r'[A-Za-z0-9][A-Za-z0-9_.-]{1,254}')
IMAGE = re.compile(r'valkey/valkey:[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}')
HASH = re.compile(r'#[0-9a-f]{64}')
USER = re.compile(r'[a-z0-9]+(?:-[a-z0-9]+)*')
MANAGED_RULES = re.compile(
    r'on(?: ~[!-~]+)+ resetchannels -@all(?: \+[!-~]+)* -@admin -@dangerous -function -script'
)
WRITE = 'umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl'


def valid_acl(acl):
    """Read ACL LIST/file tokens; hashes and flags may appear in either order. Orbit does not set payload flags."""
    if not isinstance(acl, str) or not acl.endswith('\n'):
        return False
    for index, line in enumerate(acl[:-1].split('\n')):
        tokens = line.split(' ')
        if len(tokens) < 3 or tokens[0] != 'user' or USER.fullmatch(tokens[1]) is None:
            return False
        hashes = [token for token in tokens[2:] if HASH.fullmatch(token)]
        rules = [token for token in tokens[2:] if not HASH.fullmatch(token)
                 and token != 'sanitize-payload']
        if index == 0:
            if tokens[1] != 'default' or not (
                (len(hashes) == 1 and rules == ['on', '~*', '&*', '+@all'])
                or (not hashes and rules == ['on', 'nopass', '~*', '&*', '+@all'])
            ):
                return False
        elif tokens[1] == 'default' or len(hashes) != 1 or MANAGED_RULES.fullmatch(' '.join(rules)) is None:
            return False
    return True


def main(payload):
    volume, image, acl = payload.get('volume'), payload.get('image'), payload.get('acl')
    require(isinstance(volume, str) and VOLUME.fullmatch(volume) is not None, 'failed',
            'the volume is not a Docker volume name')
    require(isinstance(image, str) and IMAGE.fullmatch(image) is not None, 'failed',
            'the image is not valkey/valkey:<tag>')
    require(valid_acl(acl), 'failed',
            'the ACL file is not the default line and managed user lines')
    completed = subprocess.run(
        ['sudo', '-n', 'docker', 'run', '--rm', '-i', '--network', 'none',
         '--mount', f'type=volume,source={volume},target=/data', '--entrypoint', 'sh', image, '-c', WRITE],
        input=acl, capture_output=True, text=True, check=False,
    )
    if completed.returncode != 0:
        log(f'docker run exited {completed.returncode}')
        return 'failed', {}
    return 'written', {}


run_program(main)
