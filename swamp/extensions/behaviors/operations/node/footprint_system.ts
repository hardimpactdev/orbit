import { localSystemCommand, type SystemTransport } from "./system_command.ts";

/** Fixed Node-owned footprint programs. Public input is JSON data on stdin only. */
export function footprintSystem(invoke: SystemTransport = localSystemCommand) {
  return async (
    name: "node.publish-tmpfiles" | "node.install-private-dns-listener",
    input: unknown,
    signal?: AbortSignal,
  ): Promise<
    { changed: boolean; installed?: boolean; error_code?: string }
  > => {
    signal?.throwIfAborted();
    const result = await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "/usr/bin/python3", "-c", FootprintProgram, name],
      stdin: JSON.stringify(input),
      timeoutMs: name === "node.publish-tmpfiles" ? 30000 : 60000,
    }, signal);
    if (!result.success) {
      throw new Error("The footprint command has no confirmed outcome");
    }
    try {
      const output = JSON.parse(result.stdout);
      if (typeof output.changed !== "boolean") throw new Error();
      if (
        output.error_code !== undefined && ![
          "node.tmpfiles_invalid",
          "node.tmpfiles_failed",
          "node.private_dns_listener_invalid",
          "node.private_dns_listener_failed",
        ].includes(output.error_code)
      ) throw new Error();
      return output.error_code === undefined
        ? {
          changed: output.changed,
          ...(output.installed === true ? { installed: true } : {}),
        }
        : { changed: false, error_code: output.error_code };
    } catch {
      throw new Error("The footprint command returned an invalid outcome");
    }
  };
}

export const FootprintProgram = String.raw`
import hashlib, ipaddress, json, os, shutil, stat, subprocess, sys, tempfile
from pathlib import Path
ROOT = Path('/var/lib/orbit/private-dns/releases')
SERVICE = Path('/etc/systemd/system/orbit-private-dns.service')
SOCKET = Path('/etc/systemd/system/orbit-private-dns.socket')
TMPFILES = Path('/etc/tmpfiles.d/orbit.conf')
UID, GID = 0, 0

class Invalid(Exception): pass

def current(path, data):
    try:
        m = path.lstat()
        return stat.S_ISREG(m.st_mode) and (m.st_uid,m.st_gid,m.st_mode & 0o7777) == (UID,GID,0o644) and path.read_bytes() == data
    except FileNotFoundError: return False

def replace(path, data, mode=0o644):
    fd, candidate = tempfile.mkstemp(prefix='.orbit-footprint-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), mode)
            os.fchown(stream.fileno(), UID, GID)
        os.replace(candidate, path)
    finally:
        if os.path.exists(candidate): os.unlink(candidate)

def directory(path):
    if path == path.parent: return
    directory(path.parent)
    try: m = path.lstat()
    except FileNotFoundError:
        path.mkdir(mode=0o755)
        os.chown(path, UID, GID)
        return
    if not stat.S_ISDIR(m.st_mode) or (m.st_uid,m.st_gid) != (UID,GID): raise Invalid()

def command(*args):
    return subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False).returncode == 0

def tmpfiles(payload):
    if not isinstance(payload,dict) or set(payload) != {'rule'}: raise Invalid()
    rule = payload['rule']
    if not isinstance(rule,str) or not rule or '\0' in rule or any(line and not line.startswith(('#','e /tmp/orbit-','e /dev/shm/orbit-')) for line in rule.splitlines()): raise Invalid()
    data = rule.encode()
    if current(TMPFILES,data): return {'changed':False}
    replace(TMPFILES,data)
    return {'changed':True}

def units(release, address):
    service = ('[Unit]\nDescription=Orbit private DNS\n'
      'After=network-online.target wg-quick@orbit.service dnsmasq.service orbit-private-dns.socket\n'
      'Wants=network-online.target\nRequires=orbit-private-dns.socket\n\n'
      '[Service]\nType=simple\nUser=root\nWorkingDirectory='+release+'\n'
      'ExecStart="/usr/bin/php8.5" "'+release+'/serve.php" "--listen='+address+'" "--port=53" '
      '"--catalog=/var/lib/orbit/private-dns/catalog.json" "--upstream=127.0.0.55:53"\n'
      'Sockets=orbit-private-dns.socket\nKillSignal=SIGTERM\nTimeoutStopSec=5\nRestart=on-failure\nRestartSec=2\n\n'
      '[Install]\nWantedBy=multi-user.target\n')
    socket = ('[Unit]\nDescription=Orbit private DNS sockets\n\n[Socket]\nListenDatagram='+address+':53\nListenStream='+address+':53\n'
      'FreeBind=yes\nService=orbit-private-dns.service\n\n[Install]\nWantedBy=sockets.target\n')
    return service,socket

def validate(payload):
    if not isinstance(payload,dict) or set(payload) != {'release_id','files','service','socket'}: raise Invalid()
    rid, files = payload['release_id'], payload['files']
    if not isinstance(rid,str) or len(rid)!=16 or any(c not in '0123456789abcdef' for c in rid) or not isinstance(files,list) or not 1 <= len(files) <=128: raise Invalid()
    identity,manifest,previous,total = '', '', '', 0
    entry = False
    for f in files:
        if not isinstance(f,dict) or set(f) != {'path','contents','sha256'}: raise Invalid()
        path,data,digest = f['path'],f['contents'],f['sha256']
        if not all(isinstance(x,str) for x in (path,data,digest)): raise Invalid()
        total += len(data.encode())
        if total > 3*1024*1024 or path <= previous or '\0' in path or not (path=='serve.php' or path.startswith('app/')) or not path.endswith('.php') or any(p in ('','..','.') for p in path.split('/')) or hashlib.sha256(data.encode()).hexdigest()!=digest: raise Invalid()
        previous = path
        entry |= path=='serve.php'
        identity += path+'\0'+digest+'\n'
        manifest += digest+'  '+path+'\n'
    if not entry or hashlib.sha256(identity.encode()).hexdigest()[:16]!=rid: raise Invalid()
    try:
        address = next(l[len('ListenDatagram='):-3] for l in payload['socket'].splitlines() if l.startswith('ListenDatagram=') and l.endswith(':53'))
        address = str(ipaddress.IPv4Address(address))
    except (ValueError, StopIteration, AttributeError): raise Invalid()
    # Unit paths are the canonical on-Node paths even in a sandbox file root.
    if units('/var/lib/orbit/private-dns/releases/'+rid,address) != (payload['service'],payload['socket']): raise Invalid()
    return manifest.encode()

def previous(path, marker):
    try: m = path.lstat()
    except FileNotFoundError: return None
    if not stat.S_ISREG(m.st_mode) or (m.st_uid,m.st_gid)!=(UID,GID): raise Invalid()
    data=path.read_bytes()
    if not data.startswith(marker): raise Invalid()
    return data,m.st_mode & 0o7777

def restore(path, before):
    try:
        if before is None: path.unlink(missing_ok=True)
        else: replace(path,*before)
        return True
    except OSError: return False

def listener(payload):
    manifest = validate(payload)
    old_service = previous(SERVICE,b'[Unit]\nDescription=Orbit private DNS\n')
    old_socket = previous(SOCKET,b'[Unit]\nDescription=Orbit private DNS sockets\n')
    directory(ROOT)
    directory(SERVICE.parent)
    release = ROOT / payload['release_id']
    try:
        m=release.lstat()
        if not stat.S_ISDIR(m.st_mode) or m.st_uid!=UID: raise Invalid()
    except FileNotFoundError: pass
    release_changed = not current(release/'.manifest',manifest) or any(not current(release/f['path'],f['contents'].encode()) for f in payload['files'])
    changed = release_changed or not current(SERVICE,payload['service'].encode()) or not current(SOCKET,payload['socket'].encode())
    staged = Path(tempfile.mkdtemp(prefix='.'+payload['release_id']+'-',dir=ROOT))
    os.chown(staged,UID,GID)
    backup=ROOT/(staged.name+'-previous')
    try:
        for f in payload['files']:
            path=staged/f['path']
            directory(path.parent)
            replace(path,f['contents'].encode())
        replace(staged/'.manifest',manifest)
        staged_service,staged_socket=staged/SERVICE.name,staged/SOCKET.name
        replace(staged_service,payload['service'].encode())
        replace(staged_socket,payload['socket'].encode())
        if not command('/usr/bin/php8.5',str(staged/'serve.php'),'--self-test') or not command('systemd-analyze','verify',str(staged_socket),str(staged_service)): raise RuntimeError()
        if not changed: return {'installed':True,'changed':False}
        active=command('systemctl','is-active','--quiet',SERVICE.name)
        staged_service.unlink()
        staged_socket.unlink()
        if release_changed:
            if release.exists(): release.rename(backup)
            try: staged.rename(release)
            except OSError:
                if backup.exists(): backup.rename(release)
                raise
        try:
            replace(SERVICE,payload['service'].encode())
            replace(SOCKET,payload['socket'].encode())
            if not command('systemctl','daemon-reload') or (active and not command('systemctl','restart',SERVICE.name)): raise RuntimeError()
        except Exception:
            restored=restore(SERVICE,old_service) & restore(SOCKET,old_socket)
            if backup.exists():
                shutil.rmtree(release)
                backup.rename(release)
            restored = command('systemctl','daemon-reload') and restored
            restored = (not active or command('systemctl','restart',SERVICE.name)) and restored
            raise RuntimeError()
        if backup.exists(): shutil.rmtree(backup)
        return {'installed':True,'changed':True}
    finally:
        if staged.exists(): shutil.rmtree(staged)

def main():
    name = sys.argv[1]
    prefix = 'node.tmpfiles' if name=='node.publish-tmpfiles' else 'node.private_dns_listener'
    try:
        payload=json.load(sys.stdin)
        result=tmpfiles(payload) if name=='node.publish-tmpfiles' else listener(payload)
    except Invalid: result={'changed':False,'error_code':prefix+'_invalid'}
    except Exception: result={'changed':False,'error_code':prefix+'_failed'}
    print(json.dumps(result))

if __name__ == '__main__': main()
`;
