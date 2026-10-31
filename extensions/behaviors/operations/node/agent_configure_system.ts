import { z } from "npm:zod@4.4.3";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Hash = z.string().regex(/^[a-f0-9]{64}$/);
export const AgentConfiguration = z.object({
  agent_sha256: Hash,
  secret_hash: Hash.nullable(),
  allow_secret_rotation: z.boolean(),
  auth_only: z.boolean().optional(),
  node_id: z.number().int().positive().optional(),
  configuration: z.string().max(65536),
  certificate: z.string().max(65536),
  unit: z.string().max(65536),
  checkouts: z.array(
    z.string().regex(/^\/(?:[A-Za-z0-9._-]+\/)*[A-Za-z0-9._-]+$/),
  ).max(1024),
  binary_changed: z.boolean(),
  worker_credentials: z.object({
    enrollment_token: z.string(),
    server_token: z.string(),
  }).strict().nullable().optional(),
}).strict();
const Result = z.discriminatedUnion("configured", [
  z.object({
    configured: z.literal(true),
    secret_hash: Hash,
    restarted: z.boolean(),
    unclosed: z.array(z.string()),
  }).strict(),
  z.object({
    configured: z.literal(false),
    error_code: z.enum([
      "agent.version_mismatch",
      "agent.update_busy",
      "agent.secret_mismatch",
      "agent.configure_failed",
      "node.agent_rekey_unavailable",
      "swamp.node_worker_memory_low",
      "swamp.node_worker_not_ready",
    ]),
  }).strict(),
]);

/** Code-owned system program. The optional root is solely an offline filesystem test seam. */
export function agentConfigurationProgram(root = "/", uid = 0): string {
  return `ROOT=${JSON.stringify(root)}\nUID=${uid}\n` + String.raw`
import os,sys,json,stat,hashlib,secrets,tempfile,fcntl,time,subprocess,tomllib
class Refusal(Exception):
 def __init__(self,code): self.code=code
def refuse(code='agent.configure_failed'): raise Refusal(code)
def path(p): return os.path.join(ROOT,p.lstrip('/'))
def metadata(p,mode=None,directory=False):
 try: m=os.lstat(path(p))
 except FileNotFoundError: return None
 if stat.S_ISLNK(m.st_mode) or m.st_uid!=UID or (stat.S_ISDIR(m.st_mode) if directory else stat.S_ISREG(m.st_mode)) is not True: refuse()
 if mode is not None and stat.S_IMODE(m.st_mode)!=mode: refuse()
 return m
def parents(p):
 current=''
 for part in os.path.dirname(p).split('/'):
  if not part: continue
  current+='/'+part
  m=metadata(current,directory=True)
  if m is None or (m.st_mode & 0o022 and not(current=='/run/lock' and stat.S_IMODE(m.st_mode)==0o1777)): refuse()
def read(p):
 parents(p)
 m=metadata(p)
 if m is None: return None
 with open(path(p),'rb') as f: return f.read()
def publish(p,text,mode):
 parents(p)
 metadata(p)
 data=text.encode()
 if read(p)==data:
  os.chmod(path(p),mode)
  return False
 fd,candidate=tempfile.mkstemp(prefix='.orbit-candidate-',dir=os.path.dirname(path(p)))
 try:
  os.fchmod(fd,mode)
  with os.fdopen(fd,'wb') as f: f.write(data);f.flush();os.fsync(f.fileno())
  os.replace(candidate,path(p))
  d=os.open(os.path.dirname(path(p)),os.O_RDONLY|os.O_DIRECTORY)
  try: os.fsync(d)
  finally: os.close(d)
 finally:
  if os.path.exists(candidate): os.unlink(candidate)
 return True
def system(args):
 if ROOT!='/': return
 if subprocess.run(args,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120).returncode: refuse()
def pin(expected):
 p='/usr/local/bin/orbit-agent'
 parents(p)
 if metadata(p,0o755) is None: refuse('agent.version_mismatch')
 with open(path(p),'rb') as f: digest=hashlib.file_digest(f,'sha256').hexdigest()
 if digest!=expected: refuse('agent.version_mismatch')
def configure(i):
 pin(i['agent_sha256'])
 lock='/run/lock/orbit-self-update.lock'
 lockrefusal='node.agent_rekey_unavailable' if i.get('auth_only') else 'agent.configure_failed'
 try:
  parents(lock)
  fd=os.open(path(lock),os.O_RDWR|os.O_CREAT|os.O_NOFOLLOW,0o600)
 except Exception: refuse(lockrefusal)
 with os.fdopen(fd,'r+b') as held:
  m=os.fstat(held.fileno())
  if not stat.S_ISREG(m.st_mode) or m.st_uid!=UID or m.st_mode & 0o022: refuse(lockrefusal)
  until=time.monotonic()+300
  while True:
   try: fcntl.flock(held,fcntl.LOCK_EX|fcntl.LOCK_NB);break
   except BlockingIOError:
    if time.monotonic()>=until: refuse('agent.update_busy')
    time.sleep(0.05)
  pin(i['agent_sha256'])
  try: cfg=tomllib.loads(i['configuration']); worker=cfg.get('swamp_worker')
  except Exception: refuse(lockrefusal)
  if worker is not None:
   if not isinstance(worker,dict) or type(worker.get('node_id')) is not int or worker['node_id']<1 or not isinstance(worker.get('user'),str): refuse()
   available=[line.split() for line in read('/proc/meminfo').decode().splitlines() if line.startswith('MemAvailable:')]
   if len(available)!=1 or len(available[0])!=3 or available[0][2]!='kB' or int(available[0][1])*1024<403378168+256*1024*1024: refuse('swamp.node_worker_memory_low')
   if i.get('worker_credentials') is None:
    try:
     if metadata('/etc/orbit/swamp-worker',0o700,True) is None: refuse('swamp.node_worker_not_ready')
     for name in ['enrollment.token','server.token']:
      if metadata('/etc/orbit/swamp-worker/'+name,0o600) is None: refuse('swamp.node_worker_not_ready')
    except Refusal: refuse('swamp.node_worker_not_ready')
  if i.get('auth_only'):
   try:
    if i.get('allow_secret_rotation') is not True or i.get('worker_credentials') is not None or type(i.get('node_id')) is not int: refuse()
    installedcfg=read('/etc/orbit/agent/config.toml')
    if installedcfg is None or metadata('/etc/orbit/agent/config.toml',0o644) is None: refuse()
    current=tomllib.loads(installedcfg.decode()).get('swamp_worker')
    if not isinstance(current,dict) or not isinstance(worker,dict) or type(current.get('node_id')) is not int or current.get('node_id')!=i['node_id'] or current!=worker: refuse()
    for file in ['/etc/orbit/agent/ca.pem','/etc/systemd/system/orbit-agent.service']:
     if metadata(file,0o644) is None: refuse()
    if metadata('/etc/orbit/agent',0o700,True) is None or metadata('/etc/orbit/agent/secret',0o600) is None: refuse()
   except Exception: refuse('node.agent_rekey_unavailable')
  installed=read('/etc/orbit/agent/secret')
  keep=installed is not None and i['secret_hash'] is not None and hashlib.sha256(installed).hexdigest()==i['secret_hash']
  if not i['allow_secret_rotation'] and not keep: refuse('agent.secret_mismatch')
  parents('/etc/orbit/agent')
  if metadata('/etc/orbit/agent',directory=True) is None: os.mkdir(path('/etc/orbit/agent'),0o700)
  os.chmod(path('/etc/orbit/agent'),0o700)
  if keep:
   metadata('/etc/orbit/agent/secret',0o600)
   digest=i['secret_hash']
  else:
   secret=secrets.token_hex(32)
   publish('/etc/orbit/agent/secret',secret,0o600)
   digest=hashlib.sha256(secret.encode()).hexdigest()
  if i.get('auth_only'):
   system(['systemctl','restart','--no-block','orbit-agent.service'])
   return {'configured':True,'secret_hash':digest,'restarted':True,'unclosed':[]}
  changed=(not keep) or i['binary_changed']
  for p,key in [('/etc/orbit/agent/config.toml','configuration'),('/etc/orbit/agent/ca.pem','certificate'),('/etc/systemd/system/orbit-agent.service','unit')]:
   changed=publish(p,i[key],0o644) or changed
  unclosed=[]
  for checkout in i['checkouts']:
   if os.path.realpath(path(checkout))!=path(checkout) or not os.path.isdir(path(checkout)): continue
   env=os.path.join(path(checkout),'.env')
   try:
    m=os.lstat(env)
    if stat.S_ISREG(m.st_mode): os.chmod(env,stat.S_IMODE(m.st_mode)&~0o007,follow_symlinks=False)
   except FileNotFoundError: pass
   except OSError: unclosed.append(checkout)
  system(['systemctl','daemon-reload'])
  system(['systemctl','enable','--now','orbit-agent.service'])
  if changed: system(['systemctl','restart','--no-block','orbit-agent.service'])
  return {'configured':True,'secret_hash':digest,'restarted':changed,'unclosed':unclosed}
try:
 i=json.load(sys.stdin)
 result=configure(i)
except Refusal as e: result={'configured':False,'error_code':e.code}
except Exception: result={'configured':False,'error_code':'agent.configure_failed'}
print(json.dumps(result,separators=(',',':')))
`;
}

/** Private request and returned hash must stay in the caller's private-material callback. */
export async function configureAgent(
  raw: unknown,
  execute: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
) {
  const input = AgentConfiguration.parse(raw);
  signal?.throwIfAborted();
  const result = await execute({
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/python3", "-c", agentConfigurationProgram()],
    stdin: JSON.stringify(input),
    timeoutMs: 420000,
  }, signal);
  if (!result.success) {
    throw new Error("Agent configuration has no confirmed outcome");
  }
  return Result.parse(JSON.parse(result.stdout));
}
