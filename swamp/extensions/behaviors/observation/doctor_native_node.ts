import type { NodeIntent, Row } from "./doctor_contract.ts";

/** Native identity, installed-file drift and capacity reads missing from the frozen observer. */
export const nodeReadProgram = String.raw`
import hashlib,json,os,pwd,stat,subprocess,sys
from pathlib import Path
ROOT=Path('/')
def absolute(path):return ROOT/str(path).lstrip('/')

def observe(body):
 user=body['user']; account=pwd.getpwnam(user); home=absolute(account.pw_dir)
 if not home.is_absolute(): raise ValueError('home')
 platform=os.uname().sysname.lower(); architecture=os.uname().machine.lower()
 if architecture in ('amd64','x86_64'): architecture='x86_64'
 elif architecture in ('arm64','aarch64'): architecture='aarch64'
 else: raise ValueError('architecture')
 if platform not in ('linux','darwin','freebsd'): raise ValueError('platform')
 def run(argv):
  result=subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=5)
  if len(result.stdout)>1048576: raise ValueError('bound')
  return result.returncode,result.stdout.decode('utf-8')
 if platform=='linux':
  code,text=run(['ip','-j','-4','address','show'])
  if code: raise ValueError('addresses')
  addresses=[a['local'] for link in json.loads(text) for a in link.get('addr_info',[]) if a.get('family')=='inet']
 else:
  code,text=run(['ifconfig'])
  if code: raise ValueError('addresses')
  addresses=[line.split()[1] for line in text.splitlines() if line.strip().startswith('inet ')]
 result=dict(platform=platform,architecture=architecture,wireguard=body['address'] in addresses,agent_binary=None,agent_unit=None,agent_active=None,worker_state=None,agent_checksum=None,secret_checksum=None,disks=[])
 def optional(path):
  try:return absolute(path).stat()
  except FileNotFoundError:return None
 def digest(path):
  info=optional(path)
  if info is None or not stat.S_ISREG(info.st_mode):return None
  value=hashlib.sha256()
  with open(absolute(path),'rb') as stream:
   for block in iter(lambda:stream.read(1048576),b''): value.update(block)
  return value.hexdigest()
 if platform=='linux':
  binary='/usr/local/bin/orbit-agent'; info=optional(binary)
  result['agent_binary']=info is not None and stat.S_ISREG(info.st_mode) and bool(info.st_mode & 0o111)
  if result['agent_binary']:result['agent_checksum']=digest(binary)
  info=optional('/etc/systemd/system/orbit-agent.service');result['agent_unit']=info is not None and stat.S_ISREG(info.st_mode)
  result['secret_checksum']=digest('/etc/orbit/agent/secret')
  if optional('/etc/systemd/system/orbit-node-worker.service') is not None:
   code,text=run(['systemctl','show','orbit-node-worker.service','--property=ActiveState','--value'])
   if code or text.strip() not in ('active','activating','deactivating','inactive','failed'):raise ValueError('worker')
   result['worker_state']=text.strip()
 seen=set()
 for location,path in ([('root',ROOT),('home',home)] if platform=='linux' else [('home',home)]):
  info=path.stat()
  if info.st_dev in seen:continue
  seen.add(info.st_dev); counters=os.statvfs(path)
  size=counters.f_blocks*counters.f_frsize//1024;available=max(0,counters.f_bavail)*counters.f_frsize//1024
  files=counters.f_files;free=counters.f_ffree
  if size<=0 or available>size or (files==0 and free!=0) or (files>0 and free>files):raise ValueError('disk')
  usable=platform=='linux' and files not in (0,18446744073709551615)
  result['disks'].append(dict(location=location,size_kib=size,available_kib=available,total_inodes=files if usable else None,free_inodes=free if usable else None))
 return result
if __name__=='__main__':print(json.dumps(observe(json.loads(sys.argv[1]))))
`;
export async function readNode(
  node: NodeIntent,
  local: (argv: string[]) => Promise<string>,
): Promise<Row> {
  const view = node.observations as Row | undefined;
  const result = JSON.parse(
    await local([
      ...(node.platform === "linux" ? ["sudo", "-n"] : []),
      "python3",
      "-c",
      nodeReadProgram,
      JSON.stringify({ user: node.user, address: node.address }),
    ]),
  );
  // Fresh authenticated observer traffic proves it is alive. An absent view is never proof of inactivity.
  result.agent_active = view?.freshness === "fresh" ? true : null;
  result.observer_unavailable = node.platform === "linux" &&
    view?.freshness !== "fresh";
  return { ...result, reachable: true };
}
