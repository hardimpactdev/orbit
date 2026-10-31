import { z } from "npm:zod@4.4.3";
import { localSystemCommand } from "../node/system_command.ts";

const Input = z.object({
  mode: z.enum([
    "read-check",
    "read",
    "write-check",
    "write",
    "merge",
    "inspect",
  ]),
  base: z.string().startsWith("/").max(4096),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,63}$/).refine((v) =>
    v !== "root"
  ),
  maximum: z.number().int().min(1).max(1048576),
  require_home: z.boolean(),
  required_capacity: z.number().int().nonnegative().default(0),
  file: z.enum([".env", ".env.testing"]).default(".env"),
  managed_keys: z.array(z.string().regex(/^[A-Z][A-Z0-9_]*$/)).max(1024)
    .default([]),
  content: z.string().optional(),
}).strict();
export type EnvironmentAccessInput = z.input<typeof Input>;

/** Code-owned local program. Content stays on stdin and never enters argv or logs. */
export const environmentProgram = String.raw`
import os,sys,json,pwd,stat,secrets,base64,re,subprocess
class Refused(Exception): pass
class TrackingUnknown(Exception): pass
def require(ok):
 if not ok: raise Refused()
def identity(st): return (st.st_dev,st.st_ino)
def outcome(state,installed=False,**rest): return dict(state=state,installed=installed,**rest)
def access(p):
 installed=False; directory=None; candidate=None; candidate_id=None
 try:
  account=pwd.getpwnam(p['user']); uid,gid=account.pw_uid,account.pw_gid
  require(uid!=0 and (not p['require_home'] or account.pw_dir==p['base']))
  parts=p['base'].split('/'); require(parts[0]=='' and len(parts)>1 and all(x not in ('','.','..') for x in parts[1:]))
  require(p['file'] in ('.env','.env.testing') and 0<p['maximum']<=1048576)
  if p['mode']=='merge': require(p['file']=='.env.testing' and p['managed_keys'] and all(re.fullmatch('[A-Z][A-Z0-9_]*',k) for k in p['managed_keys']))
  def openbase():
   fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
   try:
    for part in parts[1:]:
     nextfd=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd)
     os.close(fd); fd=nextfd
    return fd
   except BaseException: os.close(fd); raise
  def destination(fd):
   try: st=os.stat(p['file'],dir_fd=fd,follow_symlinks=False)
   except FileNotFoundError: return None
   require(stat.S_ISREG(st.st_mode) and st.st_uid==uid)
   return st
  directory=openbase(); ds=os.fstat(directory); original=destination(directory)
  def unchanged():
   fresh=openbase()
   try:
    require(identity(os.fstat(fresh))==identity(ds))
    current=destination(fresh)
    require((None if current is None else identity(current))==(None if original is None else identity(original)))
   finally: os.close(fresh)
  def read():
   require(original is not None and original.st_size<=p['maximum'])
   fd=os.open(p['file'],os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC,dir_fd=directory)
   with os.fdopen(fd,'rb') as stream:
    require(identity(os.fstat(stream.fileno()))==identity(original))
    value=stream.read(p['maximum']+1); require(len(value)<=p['maximum']); return value
  mode=p['mode']
  if mode in ('read-check','read'):
   if original is None: return outcome('missing' if mode=='read' else 'refused')
   require(original.st_size<=p['maximum'])
  if mode in ('write-check','write','merge'):
   require(ds.st_uid==uid and ds.st_mode&0o300==0o300)
   fs=os.fstatvfs(directory); require(not fs.f_flag&os.ST_RDONLY and fs.f_bavail*fs.f_frsize>=p['required_capacity'])
  if mode in ('read-check','write-check'): unchanged(); return outcome('ok')
  if mode=='read': return outcome('ok',content=base64.b64encode(read()).decode('ascii'))
  payload=p.get('content','').encode('utf-8'); require(len(payload)<=p['maximum'])
  if mode=='merge':
   def demote(): os.initgroups(account.pw_name,gid); os.setgid(gid); os.setuid(uid)
   def git(args):
    return subprocess.run(['/usr/bin/git','-c','core.hooksPath=/dev/null','-c','core.fsmonitor=false','-C',p['base']]+args,
      env={'PATH':'/usr/bin:/bin','HOME':account.pw_dir,'LANG':'C'},stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=30,
      preexec_fn=demote if os.geteuid()==0 else None)
   inside=git(['rev-parse','--is-inside-work-tree'])
   outside=inside.returncode==128 and inside.stderr.lower().startswith(b'fatal: not a git repository (or any ')
   if not outside:
    if inside.returncode!=0 or inside.stdout!=b'true\n': raise TrackingUnknown()
    tracked=git(['ls-files','--error-unmatch','--',p['file']])
    if tracked.returncode==0: unchanged(); return outcome('tracked')
    if tracked.returncode!=1: raise TrackingUnknown()
   keys=set(p['managed_keys']); updates={}
   for line in payload.splitlines(keepends=True):
    key=line.split(b'=',1)[0].decode('ascii',errors='replace')
    require(line.endswith(b'\n') and not (key in keys and key in updates))
    if key in keys: updates[key]=line
   require(bool(updates))
   if original is not None:
    lines=[]; written=set()
    for line in read().splitlines(keepends=True):
     match=re.match(rb'[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=',line)
     key=match.group(1).decode('ascii') if match else None
     if key in keys:
      if key in updates and key not in written: lines.append(updates[key]); written.add(key)
     else: lines.append(line)
    missing=[v for k,v in updates.items() if k not in written]
    if missing and lines and not lines[-1].endswith(b'\n'): lines[-1]+=b'\n'
    payload=b''.join(lines+missing); require(len(payload)<=p['maximum'])
  candidate='.env.orbit-'+secrets.token_hex(16)
  fd=os.open(candidate,os.O_RDWR|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW|os.O_CLOEXEC,0o600,dir_fd=directory)
  candidate_id=identity(os.fstat(fd))
  with os.fdopen(fd,'wb') as stream:
   if os.geteuid()==0: os.fchown(stream.fileno(),uid,gid)
   os.fchmod(stream.fileno(),0o600); stream.write(payload); stream.flush(); os.fsync(stream.fileno())
  same=original is not None and stat.S_IMODE(original.st_mode)==0o600 and read()==payload
  unchanged()
  if same: return outcome('unchanged')
  os.rename(candidate,p['file'],src_dir_fd=directory,dst_dir_fd=directory); installed=True; os.fsync(directory)
  return outcome('changed',True)
 except TrackingUnknown: return outcome('tracking_unknown')
 except (Refused,KeyError,NotADirectoryError): return outcome('refused')
 except BaseException: return outcome('failed',installed)
 finally:
  if directory is not None:
   if candidate is not None and not installed:
    try:
     if identity(os.stat(candidate,dir_fd=directory,follow_symlinks=False))==candidate_id: os.unlink(candidate,dir_fd=directory)
    except OSError: pass
   os.close(directory)
p=json.load(sys.stdin)
if p['mode']=='inspect':
 p['mode']='write-check'; preflight=access(p); existing=None
 if preflight['state']=='ok': p['mode']='read'; existing=access(p)
 result=dict(preflight=preflight,existing=existing)
else: result=access(p)
print(json.dumps(result,separators=(',',':')))
`;

export async function localEnvironment(
  raw: EnvironmentAccessInput,
  signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const input = Input.parse(raw);
  signal?.throwIfAborted();
  // Finish an admitted atomic write even if the workflow is cancelled.
  const output = await localSystemCommand({
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/python3", "-I", "-c", environmentProgram],
    stdin: JSON.stringify(input),
    timeoutMs: 90000,
    maxOutputBytes: 1500000,
  });
  if (!output.success) throw new Error("env.sync_unconfirmed");
  return z.record(z.string(), z.unknown()).parse(JSON.parse(output.stdout));
}
