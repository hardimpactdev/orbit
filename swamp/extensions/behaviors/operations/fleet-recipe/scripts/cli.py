import fcntl
import hashlib
import json
import os
import pwd
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import urllib.parse
import urllib.request

BIN = '/usr/local/bin'
LINK = BIN + '/orbit'
PROFILE = '/root/.orbit/config.json'
LOCK = '/run/lock/orbit-self-update.lock'

class Refusal(Exception):
    pass

def refuse(code):
    raise Refusal(code)

def release_version(value):
    return re.fullmatch('0\\.[1-9][0-9]{0,9}\\.0', value) is not None

def owned(path, directory=False):
    s = os.lstat(path)
    if s.st_uid != 0 or s.st_mode & 18 or (not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode))):
        refuse('cli.foreign_binary')
    return s

def run_cli(binary, user, args):
    account = pwd.getpwnam(user)
    if account.pw_uid == 0:
        refuse('cli.foreign_binary')
    try:
        result = subprocess.run([binary, *args], user=account.pw_uid, group=account.pw_gid, extra_groups=[], env={'HOME': account.pw_dir, 'PATH': '/usr/local/bin:/usr/bin:/bin', 'LANG': 'C.UTF-8'}, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=60)
    except (OSError, subprocess.TimeoutExpired):
        return None
    lines = result.stdout.decode(errors='replace').splitlines()
    return (lines[0] if lines else '') if result.returncode == 0 else None

def inspect(user):
    if not os.path.lexists(LINK):
        return ('missing', None)
    binary = LINK
    if os.path.islink(LINK):
        target = os.readlink(LINK)
        if not target.startswith('orbit-') or not release_version(target[6:]):
            refuse('cli.foreign_binary')
        binary = BIN + '/' + target
    try:
        mode = owned(binary).st_mode
        with open(binary, 'rb') as file:
            magic = file.read(4)
    except OSError:
        refuse('cli.foreign_binary')
    if not mode & 73 or magic != b'\x7fELF':
        refuse('cli.foreign_binary')
    line = run_cli(binary, user, ['--version'])
    if line is None or not line.startswith('Orbit '):
        refuse('cli.foreign_binary')
    version = line[6:]
    if not release_version(version) and (not re.fullmatch('[a-f0-9]{40}', version)):
        refuse('cli.foreign_binary')
    return ('present' if run_cli(binary, user, ['self-update', '--help']) is not None else 'stale', version)

def needs(state, version, release):
    if state in ('missing', 'stale'):
        return True
    return release is not None and release_version(version) and (int(version.split('.')[1]) < int(release['version'].split('.')[1]))

def temporary(directory):
    fd, path = tempfile.mkstemp(prefix='.orbit-cli-', dir=directory)
    os.close(fd)
    return path

class HttpsOnly(urllib.request.HTTPRedirectHandler):

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        if urllib.parse.urlsplit(newurl).scheme != 'https':
            refuse('cli.download_failed')
        return super().redirect_request(req, fp, code, msg, headers, newurl)

def publish(release, user):
    candidate = temporary(BIN)
    try:
        try:
            with urllib.request.build_opener(HttpsOnly()).open(release['url'], timeout=180) as response, open(candidate, 'wb') as out:
                size = 0
                digest = hashlib.sha256()
                for block in iter(lambda: response.read(1048576), b''):
                    size += len(block)
                    if size > 256 * 1024 * 1024:
                        refuse('cli.download_failed')
                    out.write(block)
                    digest.update(block)
        except Refusal:
            raise
        except Exception:
            refuse('cli.download_failed')
        if digest.hexdigest() != release['sha256']:
            refuse('cli.checksum_mismatch')
        with open(candidate, 'rb') as file:
            if file.read(4) != b'\x7fELF':
                refuse('cli.candidate_invalid')
        os.chmod(candidate, 493)
        os.chown(candidate, 0, 0)
        if run_cli(candidate, user, ['--version']) != 'Orbit ' + release['version']:
            refuse('cli.candidate_invalid')
        target = BIN + '/orbit-' + release['version']
        if os.path.lexists(target):
            owned(target)
        os.replace(candidate, target)
        if os.path.lexists(LINK) and (not os.path.islink(LINK)):
            shutil.copy2(LINK, LINK + '.orbit-previous')
        staged = temporary(BIN)
        os.unlink(staged)
        try:
            os.symlink(os.path.basename(target), staged)
            os.replace(staged, LINK)
        finally:
            if os.path.lexists(staged):
                os.unlink(staged)
    finally:
        if os.path.lexists(candidate):
            os.unlink(candidate)

def configure(profile):
    directory = os.path.dirname(PROFILE)
    os.makedirs(directory, mode=448, exist_ok=True)
    owned(directory, True)
    if os.path.isfile(PROFILE) and (not os.path.islink(PROFILE)):
        owned(PROFILE)
        with open(PROFILE) as file:
            if file.read() == profile:
                return False
    candidate = temporary(directory)
    try:
        with open(candidate, 'w') as file:
            file.write(profile)
        os.chmod(candidate, 384)
        os.replace(candidate, PROFILE)
    finally:
        if os.path.lexists(candidate):
            os.unlink(candidate)
    return True

def install(data):
    for path in ['/usr', '/usr/local', BIN]:
        owned(path, True)
    state, version = inspect(data['user'])
    installed = None
    if needs(state, version, data['release']):
        if data['release'] is None:
            refuse('cli.release_needed')
        fd = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 384)
        with os.fdopen(fd, 'a') as lock:
            if not stat.S_ISREG(os.fstat(lock.fileno()).st_mode) or os.fstat(lock.fileno()).st_uid != 0:
                refuse('cli.install_failed')
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                refuse('cli.update_busy')
            state, version = inspect(data['user'])
            if needs(state, version, data['release']):
                publish(data['release'], data['user'])
                installed = data['release']['version']
    return {'installed': installed, 'configured': configure(data['profile'])}
if __name__ == '__main__':
    try:
        print(json.dumps(install(json.load(sys.stdin))))
    except Refusal as error:
        print(json.dumps({'error_code': str(error)}))
    except Exception:
        print(json.dumps({'error_code': 'cli.install_failed'}))
