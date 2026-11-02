"""node.ssh_access_converge: Orbit owns only its marked block in the managed account's authorized_keys.

Usage: authorized_keys.py write|verify '<intent json>' [<outside digest>], run as the managed account.
The intent holds keys, the block's lines in order. write replaces only the lines between the markers, or
appends the block at the end; it never removes, reorders or changes a line outside the block, and an
identical block writes nothing. A file with broken markers is refused before any write. write prints only the
digest of the lines outside the block; verify checks the block holds exactly the keys and the lines outside
it still have that digest, and prints the terminal result. A refusal prints its reason on stderr and exits 1.
"""
import hashlib
import json
import os
import sys
import tempfile
from pathlib import Path

BEGIN = '# BEGIN Orbit managed keys'
END = '# END Orbit managed keys'


def refuse(reason):
    sys.exit(f'node.ssh_access_failed: {reason}')


def split(lines):
    """The lines before, inside and after the block; inside is None when there is no block."""
    begins = [i for i, line in enumerate(lines) if line == BEGIN]
    ends = [i for i, line in enumerate(lines) if line == END]
    if not begins and not ends:
        return lines, None, []
    if len(begins) != 1 or len(ends) != 1 or ends[0] < begins[0]:
        refuse('authorized_keys has broken Orbit markers; nothing was written')
    return lines[:begins[0]], lines[begins[0] + 1:ends[0]], lines[ends[0] + 1:]


def digest(lines):
    return hashlib.sha256('\n'.join(lines).encode()).hexdigest()


def read(path):
    try:
        return path.read_text().splitlines()
    except FileNotFoundError:
        return []


def write(path, keys):
    for key in keys:
        if not key or '\n' in key or key in (BEGIN, END):
            refuse('a key is one non-marker line')
    lines = read(path)
    before, inside, after = split(lines)
    outside = before + after
    if inside == keys:
        return {'changed': False, 'outside_digest': digest(outside)}
    updated = before + [BEGIN, *keys, END] + after
    path.parent.mkdir(mode=0o700, exist_ok=True)
    fd, candidate = tempfile.mkstemp(prefix='.authorized_keys.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write('\n'.join(updated) + '\n')
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(candidate, 0o600)
        os.replace(candidate, path)
    except BaseException:
        Path(candidate).unlink(missing_ok=True)
        raise
    return {'changed': True, 'outside_digest': digest(outside)}


def verify(path, keys, outside_digest):
    before, inside, after = split(read(path))
    if inside != keys:
        refuse('the Orbit block does not hold exactly the intended keys')
    if digest(before + after) != outside_digest.strip():
        refuse('the lines outside the Orbit block changed')
    if path.stat().st_mode & 0o077:
        refuse('authorized_keys is readable or writable by others')
    return {'ssh_access_ready': True}


def main(action, raw, outside_digest=None):
    keys = json.loads(raw)['keys']
    path = Path.home() / '.ssh' / 'authorized_keys'
    if action == 'write':
        print(write(path, keys)['outside_digest'])
    else:
        print(json.dumps(verify(path, keys, outside_digest)))


if __name__ == '__main__':
    main(*sys.argv[1:])
