import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

const Path = z.string().startsWith("/").max(4096).refine((path) =>
  path !== "/" &&
  path.split("/").slice(1).every((part) =>
    part !== "" && part !== "." && part !== ".." && !part.includes("\0")
  )
);
const Version = z.string().regex(/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/);
const Environment = z.object({
  store: Path,
  node_version: Version,
  package_manager: z.enum(["npm", "pnpm"]),
  package_manager_version: Version,
  package_manager_hash: z.string().nullable(),
}).strict();
export type VpActionName =
  | "tool.read-vp-project"
  | "tool.inspect-vp-scope"
  | "tool.vp-version"
  | "tool.inspect-vp-store";
export type VpInvoke = (
  call: {
    name: VpActionName;
    input: Record<string, unknown>;
  },
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** Reads existing immutable runtimes. Download/install belongs to the Tools owner. */
export const vpReadProgram = String.raw`
import json,os,pwd,re,stat,subprocess,sys
class Refusal(Exception):pass
def check(ok):
 if not ok:raise Refusal()
def normal(path):
 check(isinstance(path,str) and path.startswith('/') and path!='/' and all(p and p not in ('.','..') for p in path[1:].split('/')))
 return path
def directory(path):
 normal(path);fd=os.open('/',os.O_RDONLY|os.O_DIRECTORY|os.O_CLOEXEC)
 try:
  for part in path[1:].split('/'):
   nxt=os.open(part,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW|os.O_CLOEXEC,dir_fd=fd);os.close(fd);fd=nxt
  return fd
 except:os.close(fd);raise

def read(root,name,maximum):
 fd=directory(root)
 try:
  try:file=os.open(name,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC,dir_fd=fd)
  except FileNotFoundError:return None
  try:
   info=os.fstat(file);check(stat.S_ISREG(info.st_mode) and info.st_size<=maximum)
   with os.fdopen(file,'rb',closefd=False) as stream:data=stream.read(maximum+1)
   check(len(data)<=maximum);return data.decode('utf8')
  finally:os.close(file)
 finally:os.close(fd)
def project(root):
 root=normal(root);package=json.loads(read(root,'package.json',1048576) or '{}');check(isinstance(package,dict))
 out={'package':package,'locks':{},'workspaces':{},'npmrc':'','.node-version':None,'.nvmrc':None}
 dependencies={}
 for key in ('dependencies','devDependencies'):
  if isinstance(package.get(key),dict):dependencies.update(package[key])
 if not any(name=='vite-plus' or isinstance(spec,str) and spec.startswith('npm:vite-plus@') for name,spec in dependencies.items()):return out
 for name in ('pnpm-lock.yaml','package-lock.json','yarn.lock'):
  value=read(root,name,16777216)
  if value is not None:out['locks'][name]=value
 for text in out['locks'].values():
  for link in re.findall(r'''version: ["']?link:([^\s"']+)''',text):
   target=os.path.realpath(os.path.join(root,link));check(os.path.commonpath((root,target))==root)
   workspace=json.loads(read(target,'package.json',1048576) or '{}')
   if workspace.get('name')=='vite-plus':out['workspaces']['link:'+link]=workspace.get('version')
 out['.node-version']=read(root,'.node-version',1048576);out['.nvmrc']=read(root,'.nvmrc',1048576)
 return out
def owned(path,kind):
 info=os.lstat(path);check((stat.S_ISDIR(info.st_mode) if kind=='directory' else True) and info.st_uid==os.geteuid() and info.st_gid==os.getegid())
 return info
def scope():
 account=pwd.getpwuid(os.geteuid());normal(account.pw_dir)
 try:
  opt=os.lstat('/opt/orbit');check(stat.S_ISDIR(opt.st_mode) and opt.st_uid==0 and opt.st_gid==0)
 except FileNotFoundError:pass
 for candidate in ('/opt/orbit/vite-plus',account.pw_dir+'/.vite-plus',account.pw_dir+'/.local/share/vite-plus'):
  try:owned(candidate,'directory')
  except FileNotFoundError:continue
  binary=candidate+'/bin/vp'
  try:owned(binary,'file')
  except FileNotFoundError:continue
  check(os.path.isfile(binary) and os.access(binary,os.X_OK));return binary
 raise Refusal()
def installed(env):
 store=normal(env['store']);owned(store,'directory');fd=directory(store);os.close(fd)
 node=store+'/js_runtime/node/'+env['node_version']+'/bin/node'
 manager=store+'/package_manager/'+env['package_manager']+'/'+env['package_manager_version']+'/'+env['package_manager']
 for path in (os.path.dirname(node),manager):
  try:fd=directory(path);os.close(fd)
  except FileNotFoundError:return False
 try:
  nodeinfo=os.lstat(node);owned(node,'file')
  check(stat.S_ISREG(nodeinfo.st_mode))
 except FileNotFoundError:return False
 manifest=json.loads(read(manager,'package.json',1048576) or '{}')
 binary=manager+'/bin/'+env['package_manager']
 return os.access(node,os.X_OK) and manifest.get('name')==env['package_manager'] and manifest.get('version')==env['package_manager_version'] and os.path.isfile(binary) and os.access(binary,os.X_OK)
def main():
 try:
  check(os.geteuid()!=0);data=json.load(sys.stdin);action=data['action'];value=data['input']
  if action=='tool.read-vp-project':out=project(value['path'])
  elif action=='tool.inspect-vp-scope':out={'binary':scope()}
  elif action=='tool.vp-version':
   binary=scope();account=pwd.getpwuid(os.geteuid());result=subprocess.run([binary,'--version'],cwd=account.pw_dir,env={'PATH':'/usr/bin:/bin','HOME':account.pw_dir,'VP_HOME':os.path.dirname(os.path.dirname(binary))},stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,timeout=30)
   check(result.returncode==0 and len(result.stdout)<=1048576);text=result.stdout.decode('utf8').splitlines()[0];check(re.fullmatch(r'[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?',text));out={'version':text}
  elif action=='tool.inspect-vp-store':out={'installed':[installed(env) for env in value['environments']]}
  else:raise Refusal()
 except:out={'error_code':'deployment.vp_store_failed'}
 print(json.dumps(out,separators=(',',':')))
main()
`;

export const invokeLocalVp: VpInvoke = async (call, signal) => {
  let user: string | undefined;
  let data: Record<string, unknown>;
  switch (call.name) {
    case "tool.read-vp-project": {
      const input = z.object({
        path: Path,
        user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).refine((user) =>
          user !== "root"
        ),
      }).strict().parse(call.input);
      user = input.user;
      data = input;
      break;
    }
    case "tool.inspect-vp-scope":
    case "tool.vp-version":
      data = z.object({}).strict().parse(call.input);
      break;
    case "tool.inspect-vp-store":
      data = z.object({ environments: z.array(Environment).max(128) }).strict()
        .parse(call.input);
      break;
    default:
      throw new Error("deployment.vp_store_failed");
  }
  signal?.throwIfAborted();
  const child = localCommand(user ? "/usr/bin/sudo" : "/usr/bin/python3", {
    args: [
      ...(user ? ["-n", "-u", user, "--", "/usr/bin/python3"] : []),
      "-I",
      "-c",
      vpReadProgram,
    ],
    cwd: "/",
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const abort = () => {
    try {
      child.kill("SIGTERM");
    } catch { /* exited */ }
  };
  signal?.addEventListener("abort", abort, { once: true });
  if (signal?.aborted) abort();
  const timer = setTimeout(abort, 120000);
  let size = 0;
  const parts: Uint8Array[] = [];
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(
      JSON.stringify({ action: call.name, input: data }),
    ));
    await writer.close();
    for await (const part of child.stdout) {
      size += part.length;
      if (size > 33554432) throw new Error();
      parts.push(part);
    }
    if (!(await child.status).success) throw new Error();
    const bytes = new Uint8Array(size);
    let offset = 0;
    for (const part of parts) {
      bytes.set(part, offset);
      offset += part.length;
    }
    return z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder().decode(bytes)),
    );
  } catch {
    throw new Error("deployment.vp_store_failed");
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", abort);
    abort();
    await child.status;
  }
};
