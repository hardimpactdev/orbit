// Fixed local program owned by Swamp; all material enters through stdin, never argv or logs.
export const productionRuntimeProgram = String.raw`"""Production FPM effects owned by Swamp. Input is private stdin; diagnostics are bounded codes."""
import contextlib, fcntl, grp, hashlib, json, os, pathlib, pwd, re, shutil, socket, stat, struct, subprocess, sys, tempfile, time
P = pathlib.Path
PENDING = '.runtime-generation.pending'
APPLIED = '.runtime-generation.applied'
METRICS_PENDING = '.metrics-pool.pending'
METRICS_BACKUP = '.metrics-pool.backup'
GENERATED = ('php-fpm.conf', 'pool.conf', 'master.ini', 'local.sha256')
class Refused(Exception):
    def __init__(self, check, code='php.runtime_invalid'):
        self.check, self.code = check, code
        super().__init__(code)
def check(value, reason):
    if not value: raise Refused(reason)
def exists(p): return os.path.lexists(p)
def regular(p): return exists(p) and stat.S_ISREG(p.lstat().st_mode)
def directory(p): return exists(p) and stat.S_ISDIR(p.lstat().st_mode)
def owned(p, uid, gid, mode):
    try:
        m = p.lstat()
        return m.st_uid == uid and m.st_gid == gid and stat.S_IMODE(m.st_mode) == mode
    except OSError: return False
def digest(v): return hashlib.sha256(v).hexdigest()
def file_hash(p):
    try: return digest(p.read_bytes())
    except OSError: return None
def sync(p):
    fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)
def publish(parent, p, value, mode):
    fd, candidate = tempfile.mkstemp(prefix='.orbit.', suffix='.candidate', dir=parent)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(value if isinstance(value, bytes) else value.encode())
            output.flush(); os.fsync(output.fileno()); os.fchmod(output.fileno(), mode)
        os.replace(candidate, p)
    finally:
        if os.path.lexists(candidate): os.unlink(candidate)
def unlink(p):
    if exists(p): p.unlink()
def remove_tree(p):
    if exists(p):
        check(directory(p), 'runtime directory is real')
        shutil.rmtree(p)
def inside(p, base): return p != base and p.is_relative_to(base)
class Host:
    def __init__(self):
        self.homes, self.etc, self.units = P('/home'), P('/etc/orbit'), P('/etc/systemd/system')
        self.sockets, self.proc, self.sbin = P('/run/php'), P('/proc'), P('/usr/sbin')
        self.php_conf, self.tmp, self.locks = P('/etc/php'), P('/tmp'), P('/run/lock')
        self.systemctl = '/usr/bin/systemctl'
        self.root_uid = self.root_gid = 0
        self.caddy_gid = grp.getgrnam('caddy').gr_gid
    def ctl(self, *args):
        return subprocess.run([self.systemctl, *args], stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=90)
    def ok(self, *args): return self.ctl(*args).returncode == 0
    def active(self, service): return self.ok('is-active', '--quiet', service)
    def pid(self, service):
        out = self.ctl('show', '--property', 'MainPID', '--value', service)
        value = out.stdout.strip()
        return int(value) if out.returncode == 0 and value.isdigit() else 0
    def fpm(self, version): return self.sbin / ('php-fpm' + version)
    def start(self, pid):
        try: return int((self.proc / str(pid) / 'stat').read_text().rsplit(') ', 1)[1].split()[19])
        except (OSError, ValueError, IndexError): return None
    def runs_fpm(self, pid, version):
        try: return pid > 1 and (self.proc / str(pid) / 'exe').resolve(strict=True) == self.fpm(version)
        except OSError: return False
    def socket(self, p, uid):
        try: return stat.S_ISSOCK(p.lstat().st_mode) and owned(p, uid, self.caddy_gid, 0o660)
        except OSError: return False
    def root(self, p, mode): return owned(p, self.root_uid, self.root_gid, mode)
    def account(self, user): return pwd.getpwnam(user)
class Runtime:
    def __init__(self, h, data, home=False):
        self.user, self.version = data['user'], data['version']
        check(re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', self.user), 'valid account')
        check(re.fullmatch(r'[0-9]+\.[0-9]+', self.version), 'valid PHP version')
        self.home = h.homes / self.user
        self.service = 'orbit-' + self.user + '-php' + self.version + '-fpm.service'
        self.directory = h.etc / 'php-fpm' / self.user
        self.generated = self.directory / 'generated'
        self.local, self.marker = self.directory / 'local.conf', self.directory / 'orbit.identity'
        self.unit, self.socket = h.units / self.service, h.sockets / (self.user + '.sock')
        self.expected = data['marker'].encode()
        self.uid = 0
        if home:
            a = h.account(self.user)
            check(P(data['home']) == self.home and directory(self.home), 'canonical account home')
            m = self.home.stat()
            check((m.st_uid, m.st_gid) == (a.pw_uid, a.pw_gid), 'account owns home')
            self.uid = a.pw_uid
    def matches(self, h): return regular(self.marker) and h.root(self.marker, 0o644) and self.marker.read_bytes() == self.expected
    def receipt(self, name): return self.directory / name
@contextlib.contextmanager
def runtime_lock(h, user):
    check(directory(h.locks), 'lock directory exists')
    fd = os.open(h.locks / ('orbit-php-' + user + '.lock'), os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        m = os.fstat(fd)
        check(stat.S_ISREG(m.st_mode) and m.st_uid == h.root_uid and m.st_gid == h.root_gid and stat.S_IMODE(m.st_mode) == 0o600, 'trusted runtime lock')
        fcntl.flock(fd, fcntl.LOCK_EX)
        yield
    finally: os.close(fd)
def guard(h, p): return not exists(p) or (regular(p) and h.root(p, 0o600))
def pool_generation(pool):
    return digest(b''.join(line + b'\n' for line in pool.split(b'\n') if line.startswith((b'chdir = ', b'; Orbit application release: '))))
def master_token(h, r):
    pid = h.pid(r.service)
    try: boot = (h.proc / 'sys/kernel/random/boot_id').read_text().rstrip('\n')
    except OSError: return None
    started = h.start(pid)
    if pid <= 1 or not re.fullmatch(r'[a-fA-F0-9-]{36}', boot) or started is None or h.pid(r.service) != pid: return None
    return boot, pid, started
def generation_applied(h, r, pool):
    p = r.receipt(APPLIED)
    if exists(r.receipt(PENDING)) or not guard(h, p) or not regular(p): return False
    token = master_token(h, r)
    if token is None: return False
    lines = p.read_text().rstrip('\n').split('\n')
    if len(lines) != 4 or lines[0] != pool_generation(pool): return False
    boot, pid, started = token
    if lines[1:] == [boot, str(pid), str(started)]: return True
    return bool(re.fullmatch(r'[a-fA-F0-9-]{36}', lines[1]) and lines[3].isdigit() and (lines[1] != boot or started > int(lines[3])))
def receipt(h, r, name, text):
    p = r.receipt(name)
    check(guard(h, p), 'trusted generation receipt')
    publish(r.directory, p, text + '\n', 0o600); sync(r.directory)
def confirm(h, r, pool):
    token = master_token(h, r)
    check(token is not None, 'master is running')
    receipt(h, r, APPLIED, pool_generation(pool) + '\n' + '\n'.join(map(str, token)))
    unlink(r.receipt(PENDING)); sync(r.directory)
    check(generation_applied(h, r, pool), 'generation applied')
def select_pool(data):
    home, app, initial = map(P, (data['home'], data['application'], data['initial_application']))
    current, releases = home / 'current', home / 'releases'
    if app != initial:
        if exists(current):
            check(current.is_symlink(), 'current is link')
            selected = current.resolve(strict=True)
            check(inside(selected, releases) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,127}', str(selected.relative_to(releases))), 'current names release')
            resolved = app.resolve(strict=True)
            check(app.is_relative_to(current) and resolved == selected / app.relative_to(current), 'application in current')
            chosen, pool = app, data['configuration']['pool']
        else:
            resolved = initial.resolve(strict=True)
            check(resolved == initial, 'initial application is canonical')
            chosen, pool = initial, data['configuration']['initial_pool']
    else:
        resolved = app.resolve(strict=True)
        check(resolved == app, 'application is canonical')
        chosen, pool = app, data['configuration']['pool']
    check(chosen.is_dir(), 'application exists')
    return (pool + '\n; Orbit application release: ' + str(resolved) + '\n').encode()
def served(pool, key): return [line[len(key) + 3:] for line in pool.splitlines() if line.startswith(key + ' = ')][1:]
def tuning_allowed(local, pool_name, monitoring):
    forbidden = {'pid','user','group','listen','listen.owner','listen.group','listen.mode','chdir','chroot','env[home]','env[user]','include'}
    if monitoring: forbidden |= {'pm.status_path', 'pm.status_listen'}
    for raw in local.splitlines():
        line = raw.lstrip()
        if not line or line.startswith((';','#')): continue
        if line.startswith('['):
            if raw.strip() != '[' + pool_name + ']': return False
            continue
        if re.split(r'\s*=', line.lower(), maxsplit=1)[0] in forbidden: return False
    return True
def validate(h, r, work, main):
    lines = []
    for line in main.split('\n'):
        if line.startswith('include = ') and line.endswith('/generated/pool.conf'): line = 'include = ' + str(work / 'pool.conf')
        if line.startswith('include = ') and line.endswith('/local.conf'): line = 'include = ' + str(work / 'local.conf')
        lines.append(line)
    candidate = work / 'php-fpm.validate.conf'; candidate.write_text('\n'.join(lines))
    env = dict(os.environ, PHP_INI_SCAN_DIR=str(h.php_conf / r.version / 'fpm/conf.d') + ':' + str(work))
    def fpm(args): return subprocess.run([str(h.fpm(r.version)), *args], env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=30)
    check(fpm(['-y', str(candidate), '-t']).returncode == 0, 'FPM configuration valid')
    info = fpm(['-i'])
    check(info.returncode == 0 and str(work / 'master.ini') in info.stdout.decode(errors='replace'), 'FPM loads master.ini')
    lines = info.stdout.decode(errors='replace').splitlines()
    for line in (work / 'master.ini').read_text().splitlines():
        if not line or line.startswith(';'): continue
        key, _, value = line.partition('='); key, value = key.rstrip(' '), value.lstrip(' ')
        if key == 'opcache.validate_timestamps' and value == '0': value = 'Off'
        check(key + ' => ' + value + ' => ' + value in lines, 'FPM applies master.ini settings')
def reload(h, r): check(h.ok('reload', r.service) and h.active(r.service), 'FPM reloads')
def journal(r):
    line = r.receipt(METRICS_PENDING).read_text().split('\n', 1)[0]
    state, _, sha = line.partition(' '); sha = sha.rstrip()
    check(state in ('active', 'stopped') and re.fullmatch(r'[a-f0-9]{64}', sha), 'valid monitoring journal')
    return state == 'active', sha
def monitoring(h, r, desired, was_active, apply):
    pool, pending, backup = r.generated / 'pool.conf', r.receipt(METRICS_PENDING), r.receipt(METRICS_BACKUP)
    for parent, prefix in ((r.directory, '.metrics-pool.'), (r.generated, '.pool.conf.')):
        for p in parent.glob(prefix + '*.candidate'): unlink(p)
    check(regular(pool), 'pool is regular')
    if exists(backup): check(regular(backup) and h.root(backup, 0o600), 'monitoring backup trusted')
    if exists(pending):
        check(regular(pending) and h.root(pending, 0o600) and regular(backup), 'monitoring journal trusted')
        running, sha = journal(r); current, old = file_hash(pool), file_hash(backup)
        check(current in (sha, old), 'pool matches monitoring journal')
        if apply and digest(desired) == old and current != old:
            publish(r.generated, pool, backup.read_bytes(), 0o644); sync(r.generated)
        if h.active(r.service): reload(h, r)
        else: check(not running, 'master remains running during monitoring recovery')
        unlink(pending); sync(r.directory); unlink(backup)
    elif exists(backup): unlink(backup)
    if not apply or pool.read_bytes() == desired: return
    publish(r.directory, backup, pool.read_bytes(), 0o600)
    publish(r.directory, pending, ('active' if was_active else 'stopped') + ' ' + digest(desired) + '\n', 0o600); sync(r.directory)
    publish(r.generated, pool, desired, 0o644); sync(r.generated)
    if was_active: reload(h, r)
    unlink(pending); sync(r.directory); unlink(backup)
def cleanup_monitoring(h, r):
    p, backup = r.receipt(METRICS_PENDING), r.receipt(METRICS_BACKUP)
    if not exists(p): return
    check(regular(p) and h.root(p, 0o600) and regular(backup) and h.root(backup, 0o600), 'monitoring recovery trusted')
    _, sha = journal(r)
    for candidate in r.generated.glob('.pool.conf.*.candidate'):
        check(regular(candidate) and h.root(candidate, 0o644) and file_hash(candidate) in (sha, file_hash(backup)), 'candidate matches journal')
        unlink(candidate)
    sync(r.generated)
def verify(h, r, pool, local_hash):
    check(h.active(r.service), 'service active')
    pid = h.pid(r.service)
    check(h.runs_fpm(pid, r.version), 'master uses exact PHP binary')
    check(h.socket(r.socket, r.uid), 'socket belongs to pool')
    for p in served(pool, 'listen'): check(h.socket(P(p), r.uid), 'served socket belongs to pool')
    check(file_hash(r.local) == local_hash and h.pid(r.service) == pid, 'local tuning and master unchanged')
def converge(data, h):
    r = Runtime(h, data)
    if data['mode'] == 'monitor' and (not regular(r.marker) or not directory(r.generated)): return {'published': False}
    r = Runtime(h, data, True)
    if exists(h.etc):
        check(directory(h.etc) and h.etc.stat().st_uid == h.root_uid and h.etc.stat().st_gid == h.root_gid, 'trusted /etc/orbit')
    else: h.etc.mkdir()
    h.etc.chmod(0o711); check(h.root(h.etc, 0o711), 'trusted /etc/orbit')
    created = not exists(r.directory)
    if created:
        parent = h.etc / 'php-fpm'
        if not exists(parent): parent.mkdir(mode=0o755)
        check(directory(parent) and h.root(parent, 0o755), 'trusted PHP directory')
        r.directory.mkdir(mode=0o755)
    check(directory(r.directory) and h.root(r.directory, 0o755), 'trusted runtime directory')
    if exists(r.marker): check(r.matches(h), 'Instance identity matches')
    else:
        check(created or (not exists(r.generated) and not exists(r.local)), 'no unowned runtime artifacts')
        check(not exists(r.unit) and not exists(r.socket), 'no unowned unit or socket')
        publish(r.directory, r.marker, r.expected, 0o644)
    c = data['configuration']
    if exists(r.local): check(regular(r.local) and h.root(r.local, 0o644), 'trusted local.conf')
    else: publish(r.directory, r.local, c['local_defaults'], 0o644)
    local = r.local.read_bytes(); local_hash = digest(local)
    pool = select_pool(data); text = pool.decode()
    for p in served(text, 'chdir'):
        p = P(p).resolve(strict=True)
        check(inside(p, r.home / 'releases') and p.is_dir(), 'served directory in release')
    check(tuning_allowed(local.decode(), 'orbit-' + r.user, 'pm.status_path = /orbit-fpm-status' in text.splitlines()), 'local.conf only tunes pool')
    with tempfile.TemporaryDirectory(prefix='.orbit-php-runtime.', dir=h.tmp) as tmp:
        work = P(tmp)
        desired = {'php-fpm.conf': c['main'].encode(), 'pool.conf': pool, 'master.ini': c['master_ini'].encode(), 'local.sha256': (local_hash + '\n').encode()}
        for name, value in {**desired, 'local.conf': local}.items(): (work / name).write_bytes(value)
        validate(h, r, work, c['main'])
        generated_before = unit_before = None
        if exists(r.generated):
            check(directory(r.generated) and h.root(r.generated, 0o755), 'trusted generated directory')
            cleanup_monitoring(h, r)
            check(all(p.name in GENERATED for p in r.generated.iterdir()), 'only generated files')
            for name in ('php-fpm.conf', 'pool.conf', 'local.sha256'):
                check(regular(r.generated / name) and h.root(r.generated / name, 0o644), 'trusted generated file')
            if exists(r.generated / 'master.ini'): check(regular(r.generated / 'master.ini') and h.root(r.generated / 'master.ini', 0o644), 'trusted master.ini')
            generated_before = work / 'generated.backup'; shutil.copytree(r.generated, generated_before)
        if exists(r.unit):
            check(regular(r.unit) and h.root(r.unit, 0o644), 'trusted unit')
            unit_before = work / 'unit.backup'; shutil.copy2(r.unit, unit_before)
        active, enabled = h.active(r.service), h.ok('is-enabled', '--quiet', r.service)
        if data['mode'] == 'converge' and exists(r.receipt(METRICS_PENDING)): monitoring(h, r, pool, active, False)
        changed = any(file_hash(r.generated / name) != digest(value) for name, value in desired.items()) or file_hash(r.unit) != digest(c['unit'].encode())
        check(guard(h, r.receipt(PENDING)) and guard(h, r.receipt(APPLIED)), 'trusted runtime receipts')
        changed = changed or not generation_applied(h, r, pool)
        if data['mode'] == 'monitor':
            check(not exists(r.receipt(PENDING)), 'no generation pending')
            if active: check(generation_applied(h, r, pool), 'master loaded generation')
            monitoring(h, r, pool, active, True)
            if active: confirm(h, r, pool)
            return {'published': False}
        if active and not changed:
            check(h.ok('enable', r.service), 'service enabled')
            verify(h, r, text, local_hash)
            check(generation_applied(h, r, pool), 'master loaded generation')
            return {'published': False}
        receipt(h, r, PENDING, pool_generation(pool))
        try:
            if not exists(r.generated): r.generated.mkdir(mode=0o755)
            for name, value in desired.items(): publish(r.generated, r.generated / name, value, 0o644)
            publish(h.units, r.unit, c['unit'], 0o644); sync(r.generated); sync(h.units)
            check(h.ok('daemon-reload'), 'systemd reloads')
            if active:
                check(h.ok('enable', r.service) and h.ok('restart', r.service), 'service restarts')
            else: check(h.ok('enable', '--now', r.service), 'service starts')
            verify(h, r, text, local_hash); confirm(h, r, pool)
        except Exception:
            h.ok('disable', '--now', r.service)
            remove_tree(r.generated)
            if generated_before: shutil.copytree(generated_before, r.generated)
            unlink(r.unit)
            if unit_before: shutil.copy2(unit_before, r.unit)
            h.ok('daemon-reload')
            if enabled: h.ok('enable', r.service)
            if active: h.ok('start', r.service)
            raise
    return {'published': True}
def inspect(data, h):
    r = Runtime(h, data)
    def read(p):
        for parent in (p, *p.parents):
            m = parent.lstat()
            check(not stat.S_ISLNK(m.st_mode) and m.st_uid == h.root_uid and not m.st_mode & 0o022, 'runtime path root-owned')
            if parent == h.etc.parent: break
        return p.read_text()
    check(read(r.marker).encode() == r.expected, 'Instance identity matches')
    main, pool, local = read(r.generated / 'php-fpm.conf'), read(r.generated / 'pool.conf'), read(r.local)
    return {'pool': pool, 'fingerprint': digest((main + pool + local).encode())}
def remove(data, h):
    r = Runtime(h, data)
    if not exists(r.marker):
        check(not any(exists(p) for p in (r.generated, r.unit, r.socket)), 'no unowned runtime artifacts')
        return {'removed': False}
    check(r.matches(h), 'Instance identity matches')
    removed = any(exists(p) for p in (r.generated, r.unit, r.socket)) or h.active(r.service)
    if not h.ok('disable', '--now', r.service):
        check(not exists(r.unit), 'service stops'); h.ok('stop', r.service)
    check(not h.active(r.service) and h.pid(r.service) == 0, 'service stopped with no master')
    for name in (METRICS_PENDING, METRICS_BACKUP, PENDING, APPLIED):
        p = r.receipt(name)
        if exists(p):
            check(regular(p) and h.root(p, 0o600), 'trusted runtime receipt'); unlink(p)
    remove_tree(r.generated); unlink(r.unit)
    check(h.ok('daemon-reload'), 'systemd reloads'); h.ok('reset-failed', r.service)
    if exists(r.socket):
        a = h.account(r.user); m = r.socket.lstat()
        check(stat.S_ISSOCK(m.st_mode) and m.st_uid == a.pw_uid and m.st_gid == h.caddy_gid, 'socket belongs to pool')
        unlink(r.socket)
    check(not exists(r.local) or regular(r.local), 'local.conf remains regular')
    check(directory(r.directory), 'runtime identity remains')
    return {'removed': removed}

PROBE = "<?php\n\ndeclare(strict_types=1);\n\nheader('Content-Type: application/json');\n\n$status = opcache_get_status(false);\n$statistics = is_array($status) ? ($status['opcache_statistics'] ?? null) : null;\n$processStatus = file_get_contents('/proc/self/status');\n\nif (! is_array($status) || ! is_array($statistics)) {\n    echo json_encode(['error' => 'opcache-unavailable'], JSON_THROW_ON_ERROR);\n    return;\n}\n\nif (! is_string($processStatus)) {\n    echo json_encode(['error' => 'identity-unavailable'], JSON_THROW_ON_ERROR);\n    return;\n}\n\nif (preg_match('/^Uid:\\s+([0-9]+)/m', $processStatus, $uidMatch) !== 1) {\n    echo json_encode(['error' => 'identity-unavailable'], JSON_THROW_ON_ERROR);\n    return;\n}\n\n$identity = [\n    'sapi' => PHP_SAPI,\n    'version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,\n    'uid' => (int) $uidMatch[1],\n    'worker_pid' => getmypid(),\n    'parent_pid' => posix_getppid(),\n];\n$observation = [\n    'opcache_enabled' => $status['opcache_enabled'] ?? null,\n    'restart_pending' => $status['restart_pending'] ?? null,\n    'restart_in_progress' => $status['restart_in_progress'] ?? null,\n    'manual_restarts' => $statistics['manual_restarts'] ?? null,\n    'last_restart_time' => $statistics['last_restart_time'] ?? null,\n];\n\nif (($_SERVER['ORBIT_OPCACHE_ACTION'] ?? '') === 'reset') {\n    echo json_encode([\n        'identity' => $identity,\n        'observation' => $observation,\n        'accepted' => opcache_reset(),\n    ], JSON_THROW_ON_ERROR);\n    return;\n}\n\necho json_encode([\n    'identity' => $identity,\n    'observation' => $observation,\n], JSON_THROW_ON_ERROR);\n"
def cache_error(code): raise Refused('cache verification', 'php.' + code)
def fcgi_record(kind, content):
    padding = (-len(content)) % 8
    return struct.pack('!BBHHBB', 1, kind, 1, len(content), padding, 0) + content + bytes(padding)
def fcgi_parameters(values):
    def length(n): return bytes([n]) if n < 128 else struct.pack('!I', n | 0x80000000)
    return b''.join(length(len(k.encode())) + length(len(v.encode())) + k.encode() + v.encode() for k,v in values.items())
def fcgi(r, probe, action, deadline, accepted):
    def late(): cache_error('cache_reset_pending' if accepted else 'cache_protocol_failed')
    values = {'GATEWAY_INTERFACE':'CGI/1.1', 'REQUEST_METHOD':'GET', 'SCRIPT_FILENAME':str(probe), 'SCRIPT_NAME':'/.orbit-opcache-refresh.php', 'QUERY_STRING':'', 'REQUEST_URI':'/.orbit-opcache-refresh.php', 'DOCUMENT_ROOT':str(r.home), 'SERVER_PROTOCOL':'HTTP/1.1', 'SERVER_NAME':'localhost', 'SERVER_PORT':'80', 'REMOTE_ADDR':'127.0.0.1', 'REMOTE_PORT':'1', 'SERVER_SOFTWARE':'Orbit', 'REDIRECT_STATUS':'200', 'ORBIT_OPCACHE_ACTION':action}
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        try:
            remaining = deadline - time.monotonic()
            if remaining <= 0: late()
            conn.settimeout(remaining)
            try: conn.connect(str(r.socket))
            except (FileNotFoundError, ConnectionRefusedError, PermissionError): cache_error('cache_socket_unavailable')
            conn.sendall(fcgi_record(1, b'\x00\x01' + bytes(6)) + fcgi_record(4, fcgi_parameters(values)) + fcgi_record(4,b'') + fcgi_record(5,b''))
            def exact(n):
                data = b''
                while len(data) < n:
                    remaining = deadline - time.monotonic()
                    if remaining <= 0: late()
                    conn.settimeout(remaining)
                    piece = conn.recv(n - len(data))
                    if not piece: cache_error('cache_protocol_failed')
                    data += piece
                return data
            output, errors = b'', b''
            while True:
                version, kind, request, size, padding, _ = struct.unpack('!BBHHBB', exact(8))
                if version != 1 or request != 1: cache_error('cache_protocol_failed')
                content = exact(size); exact(padding)
                if kind == 6: output += content
                if kind == 7: errors += content
                if len(output) + len(errors) > 65536: cache_error('cache_protocol_failed')
                if kind == 3:
                    if size != 8 or content[:5] != bytes(5): cache_error('cache_protocol_failed')
                    break
            if errors or b'\r\n\r\n' not in output: cache_error('cache_protocol_failed')
            headers, body = output.split(b'\r\n\r\n',1)
            lines = headers.decode().split('\r\n')
            if 'content-type: application/json' not in [x.lower() for x in lines]: cache_error('cache_protocol_failed')
            statuses = [x for x in lines if x.lower().startswith('status:')]
            if statuses and statuses != ['Status: 200 OK']: cache_error('cache_protocol_failed')
            result = json.loads(body)
            if not isinstance(result,dict): cache_error('cache_protocol_failed')
            return result
        except TimeoutError: late()
        except (OSError, ValueError, UnicodeError): cache_error('cache_protocol_failed')
def observation(result, r, master, accepted):
    if result == {'error':'opcache-unavailable'}: cache_error('cache_reset_rejected')
    if result == {'error':'identity-unavailable'}: cache_error('cache_association_invalid')
    identity, state = result.get('identity'), result.get('observation')
    if not isinstance(identity,dict) or not isinstance(state,dict): cache_error('cache_protocol_failed')
    worker = identity.get('worker_pid')
    expected = {'sapi':'fpm-fcgi', 'version':r.version, 'uid':r.uid, 'worker_pid':worker, 'parent_pid':master}
    if identity != expected or type(worker) is not int or worker <= 1 or type(identity.get('uid')) is not int or type(identity.get('parent_pid')) is not int: cache_error('cache_association_invalid')
    flags, counts = ('opcache_enabled','restart_pending','restart_in_progress'), ('manual_restarts','last_restart_time')
    if set(state) != set(flags + counts) or any(type(state[k]) is not bool for k in flags) or any(type(state[k]) is not int for k in counts): cache_error('cache_protocol_failed')
    if not state['opcache_enabled'] and not (accepted and (state['restart_pending'] or state['restart_in_progress'])): cache_error('cache_reset_rejected')
    return state
def cache_master(h, r):
    if not h.active(r.service): cache_error('cache_association_invalid')
    pid = h.pid(r.service); started = h.start(pid)
    if not h.runs_fpm(pid,r.version) or started is None: cache_error('cache_association_invalid')
    return pid, started
def refresh(data, h):
    r = Runtime(h,data,True)
    check(type(data['deadline_seconds']) is int and 1 <= data['deadline_seconds'] <= 30, 'deadline bounded')
    if not r.matches(h): cache_error('cache_association_invalid')
    if not exists(r.socket) or not stat.S_ISSOCK(r.socket.lstat().st_mode): cache_error('cache_socket_unavailable')
    if not h.socket(r.socket, r.uid): cache_error('cache_association_invalid')
    master, started = cache_master(h,r)
    try: os.kill(master,0)
    except OSError: cache_error('cache_association_invalid')
    check(directory(h.tmp) and h.root(h.tmp,0o1777), 'trusted probe parent')
    with tempfile.TemporaryDirectory(prefix='.orbit-opcache-refresh-' + r.user + '.',dir=h.tmp) as temp:
        temp = P(temp); temp.chmod(0o711)
        probe = temp / 'probe.php'; publish(temp,probe,PROBE,0o444)
        deadline = time.monotonic() + data['deadline_seconds']
        before = observation(fcgi(r,probe,'status',deadline,False),r,master,False)
        result = fcgi(r,probe,'reset',deadline,False)
        if observation(result,r,master,False) != before: cache_error('cache_protocol_failed')
        if result.get('accepted') is not True: cache_error('cache_reset_rejected')
        while True:
            after = observation(fcgi(r,probe,'status',deadline,True),r,master,True)
            advanced = after['manual_restarts'] > before['manual_restarts'] or after['last_restart_time'] > before['last_restart_time']
            if advanced and not after['restart_pending'] and not after['restart_in_progress']: break
            if time.monotonic() >= deadline: cache_error('cache_reset_pending')
            time.sleep(min(0.05,max(0,deadline-time.monotonic())))
        if cache_master(h,r) != (master,started) or not h.socket(r.socket,r.uid): cache_error('cache_association_invalid')
    return {'complete':True}
def main():
    try:
        envelope = json.load(sys.stdin)
        action, data = envelope['action'], envelope['input']
        check(action in ('converge','inspect','remove','refresh'), 'known action')
        h = Host(); r = Runtime(h,data)
        if action == 'inspect': result = inspect(data,h)
        else:
            with runtime_lock(h,r.user): result = {'converge':converge,'remove':remove,'refresh':refresh}[action](data,h)
        print(json.dumps(result,separators=(',',':')))
    except Refused as error:
        print(json.dumps({'error_code':error.code},separators=(',',':')))
    except Exception:
        print('{"error_code":"php.runtime_execution_failed"}')
if __name__ == '__main__': main()
`;
