"""The Node agent's own footprint (legacy agent.install and agent.configure, minus key rotation).

Usage: agent.py install|configure|check '<intent json>' [binary changed: true|false], run with sudo.
install: the pinned binary at /usr/local/bin/orbit-agent; a matching binary is left alone. Otherwise the release
  is downloaded, its SHA-256 checked, the old binary kept as orbit-agent.previous, and the new one renamed in.
  Other running services of the old binary (agent services such as plausible) restart on the new one.
configure: config.toml, ca.pem and the unit, each written with one rename when it differs. The installed key
  must match the recorded verifier; this step never writes a key. Instance .env files lose their world bits.
  The agent restarts only when the binary or a file changed.
check: the agent is active, runs the pinned binary, and every file holds its content.
A refusal prints its reason on stderr and exits 1.
"""
import hashlib
import json
import os
import pwd
import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

BINARY = Path('/usr/local/bin/orbit-agent')
PREVIOUS = Path('/usr/local/bin/orbit-agent.previous')
DIRECTORY = Path('/etc/orbit/agent')
SECRET = DIRECTORY / 'secret'
UNIT = 'orbit-agent.service'


def refuse(code, reason):
    sys.exit(f'{code}: {reason}')


def sha256(path):
    try:
        with open(path, 'rb') as stream:
            return hashlib.file_digest(stream, 'sha256').hexdigest()
    except FileNotFoundError:
        return None


def systemctl(*args):
    return subprocess.run(['/usr/bin/systemctl', *args], check=False).returncode == 0


def binary_units():
    """Units other than the agent whose processes run the installed binary."""
    units = set()
    for entry in Path('/proc').iterdir():
        if not entry.name.isdigit():
            continue
        try:
            if os.readlink(entry / 'exe').removesuffix(' (deleted)') != str(BINARY):
                continue
            cgroup = (entry / 'cgroup').read_text()
        except OSError:
            continue
        unit = cgroup.strip().rsplit('/', 1)[-1]
        if unit.endswith('.service') and unit != UNIT:
            units.add(unit)
    return sorted(units)


def install(intent):
    if sha256(BINARY) == intent['sha256']:
        return {'changed': False}
    running = binary_units()
    fd, candidate = tempfile.mkstemp(prefix='.orbit-agent-candidate-', dir=BINARY.parent)
    try:
        digest = hashlib.sha256()
        with os.fdopen(fd, 'wb') as output, urllib.request.urlopen(intent['url'], timeout=60) as response:
            while chunk := response.read(65536):
                output.write(chunk)
                digest.update(chunk)
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), 0o755)
        if digest.hexdigest() != intent['sha256']:
            refuse('node.agent_checksum_mismatch', 'the download does not match the pinned SHA-256')
        if BINARY.exists():
            subprocess.run(['/usr/bin/cp', '--preserve=mode', str(BINARY), str(PREVIOUS)], check=True)
        os.replace(candidate, BINARY)
    finally:
        Path(candidate).unlink(missing_ok=True)
    for unit in running:
        if not systemctl('restart', unit):
            refuse('node.agent_service_failed', f'{unit} did not restart on the new binary')
    return {'changed': True}


def files(intent):
    """The three agent files; the unit opens the apps root when it sits in a home, as the role steps do."""
    agent = intent['configuration']
    home = pwd.getpwnam(intent['user']).pw_dir
    root = agent['apps_root'] or home + '/apps'
    access = 'ProtectHome=tmpfs' + (f'\nBindReadOnlyPaths=-{root}' if root.startswith(('/home/', '/root/')) else '')
    unit = '\n'.join(access if line == 'ProtectHome=yes' else line for line in agent['unit'].split('\n'))
    return {DIRECTORY / 'config.toml': agent['configuration'], DIRECTORY / 'ca.pem': agent['certificate'],
            Path('/etc/systemd/system') / UNIT: unit}, root


def publish(path, content):
    if path.is_file() and path.read_bytes() == content.encode():
        return False
    fd, candidate = tempfile.mkstemp(prefix=f'.{path.name}.', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            stream.write(content)
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(candidate, 0o644)
        os.replace(candidate, path)
    except BaseException:
        Path(candidate).unlink(missing_ok=True)
        raise
    return True


def close_environments(checkouts, root):
    for named in checkouts:
        checkout = Path(named)
        if not named.startswith(root + '/') or not checkout.is_dir() or os.path.realpath(checkout) != named:
            continue
        environment = checkout / '.env'
        try:
            mode = environment.lstat().st_mode
        except FileNotFoundError:
            continue
        if environment.is_file() and not environment.is_symlink() and mode & 0o007:
            os.chmod(environment, mode & 0o770, follow_symlinks=False)


def configure(intent, binary_changed):
    try:
        installed = SECRET.read_bytes()
    except FileNotFoundError:
        refuse('agent.secret_mismatch', 'the agent key is missing; run orbit:node-agent-rekey')
    if hashlib.sha256(installed).hexdigest() != intent['verifier_hash']:
        refuse('agent.secret_mismatch', 'the agent key does not match the recorded verifier; run orbit:node-agent-rekey')
    DIRECTORY.chmod(0o700)
    wanted, root = files(intent)
    changed = binary_changed
    for path, content in wanted.items():
        changed |= publish(path, content)
    close_environments(intent['configuration']['checkouts'], root)
    if not systemctl('daemon-reload') or not systemctl('enable', '--now', UNIT):
        refuse('node.agent_unit_failed', 'the agent unit did not start')
    if changed and not systemctl('restart', UNIT):
        refuse('node.agent_unit_failed', 'the agent did not restart')
    return {'changed': changed}


def check(intent):
    if not systemctl('is-active', '--quiet', UNIT):
        refuse('node.agent_inactive', 'the agent is not active')
    if sha256(BINARY) != intent['sha256']:
        refuse('node.agent_pin_mismatch', 'the installed binary is not the pinned release')
    wanted, _ = files(intent)
    for path, content in wanted.items():
        if not path.is_file() or path.read_bytes() != content.encode():
            refuse('node.agent_files_differ', f'{path} does not hold its content')
    return {'agent': True}


def main(action, raw, binary_changed='false'):
    intent = json.loads(raw)
    result = install(intent) if action == 'install' else configure(intent, binary_changed == 'true') if action == 'configure' else check(intent)
    print(json.dumps(result))


if __name__ == '__main__':
    main(*sys.argv[1:])
