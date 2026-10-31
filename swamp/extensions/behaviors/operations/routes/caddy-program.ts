/** Fixed whole-Node filesystem transaction and service activation, executed locally with sudo. */
export const caddyProgram = String.raw`
import contextlib, datetime, fcntl, grp, hashlib, ipaddress, json, os, re, shutil, stat, subprocess, sys, time
from pathlib import Path

ROOT = Path('/etc/caddy')
LOCK = Path('/run/lock/orbit/caddy.lock')
MARKER = '# Managed by Orbit: Node Caddy build'
LIMIT = 4194304

class Refusal(Exception):
    def __init__(self, stage): self.stage = stage

def command(program, *args):
    try:
        return subprocess.run([program, *map(str, args)], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=35, check=False)
    except (OSError, subprocess.TimeoutExpired):
        raise Refusal('command')

def service(*args): return command('/usr/bin/systemctl', *args, 'caddy').returncode == 0

def digest(data): return hashlib.sha256(data).hexdigest()[:32]
def exists(path): return path.exists() or path.is_symlink()
def remove(path):
    if path.is_symlink() or path.is_file(): path.unlink()
    elif path.is_dir(): shutil.rmtree(path)
    elif exists(path): raise Refusal('filesystem')

def directory(path, mode, gid):
    path.mkdir(mode=mode, parents=True, exist_ok=True)
    if not stat.S_ISDIR(path.lstat().st_mode): raise Refusal('write')
    os.chown(path, 0, 0 if mode == 0o700 else gid)
    path.chmod(mode)

@contextlib.contextmanager
def publication_lock():
    LOCK.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    info = LOCK.parent.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != 0o700: raise Refusal('lock')
    fd = os.open(LOCK, os.O_CREAT | os.O_APPEND | os.O_NOFOLLOW | os.O_WRONLY, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_gid != 0: raise Refusal('lock')
        os.fchmod(fd, 0o600)
        deadline = time.monotonic() + 30
        while True:
            try: fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB); break
            except BlockingIOError:
                if time.monotonic() >= deadline: raise Refusal('lock')
                time.sleep(0.05)
        yield
    finally: os.close(fd)

def reserve_backup():
    backups = ROOT / 'orbit-backups'
    directory(backups, 0o700, 0)
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    for n in range(1, 101):
        path = backups / (stamp if n == 1 else stamp + '-' + str(n))
        try: path.mkdir(mode=0o700); return path
        except FileExistsError: pass
    raise Refusal('backup')

def backup(source, whole=False):
    target = reserve_backup() / (source.name if whole else 'Caddyfile')
    if command('/usr/bin/cp', '-a', '--', source, target).returncode != 0: raise Refusal('backup')

def digest_matches(path):
    if not re.fullmatch('[a-f0-9]{32}', path.name) or not (path / 'Caddyfile').is_file(): return True
    return digest((path / 'Caddyfile').read_bytes()) == path.name

def package_default(path):
    result = command('/usr/bin/dpkg-query', '-W', '-f=$' + '{Conffiles}\n', 'caddy')
    if result.returncode != 0: return False
    expected = next((parts[1] for line in result.stdout.decode().splitlines() if len(parts := line.split()) >= 2 and parts[0] == str(path)), None)
    return expected is not None and hashlib.md5(path.read_bytes()).hexdigest() == expected

def backup_live(live):
    if live.is_symlink():
        try: target = live.resolve(strict=True)
        except (OSError, RuntimeError): return
        if not target.is_file(): return
        managed = target.read_bytes().splitlines()[:1] == [MARKER.encode()]
        parent = target.parent
        if target.is_relative_to(ROOT / 'orbit-versions') and ((not managed and (parent / 'fragments').is_dir()) or (managed and not digest_matches(parent))): backup(parent, True)
        elif not managed: backup(target)
    elif live.is_file() and live.read_bytes().splitlines()[:1] != [MARKER.encode()] and not package_default(live): backup(live)

def prune(published):
    entries = [p for p in published.parent.iterdir() if p != published and not p.name.startswith('.') and (p / 'Caddyfile').is_file()]
    entries.sort(key=lambda p: p.stat().st_mtime_ns, reverse=True)
    for path in entries[9:]:
        try:
            if not digest_matches(path): backup(path, True)
            remove(path)
        except (OSError, Refusal): pass

def validate(path):
    result = command('/usr/sbin/runuser', '-u', 'caddy', '--', '/usr/bin/caddy', 'validate', '--config', path, '--adapter', 'caddyfile')
    if result.returncode != 0: raise Refusal('validate')

def publish(value):
    content, version = value['content'].encode(), value['version']
    if len(content) > LIMIT or content.splitlines()[:1] != [MARKER.encode()] or digest(content) != version: raise Refusal('input')
    live = ROOT / 'Caddyfile'
    if value['unserved'] and not Path('/usr/bin/caddy').exists() and not service('is-active', '--quiet'):
        if exists(live) and (not live.is_file() or live.read_bytes() != content): live.rename(reserve_backup() / 'Caddyfile')
        return {'result': 'unchanged'}
    release = command('/usr/bin/caddy', 'version')
    match = re.match(r'v?(\d+)\.(\d+)\.(\d+)(?:[-+\s]|$)', release.stdout.decode())
    if release.returncode != 0 or not match or tuple(map(int, match.groups())) < (2, 9, 0): raise Refusal('release')
    addresses = command('/usr/sbin/ip', '-o', '-4', 'addr', 'show')
    if addresses.returncode != 0: raise Refusal('addresses')
    present = {line.split()[3].split('/')[0] for line in addresses.stdout.decode().splitlines() if len(line.split()) > 3}
    if any(str(ipaddress.IPv4Address(a)) not in present for a in value['listen_addresses']): raise Refusal('addresses')
    versions = ROOT / 'orbit-versions'
    published = versions / version
    if live.is_symlink() and live.resolve() == published / 'Caddyfile' and live.read_bytes() == content: return {'result': 'unchanged'}
    gid = grp.getgrnam('caddy').gr_gid
    candidate, replaced = versions / ('.' + version + '.candidate'), versions / ('.' + version + '.replaced')
    link, previous = ROOT / ('.Caddyfile.orbit-build-' + version), ROOT / ('.Caddyfile.orbit-previous-' + version)
    restore_failed = False
    try:
        directory(versions, 0o750, gid)
        remove(candidate); directory(candidate, 0o750, gid)
        path = candidate / 'Caddyfile'
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o640)
        with os.fdopen(fd, 'wb') as file:
            file.write(content); file.flush(); os.fchown(file.fileno(), 0, gid); os.fchmod(file.fileno(), 0o640)
        validate(path)
        backup_live(live)
        for path in [link, previous, replaced]: remove(path)
        had_live, had_published = exists(live), exists(published)
        previous_target = os.readlink(live) if live.is_symlink() else None
        if had_live and previous_target is None: shutil.copy2(live, previous)
        if had_published: published.rename(replaced)
        try:
            candidate.rename(published)
            link.symlink_to(published / 'Caddyfile'); link.replace(live)
            if not service('enable', '--quiet') or not service('reload-or-restart'): raise Refusal('reload')
        except (OSError, Refusal):
            try:
                remove(published)
                if had_published: replaced.rename(published)
                remove(link)
                if previous_target is not None: link.symlink_to(previous_target); link.replace(live)
                elif had_live: previous.replace(live)
                else: remove(live)
            except OSError:
                restore_failed = True
                raise Refusal('restore')
            if had_live: service('reload' if service('is-active', '--quiet') else 'start')
            raise Refusal('reload')
        remove(replaced); prune(published)
        return {'result': 'published'}
    finally:
        for path in [candidate, link] + ([] if restore_failed else [previous]):
            try: remove(path)
            except OSError: pass

if __name__ == '__main__':
    try:
        value = json.load(sys.stdin)
        with publication_lock(): result = publish(value)
    except Refusal as error: result = {'error_code': 'caddy.publication_invalid' if error.stage == 'input' else 'caddy.publication_failed', 'stage': error.stage}
    except (OSError, ValueError, KeyError): result = {'error_code': 'caddy.publication_failed'}
    print(json.dumps(result, separators=(',', ':')))
`;
