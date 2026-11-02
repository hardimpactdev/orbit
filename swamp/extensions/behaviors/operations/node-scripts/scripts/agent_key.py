"""node.agent_rekey: a new Node agent key, made on the Node; it never leaves it.

Usage: agent_key.py rekey | check <verifier> | restore, run with sudo.
rekey: keeps the old key as secret.previous, writes 32 random bytes as 64 hex characters to
  /etc/orbit/agent/secret (root:root, 0600) with one rename, and prints only the new key's SHA-256 verifier.
check: the agent is active and the key file matches the verifier; then secret.previous is removed, best effort.
  Prints the terminal result.
restore: puts secret.previous back and restarts the agent, so it matches the verifier the Gateway still has.
A refusal prints its reason on stderr and exits 1.
"""
import hashlib
import json
import os
import secrets
import subprocess
import sys
import tempfile
from pathlib import Path

DIRECTORY = Path('/etc/orbit/agent')
SECRET = DIRECTORY / 'secret'
PREVIOUS = DIRECTORY / 'secret.previous'
UNIT = 'orbit-agent.service'


def refuse(code, reason):
    sys.exit(f'{code}: {reason}')


def publish(path, data):
    fd, candidate = tempfile.mkstemp(prefix=f'.{path.name}.', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(candidate, 0o600)
        os.replace(candidate, path)
    except BaseException:
        Path(candidate).unlink(missing_ok=True)
        raise


def rekey():
    DIRECTORY.mkdir(mode=0o700, parents=True, exist_ok=True)
    if SECRET.is_file():
        publish(PREVIOUS, SECRET.read_bytes())
    key = secrets.token_hex(32).encode()
    publish(SECRET, key)
    return {'verifier_hash': hashlib.sha256(key).hexdigest()}


def check(verifier):
    if subprocess.run(['/usr/bin/systemctl', 'is-active', '--quiet', UNIT], check=False).returncode:
        refuse('node.agent_inactive', 'the agent is not active after its restart')
    if hashlib.sha256(SECRET.read_bytes()).hexdigest() != verifier.strip():
        refuse('agent.secret_mismatch', 'the key file does not match the new verifier')
    PREVIOUS.unlink(missing_ok=True)
    return {'rekeyed': True, 'restarted': True, 'verifier_hash': verifier.strip()}


def restore():
    if PREVIOUS.is_file():
        os.replace(PREVIOUS, SECRET)
        subprocess.run(['/usr/bin/systemctl', 'restart', UNIT], check=False)
    return {'restored': True}


def main(action, *args):
    result = rekey() if action == 'rekey' else check(args[0]) if action == 'check' else restore()
    print(result['verifier_hash'] if action == 'rekey' else json.dumps(result))


if __name__ == '__main__':
    main(*sys.argv[1:])
