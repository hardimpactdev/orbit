import { z } from "npm:zod@4.4.3";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Domain = z.string().max(253).regex(
  /^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?(?![\s\S])/,
);
const Input = z.discriminatedUnion("operation", [
  z.object({
    operation: z.literal("converge"),
    address: z.union([z.ipv4(), z.ipv6()]),
    domain: Domain,
  }).strict(),
  z.object({ operation: z.literal("remove") }).strict(),
]);
export type GatewayRoleSystemInput = z.infer<typeof Input>;
const Result = z.discriminatedUnion("completed", [
  z.object({ completed: z.literal(true), changed: z.boolean() }).strict(),
  z.object({
    completed: z.literal(false),
    error_code: z.enum([
      "vpn.configuration_invalid",
      "vpn.dns_resolver_failed",
    ]),
  }).strict(),
]);
export type GatewayRoleSystemResult = z.infer<typeof Result>;
const failure = {
  completed: false,
  error_code: "vpn.dns_resolver_failed",
} as const;

/** Gateway-role resolver policy only; managed WireGuard peers keep their own policy. */
export async function gatewayRoleSystem(
  raw: unknown,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<GatewayRoleSystemResult> {
  signal?.throwIfAborted();
  const input = Input.safeParse(raw);
  if (!input.success) {
    return { completed: false, error_code: "vpn.configuration_invalid" };
  }
  try {
    const output = await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "/usr/bin/python3", "-"],
      stdin: `${GatewayRoleProgram}\nmain(json.loads(${
        JSON.stringify(JSON.stringify(input.data))
      }))\n`,
      timeoutMs: 90000,
      maxOutputBytes: 4096,
    }, signal);
    if (!output.success || output.stdout.length > 4096) return failure;
    const result = Result.safeParse(JSON.parse(output.stdout));
    return result.success ? result.data : failure;
  } catch {
    return failure;
  }
}

/** Code-owned program. Paths, link, lock and command arguments cannot come from admission. */
export const GatewayRoleProgram = String
  .raw`import errno, fcntl, ipaddress, json, os, re, subprocess, tempfile, time
from pathlib import Path
DROP_IN = Path('/etc/systemd/system/wg-quick@orbit.service.d/orbit-gateway-dns.conf')
PEER_STATE = Path('/etc/wireguard/orbit.dns-link')
LOCK = Path('/run/lock/orbit-wireguard-peer.lock')
UID, GID = 0, 0
DEADLINE = None

def command(argv, required=True):
    left = DEADLINE - time.monotonic()
    if left <= 0: raise TimeoutError()
    result = subprocess.run(argv, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                            stderr=subprocess.DEVNULL, timeout=min(15, left))
    if required and result.returncode: raise RuntimeError()
    return result.returncode == 0

def drop_in(address, domain):
    return ('# Managed by Orbit.\n[Service]\n'
            "ExecStartPost=-/bin/sh -c 'test -e /etc/wireguard/orbit.dns-link || { "
            'resolvectl domain orbit "~' + domain + '" && resolvectl default-route orbit false && '
            "resolvectl dns orbit " + address + "; }'\n")

def live_link():
    return not PEER_STATE.exists() and command(['/usr/bin/ip','link','show','dev','orbit'], False)

def converge(data):
    content = drop_in(data['address'], data['domain']).encode()
    changed = not DROP_IN.is_file() or DROP_IN.read_bytes() != content
    if changed:
        DROP_IN.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        os.chown(DROP_IN.parent, UID, GID)
        os.chmod(DROP_IN.parent, 0o755)
        descriptor, candidate = tempfile.mkstemp(prefix='.orbit-gateway-dns.conf.', suffix='.candidate', dir=DROP_IN.parent)
        try:
            with os.fdopen(descriptor, 'wb') as output:
                os.fchown(output.fileno(), UID, GID)
                os.fchmod(output.fileno(), 0o644)
                output.write(content); output.flush(); os.fsync(output.fileno())
            os.replace(candidate, DROP_IN)
        finally:
            if os.path.lexists(candidate): os.unlink(candidate)
        command(['/usr/bin/systemctl','daemon-reload'])
    if live_link():
        command(['/usr/bin/resolvectl','domain','orbit','~' + data['domain']])
        command(['/usr/bin/resolvectl','default-route','orbit','false'])
        command(['/usr/bin/resolvectl','dns','orbit',data['address']])
    return {'completed':True, 'changed':changed}

def remove():
    if not DROP_IN.exists(): return {'completed':True, 'changed':False}
    DROP_IN.unlink()
    try: DROP_IN.parent.rmdir()
    except OSError as error:
        if error.errno not in (errno.ENOTEMPTY, errno.EEXIST): raise
    command(['/usr/bin/systemctl','daemon-reload'])
    if live_link(): command(['/usr/bin/resolvectl','revert','orbit'])
    return {'completed':True, 'changed':True}

def main(data):
    global DEADLINE
    DEADLINE = time.monotonic() + 60
    try:
        # The fixed program validates independently before opening the shared effect lock.
        if data.get('operation') == 'converge':
            ipaddress.ip_address(data['address'])
            if not isinstance(data['domain'],str) or len(data['domain']) > 253 or not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*\.?',data['domain']):
                raise ValueError()
        elif data.get('operation') != 'remove': raise ValueError()
    except (ValueError, KeyError, TypeError):
        print(json.dumps({'completed':False,'error_code':'vpn.configuration_invalid'})); return
    try:
        with LOCK.open('a') as lock:
            until = time.monotonic() + 30
            while True:
                try: fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB); break
                except BlockingIOError:
                    if time.monotonic() >= until: raise TimeoutError()
                    time.sleep(0.05)
            result = converge(data) if data['operation'] == 'converge' else remove()
        print(json.dumps(result))
    except (OSError, RuntimeError, subprocess.SubprocessError):
        print(json.dumps({'completed':False,'error_code':'vpn.dns_resolver_failed'}))
`;

const OrderingResult = z.discriminatedUnion("completed", [
  z.object({ completed: z.literal(true), changed: z.boolean() }).strict(),
  z.object({
    completed: z.literal(false),
    error_code: z.enum([
      "service.ordering_unsafe",
      "service.ordering_failed",
      "service.ordering_recovery_failed",
    ]),
  }).strict(),
]);

/** Gateway Caddy ordering is independent of whether a VPN DNS server is configured. */
export async function gatewayCaddyOrdering(
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<z.infer<typeof OrderingResult>> {
  signal?.throwIfAborted();
  let output;
  try {
    output = await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "/usr/bin/python3", "-"],
      stdin: `${GatewayCaddyOrderingProgram}\nmain()\n`,
      timeoutMs: 120000,
      maxOutputBytes: 4096,
    }, signal);
  } catch {
    throw new Error("Gateway Caddy ordering has no confirmed outcome");
  }
  if (!output.success || output.stdout.length > 4096) {
    throw new Error("Gateway Caddy ordering has no confirmed outcome");
  }
  try {
    return OrderingResult.parse(JSON.parse(output.stdout));
  } catch {
    throw new Error("Gateway Caddy ordering has no confirmed outcome");
  }
}

export const GatewayCaddyOrderingProgram = String
  .raw`import fcntl, json, os, stat, subprocess, tempfile, time
from pathlib import Path
ROOT = Path('/')
LOCK = Path('/run/lock/orbit/caddy.lock')
UID, GID = 0, 0
CONTENT = b'# Managed by Orbit.\n[Unit]\nAfter=wg-quick@orbit.service\nWants=wg-quick@orbit.service\n'
class Refused(Exception):
    def __init__(self, code='service.ordering_failed'): self.code=code

def inspect(path, directory):
    try: meta=path.lstat()
    except FileNotFoundError: return None
    if meta.st_uid!=UID or not (stat.S_ISDIR(meta.st_mode) if directory else stat.S_ISREG(meta.st_mode)):
        raise Refused('service.ordering_unsafe')
    return meta

def control(args, active=False):
    result=subprocess.run(['/usr/bin/systemctl',*args],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=15)
    if active and result.returncode in (0,3): return result.returncode==0
    if result.returncode: raise Refused()
    return True

def publish(path, content, mode, uid, gid):
    descriptor,candidate=tempfile.mkstemp(prefix='.orbit-vpn-',dir=path.parent)
    try:
        with os.fdopen(descriptor,'wb') as output:
            os.fchown(output.fileno(),uid,gid);os.fchmod(output.fileno(),mode)
            output.write(content);output.flush();os.fsync(output.fileno())
        os.replace(candidate,path)
    finally:
        if os.path.lexists(candidate):os.unlink(candidate)

def converge_ordering():
    for name in ('etc','etc/systemd','etc/systemd/system'):
        if inspect(ROOT/name,True) is None:raise Refused()
    directory=ROOT/'etc/systemd/system/caddy.service.d';path=directory/'orbit-vpn.conf'
    before_directory=inspect(directory,True);before=inspect(path,False)
    content=path.read_bytes() if before else None
    was_active=control(['is-active','--quiet','caddy'],True)
    file_changed=content!=CONTENT or before is None or before.st_gid!=GID or stat.S_IMODE(before.st_mode)!=0o644
    directory_changed=before_directory is None or before_directory.st_gid!=GID or stat.S_IMODE(before_directory.st_mode)!=0o755
    if not file_changed and not directory_changed and was_active:return {'completed':True,'changed':False}
    try:
        directory.mkdir(mode=0o755,exist_ok=True)
        if directory_changed:os.chown(directory,UID,GID);os.chmod(directory,0o755)
        if file_changed:publish(path,CONTENT,0o644,UID,GID);control(['daemon-reload'])
        if file_changed or not was_active:control(['restart','caddy'])
    except (Refused,OSError,subprocess.SubprocessError):
        try:
            if before_directory is not None:directory.mkdir(mode=0o755,exist_ok=True)
            if before is not None:publish(path,content,stat.S_IMODE(before.st_mode),before.st_uid,before.st_gid)
            elif path.exists():path.unlink()
            if before_directory is not None:
                os.chown(directory,before_directory.st_uid,before_directory.st_gid);os.chmod(directory,stat.S_IMODE(before_directory.st_mode))
            elif directory.exists():directory.rmdir()
            if file_changed:control(['daemon-reload'])
            control(['restart' if was_active else 'stop','caddy'])
        except (Refused,OSError,subprocess.SubprocessError):raise Refused('service.ordering_recovery_failed')
        raise Refused()
    return {'completed':True,'changed':True}

def main():
    try:
        LOCK.parent.mkdir(mode=0o700,exist_ok=True)
        meta=LOCK.parent.lstat()
        if not stat.S_ISDIR(meta.st_mode) or meta.st_uid!=UID or meta.st_gid!=GID or stat.S_IMODE(meta.st_mode)!=0o700:raise Refused()
        descriptor=os.open(LOCK,os.O_CREAT|os.O_APPEND|os.O_WRONLY|os.O_NOFOLLOW,0o600)
        with os.fdopen(descriptor,'a') as lock:
            meta=os.fstat(lock.fileno())
            if not stat.S_ISREG(meta.st_mode) or meta.st_uid!=UID or meta.st_gid!=GID:raise Refused()
            os.fchmod(lock.fileno(),0o600)
            until=time.monotonic()+30
            while True:
                try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);break
                except BlockingIOError:
                    if time.monotonic()>=until:raise Refused()
                    time.sleep(0.05)
            result=converge_ordering()
    except Refused as error:result={'completed':False,'error_code':error.code}
    except (OSError,subprocess.SubprocessError):result={'completed':False,'error_code':'service.ordering_failed'}
    print(json.dumps(result))
`;
