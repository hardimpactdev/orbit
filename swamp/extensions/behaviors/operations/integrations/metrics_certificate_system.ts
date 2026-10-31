import type { SystemTransport } from "../node/system_command.ts";
import { metricsTransport } from "./metrics_system.ts";
export type MetricsCertificateAction =
  | { operation: "publish"; version: string; certificate: string; key: string }
  | {
    operation: "restore";
    change: "created" | "replaced";
    previous: string | null;
  }
  | { operation: "remove" }
  | { operation: "revoke_access" };
const program = String.raw`
import fcntl,grp,json,os,pathlib,re,shutil,stat,subprocess,sys,tempfile,time,uuid
BASE=pathlib.Path('/etc/caddy');VERSIONS=BASE/'orbit-metrics-cert-versions';CURRENT=BASE/'orbit-metrics-cert-current';MARK='metrics-certificate\n'
class Refused(Exception):pass
def refuse():raise Refused()
def exists(path):return path.exists() or path.is_symlink()
def directory(path):
 if path.is_symlink() or not path.is_dir() or path.stat().st_uid!=0 or path.stat().st_mode&0o022:refuse()
def owned():
 if not exists(VERSIONS):return False
 directory(VERSIONS)
 marker=VERSIONS/'.orbit-owner'
 if marker.is_symlink() or not marker.is_file() or marker.read_text()!=MARK:refuse()
 return True
def current():
 if not exists(CURRENT):return None
 if not CURRENT.is_symlink():refuse()
 target=CURRENT.resolve(strict=True)
 if target.parent!=VERSIONS or target.is_symlink() or not target.is_dir():refuse()
 return (os.readlink(CURRENT),target)
def point(target):
 candidate=BASE/('.orbit-metrics-current.'+uuid.uuid4().hex)
 try:os.symlink(target,candidate);os.replace(candidate,CURRENT)
 finally:candidate.unlink(missing_ok=True)
def write(path,value,gid):
 fd,name=tempfile.mkstemp(prefix='.orbit-',dir=path.parent)
 try:
  with os.fdopen(fd,'wb') as f:os.fchown(f.fileno(),0,gid);os.fchmod(f.fileno(),0o640);f.write(value.encode());f.flush();os.fsync(f.fileno())
  os.replace(name,path)
 finally:pathlib.Path(name).unlink(missing_ok=True)
def ctl(action):return subprocess.run(['/usr/bin/systemctl',action,'caddy'],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=30)
def active():
 result=ctl('is-active');text=result.stdout.decode().strip()
 if result.returncode==0 and text=='active':return True
 if text in ('inactive','failed','unknown','deactivating') and result.returncode in (1,3,4):return False
 refuse()
def reload():
 if ctl('reload').returncode:refuse()
def remove_tree(path):
 if exists(path):
  if path.is_symlink() or not path.is_dir():refuse()
  shutil.rmtree(path)
def execute(v):
 directory(BASE)
 if v['operation']=='revoke_access':
  if not active():refuse()
  reload();return {'revoked':True}
 present=owned();previous=current()
 if v['operation']=='remove':
  if not present and previous is None:return {}
  if not present:refuse()
  CURRENT.unlink(missing_ok=True);remove_tree(VERSIONS);return {}
 if v['operation']=='restore':
  if not present or previous is None:refuse()
  if v['change']=='created' and v['previous'] is None:target=None
  elif v['change']=='replaced' and isinstance(v['previous'],str):
   target=pathlib.Path(v['previous'])
   if target.is_symlink():refuse()
   target=target.resolve(strict=True)
   if target.parent!=VERSIONS or not target.is_dir():refuse()
   if target==previous[1]:return {}
  else:refuse()
  running=active()
  if target is None:CURRENT.unlink()
  else:point(target)
  try:
   if running:reload()
  except Refused:
   point(previous[0]);ctl('reload');raise
  remove_tree(previous[1]);return {}
 if v['operation']!='publish' or not re.fullmatch(r'[a-fA-F0-9]{1,128}',v['version']):refuse()
 running=active();gid=grp.getgrnam('caddy').gr_gid
 if not present:
  VERSIONS.mkdir(mode=0o750);os.chown(VERSIONS,0,gid);write(VERSIONS/'.orbit-owner',MARK,gid)
 if previous and (previous[1]/'metrics.pem').read_text()==v['certificate'] and (previous[1]/'metrics.key').read_text()==v['key']:return {'change':'unchanged','previous':None}
 target=VERSIONS/v['version']
 if previous and previous[1]==target:refuse()
 remove_tree(target);candidate=VERSIONS/(v['version']+'.candidate');remove_tree(candidate)
 candidate.mkdir(mode=0o750);os.chown(candidate,0,gid)
 try:
  write(candidate/'metrics.pem',v['certificate'],gid);write(candidate/'metrics.key',v['key'],gid)
  cert=subprocess.run(['/usr/bin/openssl','x509','-in',str(candidate/'metrics.pem'),'-pubkey','-noout'],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=20)
  key=subprocess.run(['/usr/bin/openssl','pkey','-in',str(candidate/'metrics.key'),'-pubout'],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=20)
  if cert.returncode or key.returncode or cert.stdout!=key.stdout:refuse()
  os.rename(candidate,target)
 except Exception:
  remove_tree(candidate);raise
 point(target)
 try:
  if running:reload()
 except Refused:
  if previous:point(previous[0])
  else:CURRENT.unlink(missing_ok=True)
  ctl('reload');remove_tree(target);raise
 return {'change':'replaced' if previous else 'created','previous':previous[0] if previous else None}
try:
 v=json.load(sys.stdin);lockpath=pathlib.Path('/run/lock/orbit/caddy.lock');lockpath.parent.mkdir(mode=0o700,parents=True,exist_ok=True);directory(lockpath.parent)
 fd=os.open(lockpath,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'w') as lock:
  if os.fstat(lock.fileno()).st_uid!=0:refuse()
  deadline=time.monotonic()+30
  while True:
   try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB);break
   except BlockingIOError:
    if time.monotonic()>=deadline:refuse()
    time.sleep(0.1)
  print(json.dumps(execute(v)))
except Refused:print(json.dumps({'error_code':'metrics.certificate_failed'}))
except Exception:print(json.dumps({'unknown':True}))
`;
/** Call only inside the owning private-material callback on the placed certificate Node. */
export function metricsCertificateSystem(
  transport: SystemTransport = metricsTransport,
) {
  return async (
    action: MetricsCertificateAction,
    signal?: AbortSignal,
  ): Promise<{ change?: string; previous?: string | null; revoked?: true }> => {
    if (
      action.operation === "publish" &&
      (!/^[a-fA-F0-9]{1,128}$/.test(action.version) || !action.certificate ||
        !action.key)
    ) throw new Error("metrics.certificate_failed");
    let response;
    try {
      response = await transport({
        command: "/usr/bin/sudo",
        args: ["-n", "--", "/usr/bin/python3", "-c", program],
        stdin: JSON.stringify(action),
        timeoutMs: 120000,
      }, signal);
    } catch {
      throw new Error("metrics.certificate_outcome_unknown");
    }
    if (!response.success) {
      throw new Error("metrics.certificate_outcome_unknown");
    }
    let result;
    try {
      result = JSON.parse(response.stdout);
    } catch {
      throw new Error("metrics.certificate_outcome_unknown");
    }
    if (!result || typeof result !== "object") {
      throw new Error("metrics.certificate_outcome_unknown");
    }
    if (result.error_code) throw new Error("metrics.certificate_failed");
    if (result.unknown) {
      throw new Error("metrics.certificate_outcome_unknown");
    }
    if (action.operation === "revoke_access") {
      if (result.revoked !== true) {
        throw new Error("metrics.certificate_outcome_unknown");
      }
      return { revoked: true };
    }
    if (action.operation !== "publish") return {};
    if (
      !["created", "replaced", "unchanged"].includes(result.change) ||
      (result.previous !== null && typeof result.previous !== "string")
    ) throw new Error("metrics.certificate_outcome_unknown");
    return { change: result.change, previous: result.previous };
  };
}

/** Close existing Metrics streams after access intent changes, under the canonical Caddy lock. */
export function revokeMetricsAccess(
  transport: SystemTransport = metricsTransport,
) {
  return (signal?: AbortSignal) =>
    metricsCertificateSystem(transport)({ operation: "revoke_access" }, signal);
}
