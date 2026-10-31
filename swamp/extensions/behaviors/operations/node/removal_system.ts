import { z } from "npm:zod@4.4.3";
import { type NetworkSystem, runNetworkSystem } from "./network_system.ts";
const Input = z.object({ node_id: z.number().int().positive() }).strict();
/** Fixed root program; no executable, path, account, credential or shell source comes from input. */
export const RemoveNodeProgram = String.raw`
import fcntl,json,os,pathlib,pwd,shutil,stat,subprocess,sys,time,tomllib
ROOT=pathlib.Path('/')
def path(value): return ROOT / value.lstrip('/')
def safe(value,directory=False):
 p=path(value)
 try: s=p.lstat()
 except FileNotFoundError: return False
 sticky_lock=value=='/run/lock' and directory and stat.S_ISDIR(s.st_mode) and bool(s.st_mode&stat.S_ISVTX)
 if stat.S_ISLNK(s.st_mode) or s.st_uid!=0 or (s.st_mode&0o022 and not sticky_lock) or (not stat.S_ISDIR(s.st_mode) if directory else not stat.S_ISREG(s.st_mode)): raise RuntimeError('unsafe local state')
 return True
def systemctl(*args,required=True):
 result=subprocess.run(['/usr/bin/systemctl',*args],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=90)
 if required and result.returncode!=0: raise RuntimeError('service cleanup failed')
def service_inactive(unit):
 result=subprocess.run(['/usr/bin/systemctl','is-active',unit],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=15)
 if result.returncode not in [3,4]: raise RuntimeError('service did not stop')
def remove_node(input):
 if set(input) not in [{'node_id'},{'node_id','retire_worker'}] or type(input['node_id']) is not int or input['node_id']<=0 or ('retire_worker' in input and input['retire_worker'] is not True): raise RuntimeError('invalid removal input')
 retiring=input.get('retire_worker') is True
 for parent in ['/run/lock','/etc','/etc/systemd','/etc/systemd/system','/usr','/usr/local','/usr/local/bin']:
  if not safe(parent,True): raise RuntimeError('missing root directory')
 lockpath=path('/run/lock/orbit-self-update.lock')
 fd=os.open(lockpath,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'r+') as held:
  lockstat=os.fstat(held.fileno())
  if lockstat.st_uid!=0 or not stat.S_ISREG(lockstat.st_mode) or lockstat.st_mode&0o022: raise RuntimeError('unsafe update lock')
  deadline=time.monotonic()+300
  while True:
   try: fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB); break
   except BlockingIOError:
    if time.monotonic()>=deadline: return {'error_code':'agent.update_busy'}
    time.sleep(.1)
  observer=safe('/etc/systemd/system/orbit-agent.service')
  worker=safe('/etc/systemd/system/orbit-node-worker.service')
  binary=safe('/usr/local/bin/orbit-agent')
  directory=safe('/etc/orbit/agent',True)
  # Stopping this worker can kill the dispatch before it acknowledges cleanup.
  # A separate native off-worker retirement phase is required for live workers.
  if worker and not retiring: return {'error_code':'node.removal_worker_shutdown_unavailable'}
  config_worker=None
  if directory:
   config=path('/etc/orbit/agent/config.toml')
   if not safe('/etc/orbit/agent/config.toml'): return {'error_code':'node.removal_node_mismatch'}
   data=tomllib.loads(config.read_text())
   config_worker=data.get('swamp_worker')
   if config_worker is not None and (not isinstance(config_worker,dict) or config_worker.get('node_id')!=input['node_id']): return {'error_code':'node.removal_node_mismatch'}
   if not retiring and config_worker is None: return {'error_code':'node.removal_node_mismatch'}
  elif observer or binary or worker: return {'error_code':'node.removal_node_mismatch'}
  cache=path('/var/lib/orbit-node-worker')
  credentials=path('/etc/orbit/swamp-worker')
  checker=path('/usr/local/libexec/orbit-node-worker-check')
  if worker:
   if not isinstance(config_worker,dict) or not isinstance(config_worker.get('user'),str) or config_worker['user']=='root': return {'error_code':'node.removal_node_mismatch'}
   account=pwd.getpwnam(config_worker['user'])
   if cache.exists() or cache.is_symlink():
    info=cache.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid!=account.pw_uid or stat.S_IMODE(info.st_mode)!=0o700: raise RuntimeError('unsafe worker cache')
   for name in ['/etc/orbit','/usr/local/libexec']:
    if not safe(name,True): raise RuntimeError('missing worker parent')
   if credentials.exists() or credentials.is_symlink():
    if not safe('/etc/orbit/swamp-worker',True) or stat.S_IMODE(credentials.lstat().st_mode)!=0o700: raise RuntimeError('unsafe worker credentials')
    for name in ['enrollment.token','server.token']:
     token=credentials/name
     if token.exists() or token.is_symlink():
      if not safe('/etc/orbit/swamp-worker/'+name) or stat.S_IMODE(token.lstat().st_mode)!=0o600: raise RuntimeError('unsafe worker credential')
   if checker.exists() or checker.is_symlink(): safe('/usr/local/libexec/orbit-node-worker-check')
  if not observer and not binary and not directory and not worker: return {'removed':True}
  if worker:
   systemctl('disable','orbit-node-worker.service')
   systemctl('stop','orbit-node-worker.service')
   service_inactive('orbit-node-worker.service')
  if observer:
   systemctl('stop','orbit-agent.service')
   systemctl('disable','orbit-agent.service')
   service_inactive('orbit-agent.service')
  for name in ['/etc/systemd/system/orbit-agent.service','/usr/local/bin/orbit-agent']:
   path(name).unlink(missing_ok=True)
  if directory: shutil.rmtree(path('/etc/orbit/agent'))
  if worker:
   path('/etc/systemd/system/orbit-node-worker.service').unlink()
   if cache.exists(): shutil.rmtree(cache)
   if credentials.exists(): shutil.rmtree(credentials)
   checker.unlink(missing_ok=True)
  systemctl('daemon-reload')
  systemctl('reset-failed','orbit-agent.service',required=False)
  return {'removed':True}
try:
 result=remove_node(json.load(sys.stdin))
 print(json.dumps(result,separators=(',',':')))
except Exception:
 sys.exit(1)
`;

/** Direct Swamp effect. Unknown local outcomes throw, preserving the Node row for recovery. */
export async function removeNodeArtifacts(
  raw: z.infer<typeof Input>,
  execute: NetworkSystem = runNetworkSystem,
  signal?: AbortSignal,
  retireWorker = false,
) {
  const input = Input.parse(raw);
  signal?.throwIfAborted();
  const reply = await execute(
    ["/usr/bin/python3", "-c", RemoveNodeProgram],
    JSON.stringify({
      ...input,
      ...(retireWorker ? { retire_worker: true } : {}),
    }),
    AbortSignal.timeout(420000),
  );
  if (!reply.success) {
    throw new Error("The direct Node removal outcome is uncertain");
  }
  let value: unknown;
  try {
    value = JSON.parse(reply.stdout);
  } catch {
    throw new Error("The direct Node removal output is uncertain");
  }
  const refusal = z.object({
    error_code: z.enum([
      "agent.update_busy",
      "node.removal_node_mismatch",
      "node.removal_worker_shutdown_unavailable",
    ]),
  }).strict().safeParse(value);
  if (refusal.success) {
    return { ready: false as const, error_code: refusal.data.error_code };
  }
  if (
    !z.object({ removed: z.literal(true) }).strict().safeParse(value).success
  ) throw new Error("The direct Node removal output is uncertain");
  return { ready: true as const };
}
