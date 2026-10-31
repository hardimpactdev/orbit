import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

export type PiCommand =
  | "pi.receive-artifact"
  | "pi.install-artifact"
  | "pi.inspect-artifact"
  | "pi.configure-runtime";

/** Code-owned Pi files. The caller supplies an admitted local or first-visit guest transport. */
export async function piNative(
  name: PiCommand,
  input: Record<string, unknown>,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const runtime = name === "pi.configure-runtime";
  const failure = runtime ? "pi.runtime_failed" : "pi.artifact_failed";
  if (
    ![
      "pi.receive-artifact",
      "pi.install-artifact",
      "pi.inspect-artifact",
      "pi.configure-runtime",
    ].includes(name)
  ) throw new Error("pi.artifact_invalid");
  signal?.throwIfAborted();
  try {
    const output = await transport({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/python3", "-c", PiProgram],
      stdin: JSON.stringify({ name, input }),
      timeoutMs: 120000,
    }, signal);
    const value: unknown = JSON.parse(output.stdout);
    if (!value || typeof value !== "object" || Array.isArray(value)) {
      throw new Error(failure);
    }
    const result = value as Record<string, unknown>;
    if (!output.success || result.error_code !== undefined) {
      const code = result.error_code;
      if (
        code !== "pi.artifact_invalid" && code !== "pi.artifact_failed" &&
        code !== "pi.runtime_invalid" && code !== "pi.runtime_failed"
      ) throw new Error(failure);
      throw new Error(code);
    }
    return result;
  } catch (error) {
    if (
      error instanceof Error &&
      /^pi\.(artifact|runtime)_(invalid|failed)$/.test(error.message)
    ) throw error;
    throw new Error(failure);
  }
}

export const PiProgram = String.raw`
import base64, hashlib, json, os, pathlib, pwd, re, secrets, stat, sys, urllib.parse
MAX_ARTIFACT = 268435456
MAX_CHUNK = 2097152
CACHE = pathlib.Path('/var/cache/orbit/pi')
class Refusal(Exception):
    pass

def refuse(code):
    raise Refusal(code)

def metadata(path, uid, directory=False, writable=False):
    try:
        info = path.lstat()
    except FileNotFoundError:
        return None
    if info.st_uid != uid or not (stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)):
        refuse('pi.artifact_failed')
    if not writable and stat.S_IMODE(info.st_mode) & 0o022:
        refuse('pi.artifact_failed')
    return info

def account(user):
    if not isinstance(user, str) or not re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', user) or user in ('root', 'orbit-swamp-package'):
        refuse('pi.artifact_invalid')
    who = pwd.getpwnam(user)
    home = pathlib.Path(who.pw_dir)
    if not home.is_absolute() or '..' in home.parts:
        refuse('pi.artifact_failed')
    if metadata(home, who.pw_uid, True) is None:
        refuse('pi.artifact_failed')
    return who, home

def safe_runtime(home, target, uid):
    for path in list(target.parents)[:len(target.parts)-len(home.parts)] + [target]:
        if path != home:
            metadata(path, uid, path != target)

def pin(value):
    if not isinstance(value, str) or not re.fullmatch('[a-f0-9]{64}', value):
        refuse('pi.artifact_invalid')
    return value

def digest_fd(fd):
    os.lseek(fd, 0, os.SEEK_SET)
    result = hashlib.sha256()
    total = 0
    while True:
        block = os.read(fd, 1048576)
        if not block:
            break
        total += len(block)
        if total > MAX_ARTIFACT:
            refuse('pi.artifact_invalid')
        result.update(block)
    return result.hexdigest()

def open_read(path, uid):
    if metadata(path, uid) is None:
        refuse('pi.artifact_failed')
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    info = os.fstat(fd)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or stat.S_IMODE(info.st_mode) & 0o022:
        os.close(fd)
        refuse('pi.artifact_failed')
    return fd

def cache_directory(create=False):
    owner = os.geteuid()
    for path in list(reversed(CACHE.parents)) + [CACHE]:
        try:
            info = path.lstat()
        except FileNotFoundError:
            if not create:
                continue
            path.mkdir(mode=0o700)
            info = path.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid not in (0, owner) or stat.S_IMODE(info.st_mode) & 0o022:
            refuse('pi.artifact_failed')
    if create:
        os.chmod(CACHE, 0o700)

def receive(data):
    if set(data) != {'sha256', 'offset', 'total', 'bytes'}:
        refuse('pi.artifact_invalid')
    sha = pin(data['sha256'])
    offset, total, encoded = data['offset'], data['total'], data['bytes']
    if type(offset) is not int or type(total) is not int or offset < 0 or not 0 < total <= MAX_ARTIFACT or not isinstance(encoded, str) or len(encoded) > ((MAX_CHUNK + 2) // 3) * 4:
        refuse('pi.artifact_invalid')
    try:
        content = base64.b64decode(encoded, validate=True)
    except Exception:
        refuse('pi.artifact_invalid')
    if not content or len(content) > MAX_CHUNK or offset + len(content) > total:
        refuse('pi.artifact_invalid')
    cache_directory()
    partial, ready = CACHE / (sha + '.partial'), CACHE / (sha + '.bin')
    if metadata(ready, os.geteuid()) is not None:
        fd = open_read(ready, os.geteuid())
        try:
            if os.fstat(fd).st_size != total or digest_fd(fd) != sha:
                refuse('pi.artifact_failed')
        finally:
            os.close(fd)
        return {'received_bytes': total, 'verified': True}
    exists = metadata(partial, os.geteuid()) is not None
    if not exists and offset != 0:
        refuse('pi.artifact_invalid')
    cache_directory(True)
    fd = os.open(partial, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.geteuid() or stat.S_IMODE(info.st_mode) & 0o022:
            refuse('pi.artifact_failed')
        length = info.st_size
        if length > total or offset > length:
            refuse('pi.artifact_invalid')
        overlap = min(length - offset, len(content))
        os.lseek(fd, offset, os.SEEK_SET)
        previous = b''
        while len(previous) < overlap:
            block = os.read(fd, overlap - len(previous))
            if not block:
                refuse('pi.artifact_failed')
            previous += block
        if previous != content[:overlap]:
            refuse('pi.artifact_invalid')
        pending = memoryview(content)[overlap:]
        while pending:
            written = os.write(fd, pending)
            pending = pending[written:]
        os.fsync(fd)
        received = max(length, offset + len(content))
        if received == total and digest_fd(fd) != sha:
            partial.unlink()
            refuse('pi.artifact_invalid')
    finally:
        os.close(fd)
    if received == total:
        os.replace(partial, ready)
    return {'received_bytes': received, 'verified': received == total}

def inspect(data):
    if set(data) != {'user', 'sha256'}:
        refuse('pi.artifact_invalid')
    sha = pin(data['sha256'])
    who, home = account(data['user'])
    target = home / '.local/bin/pi-server'
    safe_runtime(home, target, who.pw_uid)
    info = metadata(target, who.pw_uid)
    current = False
    if info is not None and stat.S_IMODE(info.st_mode) == 0o755:
        fd = open_read(target, who.pw_uid)
        try:
            current = digest_fd(fd) == sha
        finally:
            os.close(fd)
    return {'current': current}

def directory(path, uid, gid, mode):
    existing = metadata(path, uid, True, True)
    changed = existing is None or stat.S_IMODE(existing.st_mode) != mode or existing.st_gid != gid
    if existing is None:
        path.mkdir(mode=mode)
    os.chown(path, uid, gid)
    os.chmod(path, mode)
    return changed

def atomic(path, content, uid, gid, mode):
    candidate = path.with_name('.orbit-pi-' + secrets.token_hex(12))
    fd = os.open(candidate, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        pending = memoryview(content)
        while pending:
            written = os.write(fd, pending)
            pending = pending[written:]
        os.fchown(fd, uid, gid)
        os.fchmod(fd, mode)
        os.fsync(fd)
        os.replace(candidate, path)
    finally:
        os.close(fd)
        candidate.unlink(missing_ok=True)

def install(data):
    current = inspect(data)['current']
    who, home = account(data['user'])
    cache_directory()
    source = CACHE / (pin(data['sha256']) + '.bin')
    fd = open_read(source, os.geteuid())
    try:
        if not 0 < os.fstat(fd).st_size <= MAX_ARTIFACT or digest_fd(fd) != data['sha256']:
            refuse('pi.artifact_invalid')
        if current:
            return {'changed': False}
        os.lseek(fd, 0, os.SEEK_SET)
        content = bytearray()
        while True:
            block = os.read(fd, 1048576)
            if not block:
                break
            content.extend(block)
    finally:
        os.close(fd)
    for path in [home / '.local', home / '.local/bin']:
        directory(path, who.pw_uid, who.pw_gid, 0o755)
    atomic(home / '.local/bin/pi-server', content, who.pw_uid, who.pw_gid, 0o755)
    return {'changed': True}

def configure(data):
    if set(data) != {'user', 'origin', 'models', 'model_key', 'token'}:
        refuse('pi.runtime_invalid')
    origin = data['origin']
    if not isinstance(origin, str) or any(ord(c) < 33 or ord(c) > 126 for c in origin):
        refuse('pi.runtime_invalid')
    parsed = urllib.parse.urlsplit(origin)
    if parsed.scheme not in ('http', 'https') or not parsed.hostname or parsed.username is not None or parsed.password is not None or parsed.query or parsed.fragment or parsed.path not in ('', '/'):
        refuse('pi.runtime_invalid')
    for key in ['model_key', 'token']:
        value = data[key]
        if not isinstance(value, str) or not 32 <= len(value) <= 4096 or any(ord(c) < 33 or ord(c) > 126 for c in value):
            refuse('pi.runtime_invalid')
    models = data['models']
    if not isinstance(models, list) or not 1 <= len(models) <= 256 or any(not isinstance(v, str) or not 1 <= len(v) <= 256 or any(ord(c) < 32 or ord(c) == 127 for c in v) for v in models):
        refuse('pi.runtime_invalid')
    who, home = account(data['user'])
    parent, target = home / '.pi', home / '.pi/agent'
    model_file, token_file = target / 'models.json', target / 'orbit-token'
    for path in [parent, target]:
        metadata(path, who.pw_uid, True, True)
    for path in [model_file, token_file]:
        metadata(path, who.pw_uid, False, True)
    changed = False
    for path in [parent, target]:
        changed = directory(path, who.pw_uid, who.pw_gid, 0o700) or changed
    settings = {'providers': {'orbit-task-vm': {'baseUrl': origin.rstrip('/') + '/v1', 'api': 'openai-responses', 'apiKey': data['model_key'], 'models': [{'id': v, 'reasoning': True} for v in models]}}}
    for path, content in [(model_file, json.dumps(settings, indent=2, ensure_ascii=False).encode()), (token_file, data['token'].encode())]:
        existing = metadata(path, who.pw_uid, False, True)
        if existing is not None and stat.S_IMODE(existing.st_mode) == 0o600 and existing.st_gid == who.pw_gid:
            fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
            try:
                if os.fstat(fd).st_size == len(content) and os.read(fd, len(content) + 1) == content:
                    continue
            finally:
                os.close(fd)
        atomic(path, content, who.pw_uid, who.pw_gid, 0o600)
        changed = True
    return {'changed': changed, 'binary': str(home / '.local/bin/pi-server'), 'token_file': str(token_file)}

def run(request):
    name, data = request['name'], request['input']
    if not isinstance(data, dict):
        refuse('pi.artifact_invalid')
    actions = {'pi.receive-artifact': receive, 'pi.install-artifact': install, 'pi.inspect-artifact': inspect, 'pi.configure-runtime': configure}
    if name not in actions:
        refuse('pi.artifact_invalid')
    return actions[name](data)

if __name__ == '__main__':
    runtime = False
    try:
        value = sys.stdin.buffer.read(4194305)
        if len(value) > 4194304:
            refuse('pi.artifact_invalid')
        request = json.loads(value)
        runtime = request.get('name') == 'pi.configure-runtime'
        print(json.dumps(run(request)))
    except Exception as error:
        code = str(error) if isinstance(error, Refusal) else ('pi.runtime_failed' if runtime else 'pi.artifact_failed')
        if runtime and code.startswith('pi.artifact_'):
            code = 'pi.runtime_failed'
        print(json.dumps({'error_code': code}))
        sys.exit(1)
`;
