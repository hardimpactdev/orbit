import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

export type WebSocketRuntimeInput = {
  user: string;
  group: string;
  install_path: string;
  repository: string;
  ref: string;
  port: number;
  environment: string;
  unit: string;
};
export type WebSocketRemovalInput = { install_path: string; purge: boolean };
export class WebSocketRuntimeError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}

// Code-owned local programs only. Private configuration arrives on stdin, never argv/output.
const program = String.raw`
import fcntl,grp,json,os,pathlib,pwd,re,shutil,socket,stat,subprocess,sys,tempfile,time,uuid
SERVICE='orbit-websocket'
UNIT=pathlib.Path('/etc/systemd/system/orbit-websocket.service')
class Refused(Exception):pass
def refuse(code):raise Refused('websocket.'+code)
def run(args,step,check=True):
 result=subprocess.run(args,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=300)
 if check and result.returncode:refuse(step+'_failed')
 return result.returncode==0
def ctl(*args,check=True):return run(['/usr/bin/systemctl',*args],'service',check)
def unlink(path):path.unlink(missing_ok=True)
def write(path,data,mode,uid=0,gid=0):
 fd,name=tempfile.mkstemp(prefix='.orbit-',dir=path.parent)
 try:
  with os.fdopen(fd,'wb') as out:
   os.fchown(out.fileno(),uid,gid);os.fchmod(out.fileno(),mode);out.write(data);out.flush();os.fsync(out.fileno())
  os.replace(name,path)
 finally:unlink(pathlib.Path(name))
def link(target,path):
 candidate=path.with_name('.orbit-current.'+uuid.uuid4().hex)
 try:os.symlink(target,candidate);os.replace(candidate,path)
 finally:unlink(candidate)
def regular(path):return path.is_file() and not path.is_symlink()
def listener(port):
 deadline=time.monotonic()+30
 while True:
  try:
   with socket.create_connection(('127.0.0.1',port),timeout=1):return True
  except OSError:pass
  if time.monotonic()>=deadline:return False
  time.sleep(0.5)
def own_paths(install,releases):
 expected=None
 if UNIT.is_symlink():refuse('runtime_ownership_drift')
 if UNIT.exists():
  user=re.search(r'^User=([a-z_][a-z0-9_-]*)$',UNIT.read_text(),re.M)
  if user is None:refuse('runtime_ownership_drift')
  try:expected=pwd.getpwnam(user.group(1)).pw_uid
  except KeyError:refuse('runtime_account_missing')
 if releases.exists() and (releases.is_symlink() or not releases.is_dir() or (expected is not None and releases.stat().st_uid not in (0,expected))):refuse('runtime_ownership_drift')
 if UNIT.exists() and (not regular(UNIT) or not UNIT.read_text().startswith('# Managed by Orbit.')):refuse('runtime_ownership_drift')
 if install.is_symlink():
  previous=pathlib.Path(os.readlink(install))
  if not previous.is_absolute():previous=install.parent/previous
  if previous.parent!=releases or not re.fullmatch(r'release\.[a-f0-9]{32}',previous.name) or not previous.is_dir() or previous.is_symlink():refuse('runtime_ownership_drift')
  return previous
 if install.exists():refuse('runtime_ownership_drift')
 return None
def execute(v):
 install=pathlib.Path(v['install_path']);releases=pathlib.Path(str(install)+'.releases')
 # Check ownership before creating a lock or touching systemd.
 previous=own_paths(install,releases)
 install.parent.mkdir(parents=True,exist_ok=True)
 lockpath=pathlib.Path(str(install)+'.install.lock')
 fd=os.open(lockpath,os.O_CREAT|os.O_WRONLY|os.O_NOFOLLOW,0o600)
 with os.fdopen(fd,'w') as lock:
  if os.fstat(lock.fileno()).st_uid!=0:refuse('runtime_ownership_drift')
  fcntl.flock(lock,fcntl.LOCK_EX)
  previous=own_paths(install,releases)
  oldunit=UNIT.read_bytes() if UNIT.exists() else None
  if v['operation']=='remove':
   if v['purge'] and releases.exists():
    for child in releases.iterdir():
     if not re.fullmatch(r'release\.[a-f0-9]{32}',child.name) or child.is_symlink() or not child.is_dir():refuse('runtime_ownership_drift')
   if oldunit is not None:
    ctl('disable','--now','--',SERVICE)
    if ctl('is-active','--quiet',SERVICE,check=False):refuse('service_stop_failed')
    unlink(UNIT);ctl('daemon-reload');ctl('reset-failed','--',SERVICE,check=False)
   if v['purge']:
    unlink(install)
    if releases.exists():
     for child in releases.iterdir():
      if not re.fullmatch(r'release\.[a-f0-9]{32}',child.name) or child.is_symlink() or not child.is_dir():refuse('runtime_ownership_drift')
     shutil.rmtree(releases)
   return {}
  try:account=pwd.getpwnam(v['user']);group=grp.getgrnam(v['group'])
  except KeyError:refuse('runtime_account_missing')
  releases.mkdir(mode=0o755,exist_ok=True)
  candidate=releases/('release.'+uuid.uuid4().hex)
  candidate.mkdir(mode=0o755);os.chown(candidate,account.pw_uid,group.gr_gid)
  verify=pathlib.Path(tempfile.mkdtemp(prefix='orbit-websocket-verify-'))
  def managed(args,step):return run(['/usr/bin/sudo','-n','-H','-u',v['user'],'--',*args],step)
  try:
   managed(['/usr/bin/git','clone','--quiet','--no-checkout','--',v['repository'],str(candidate)],'clone')
   managed(['/usr/bin/git','-C',str(candidate),'fetch','--quiet','origin',v['ref']],'fetch')
   managed(['/usr/bin/git','-C',str(candidate),'checkout','--quiet','--detach',v['ref']],'checkout')
   managed(['/usr/bin/env','COMPOSER_ALLOW_SUPERUSER=1','composer','install','--no-dev','--optimize-autoloader','--no-interaction','--no-progress','--working-dir='+str(candidate)],'composer')
   if not regular(candidate/'artisan') or not (candidate/'vendor').is_dir():refuse('release_invalid')
   write(candidate/'.env',v['environment'].encode(),0o600,account.pw_uid,group.gr_gid)
   managed(['/usr/bin/php',str(candidate/'artisan'),'orbit:reverb:start','--help'],'artisan')
   verifyunit=verify/(SERVICE+'.service');write(verifyunit,v['unit'].encode(),0o600)
   run(['/usr/bin/systemd-analyze','verify',str(verifyunit)],'unit')
  except Refused:
   shutil.rmtree(candidate);raise
  finally:shutil.rmtree(verify)
  wasactive=ctl('is-active','--quiet',SERVICE,check=False)
  wasenabled=ctl('is-enabled','--quiet',SERVICE,check=False)
  if oldunit is not None:
   try:
    ctl('stop','--',SERVICE)
    if ctl('is-active','--quiet',SERVICE,check=False):refuse('service_stop_failed')
   except Refused:
    shutil.rmtree(candidate);raise
  try:
   link(candidate,install);write(UNIT,v['unit'].encode(),0o644)
   ctl('daemon-reload');ctl('enable','--quiet','--',SERVICE);ctl('restart','--',SERVICE)
   if not ctl('is-active','--quiet',SERVICE,check=False) or not listener(v['port']):refuse('runtime_not_listening')
  except Refused as original:
   try:
    ctl('stop','--',SERVICE)
    if previous is None:unlink(install)
    else:link(previous,install)
    if oldunit is None:unlink(UNIT)
    else:write(UNIT,oldunit,0o644)
    ctl('daemon-reload')
    if not wasenabled:ctl('disable','--quiet','--',SERVICE)
    if wasactive:
     ctl('restart','--',SERVICE)
     if not ctl('is-active','--quiet',SERVICE,check=False) or not listener(v['port']):refuse('runtime_restore_failed')
   except Refused:refuse('runtime_restore_failed')
   shutil.rmtree(candidate)
   raise original
  for child in releases.iterdir():
   if child not in (candidate,previous) and re.fullmatch(r'release\.[a-f0-9]{32}',child.name) and child.is_dir() and not child.is_symlink():shutil.rmtree(child)
  return {'release':str(candidate)}
try:print(json.dumps(execute(json.load(sys.stdin))))
except Refused as error:print(json.dumps({'error_code':str(error)}))
except Exception:print(json.dumps({'unknown':True}))
`;

export const WebSocketOwnership = program.slice(program.indexOf("def own_paths"), program.indexOf("def execute"));

function validPath(path: string) {
  return /^\/(?:[A-Za-z0-9_.-]+\/){2,}[A-Za-z0-9_.-]+$/.test(path) &&
    !path.split("/").some((p) => p === "." || p === "..");
}
export function websocketRuntimeSystem(
  transport: SystemTransport = localSystemCommand,
) {
  const execute = async (
    operation: string,
    input: WebSocketRuntimeInput | WebSocketRemovalInput,
    signal?: AbortSignal,
  ) => {
    signal?.throwIfAborted();
    if (!validPath(input.install_path)) {
      throw new WebSocketRuntimeError("websocket.runtime_invalid");
    }
    let response;
    try {
      response = await transport({
        command: "/usr/bin/sudo",
        args: ["-n", "--", "/usr/bin/python3", "-c", program],
        stdin: JSON.stringify({ operation, ...input }),
        timeoutMs: 900000,
        maxOutputBytes: 4096,
      }, signal);
    } catch {
      throw new Error("websocket.outcome_unknown");
    }
    if (!response.success) throw new Error("websocket.outcome_unknown");
    let result;
    try {
      result = JSON.parse(response.stdout);
    } catch {
      throw new Error("websocket.outcome_unknown");
    }
    if (
      typeof result?.error_code === "string" &&
      /^[a-z0-9_.-]{1,100}$/.test(result.error_code)
    ) {
      throw new WebSocketRuntimeError(result.error_code);
    }
    if (
      !result || typeof result !== "object" || Array.isArray(result) ||
      result.unknown
    ) throw new Error("websocket.outcome_unknown");
    return result;
  };
  return {
    async converge(input: WebSocketRuntimeInput, signal?: AbortSignal) {
      if (
        !/^[a-z_][a-z0-9_-]*$/.test(input.user) ||
        !/^[a-z_][a-z0-9_-]*$/.test(input.group) ||
        !/^[a-f0-9]{40}$/.test(input.ref) ||
        !/^https:\/\/[^\s@]+\.git$/.test(input.repository) ||
        !Number.isInteger(input.port) || input.port < 1 || input.port > 65535 ||
        !input.environment || !input.unit.startsWith("# Managed by Orbit.") ||
        !input.unit.includes(`\nUser=${input.user}\n`) ||
        !input.unit.includes(`\nGroup=${input.group}\n`) ||
        !input.unit.includes(`\nWorkingDirectory=${input.install_path}\n`) ||
        !input.unit.includes(
          `\nExecStart=/usr/bin/php ${input.install_path}/artisan orbit:reverb:start --host=127.0.0.1 --port=${input.port}\n`,
        )
      ) throw new WebSocketRuntimeError("websocket.runtime_invalid");
      const result = await execute("converge", input, signal);
      if (
        typeof result.release !== "string" ||
        Object.keys(result).length !== 1 ||
        !result.release.startsWith(input.install_path + ".releases/release.") ||
        !/^[a-f0-9]{32}$/.test(
          result.release.slice(
            (input.install_path + ".releases/release.").length,
          ),
        )
      ) {
        throw new Error("websocket.outcome_unknown");
      }
      return { release: result.release };
    },
    async remove(input: WebSocketRemovalInput, signal?: AbortSignal) {
      if (typeof input.purge !== "boolean") {
        throw new WebSocketRuntimeError("websocket.runtime_invalid");
      }
      const result = await execute("remove", input, signal);
      if (Object.keys(result).length !== 0) {
        throw new Error("websocket.outcome_unknown");
      }
      return {};
    },
  };
}
