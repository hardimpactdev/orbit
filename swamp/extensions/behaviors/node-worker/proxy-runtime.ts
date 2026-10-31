import { z } from "npm:zod@4.4.3";
import { type NodeCommand, run } from "../operations/_lib/node-commands.ts";
import { availableMemory, Swamp } from "./install.ts";
import { required, text } from "./files.ts";

export const ProxyRuntime = z.object({
  contract: z.literal("running209-artifact-before-proxy-effects-v1"),
  node_id: z.literal(209),
  user: z.literal("orbit"),
  pid: z.number().int().positive(),
  invocation: z.string().regex(/^[a-f0-9]{32}$/),
  sha256: z.string().regex(/^[a-f0-9]{64}$/),
  bytes: z.number().int().positive(),
  device: z.number().int().nonnegative(),
  inode: z.number().int().positive(),
  unit_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
export type ProxyRuntime = z.infer<typeof ProxyRuntime>;

// Fixed, read-only subprocess: compiled Swamp cannot read /proc directly.
// No private credential file or process environment is read.
export const runtimeProgram =
  `import os,sys,stat,json,hashlib,pwd,subprocess,tomllib
def custody(path,mode,directory=False):
 s=os.lstat(path)
 if s.st_uid!=0 or s.st_gid!=0 or (s.st_mode&0o022 if directory else stat.S_IMODE(s.st_mode)!=mode) or not (stat.S_ISDIR(s.st_mode) if directory else stat.S_ISREG(s.st_mode)):raise RuntimeError('unsafe runtime custody')
 return s
def digest(f):
 h=hashlib.sha256()
 while True:
  block=f.read(1048576)
  if not block:break
  h.update(block)
 return h.hexdigest()
def state():
 v=subprocess.check_output(['/usr/bin/systemctl','show','orbit-node-worker.service','--property=LoadState,ActiveState,SubState,MainPID,User,FragmentPath,DropInPaths,InvocationID'],timeout=8,text=True)
 return dict(line.split('=',1) for line in v.splitlines())
if os.geteuid()!=0:raise RuntimeError('root runtime inspection required')
for path in ['/','/usr','/usr/local','/usr/local/bin','/etc','/etc/systemd','/etc/systemd/system','/etc/orbit']:
 custody(path,0o755,True)
custody('/etc/orbit/agent',0o700,True)
config='/etc/orbit/agent/config.toml';s=os.lstat(config)
if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_gid!=0 or s.st_mode&0o022:raise RuntimeError('unsafe Node identity')
with open(config,'rb') as f:identity=tomllib.load(f)
if identity.get('swamp_worker')!={'node_id':209,'user':'orbit'}:raise RuntimeError('proxy workers belong on Node209')
unit='/etc/systemd/system/orbit-node-worker.service';custody(unit,0o644)
with open(unit,'rb') as f:unit_hash=digest(f)
before=state()
if any(before.get(k)!=v for k,v in {'LoadState':'loaded','ActiveState':'active','SubState':'running','User':'orbit','FragmentPath':unit,'DropInPaths':''}.items()):raise RuntimeError('canonical existing worker required')
pid=int(before['MainPID']);account=pwd.getpwnam('orbit')
if pid<=0 or account.pw_uid<=0:raise RuntimeError('invalid worker identity')
with open('/proc/'+str(pid)+'/status') as f:status=dict(line.split(':',1) for line in f if ':' in line)
if list(map(int,status['Uid'].split()))!=[account.pw_uid]*4:raise RuntimeError('worker process user drift')
with open('/proc/'+str(pid)+'/cgroup') as f:cgroup=f.read(65537)
if cgroup.strip()!='0::/system.slice/orbit-node-worker.service':raise RuntimeError('worker process placement drift')
with open('/proc/'+str(pid)+'/cmdline','rb') as f:argv=f.read(65537).split(bytes([0]))
binary='/usr/local/bin/swamp';exe='/proc/'+str(pid)+'/exe'
if argv[:3]!=[binary.encode(),b'worker',b'connect'] or b'node=209' not in argv:raise RuntimeError('worker command identity drift')
if os.readlink(exe)!=binary:raise RuntimeError('worker executable path drift')
path_stat=custody(binary,0o755)
with open(binary,'rb') as path_file,open(exe,'rb') as process_file:
 a=os.fstat(path_file.fileno());b=os.fstat(process_file.fileno())
 if (a.st_dev,a.st_ino,a.st_size)!=(b.st_dev,b.st_ino,b.st_size) or (a.st_dev,a.st_ino)!=(path_stat.st_dev,path_stat.st_ino) or a.st_size<=0:raise RuntimeError('worker executable identity drift')
 path_hash=digest(path_file);process_hash=digest(process_file)
 if path_hash!=process_hash:raise RuntimeError('worker executable digest drift')
 end=custody(binary,0o755)
 if (end.st_dev,end.st_ino,end.st_size,end.st_mtime_ns,end.st_ctime_ns)!=(a.st_dev,a.st_ino,a.st_size,a.st_mtime_ns,a.st_ctime_ns) or state()!=before or os.readlink(exe)!=binary:raise RuntimeError('runtime changed during attestation')
with open(unit,'rb') as f:
 if digest(f)!=unit_hash:raise RuntimeError('worker unit changed during attestation')
print(json.dumps({'contract':'running209-artifact-before-proxy-effects-v1','node_id':209,'user':'orbit','pid':pid,'invocation':before['InvocationID'],'sha256':path_hash,'bytes':a.st_size,'device':a.st_dev,'inode':a.st_ino,'unit_sha256':unit_hash}))
`;

/** Attests the running approved artifact, never the future installer target. */
export async function attestProxyRuntime(
  invoke: NodeCommand = run,
  expected?: ProxyRuntime,
): Promise<ProxyRuntime> {
  const proof = ProxyRuntime.parse(JSON.parse(text(
    await required(
      invoke,
      ["/usr/bin/python3", "-c", runtimeProgram],
    ),
  )));
  if (
    expected && JSON.stringify(proof) !==
      JSON.stringify(ProxyRuntime.parse(expected))
  ) throw new Error("Proxy worker runtime changed since preflight");
  const memory = availableMemory(
    text(await required(invoke, ["/usr/bin/cat", "/proc/meminfo"])),
    proof.bytes,
  );
  if (memory < 2 * (proof.bytes + Swamp.headroom)) {
    throw new Error("Insufficient RAM for two proxy workers");
  }
  return proof;
}
