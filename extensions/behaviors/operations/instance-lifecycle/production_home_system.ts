import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";
import type { NodeCall } from "../_lib/node-agent.ts";
import { sourceGitCommand } from "./source_system.ts";

const scalar = (value: string) =>
  [...value].every((c) => c.charCodeAt(0) > 32 && c.charCodeAt(0) !== 127);
const Path = z.string().max(4096).regex(/^\/(?:[^/]+\/)*[^/]+$/).refine((v) =>
  !v.split("/").some((part) => part === "." || part === "..") &&
  scalar(v)
);
const Relative = z.string().min(1).max(4096).refine((v) =>
  !v.startsWith("/") &&
  v.split("/").every((part) =>
    part !== "" && part !== "." && part !== ".." &&
    scalar(part)
  )
);
const ProjectRoot = z.union([z.literal("."), Relative]);
const Repository = z.string().min(1).max(4096).refine((v) =>
  !v.startsWith("-") && scalar(v)
);
const Identity = z.object({
  project_id: z.number().int().positive().safe(),
  user: z.string().regex(/^orbit-app-[1-9][0-9]*$/).max(32),
});
const Home = Identity.extend({ home: Path });
const Source = Home.extend({
  repository: Repository,
  instance: z.number().int().positive().safe(),
});
const GitRead = z.object({
  github_token: z.string().min(1).max(4096).regex(/^[A-Za-z0-9_.-]+$/)
    .nullable(),
}).strict();
const Release = Source.extend({
  relative_root: ProjectRoot,
  application_suffix: z.string().default(""),
  served: z.array(z.unknown()).default([]),
});
const productionActions = [
  "instance.prepare-production-user",
  "instance.prepare-production-source",
  "instance.resolve-production-source",
  "instance.inspect-production-profile",
  "instance.validate-release-layout",
  "instance.clear-current-release",
  "instance.remove-production-home",
  "instance.grant-production-caddy-access",
  "instance.inspect-clone-candidate",
] as const;
export type ProductionHomeAction = typeof productionActions[number];
export const isProductionHomeAction = (
  name: string,
): name is ProductionHomeAction =>
  productionActions.some((action) => action === name);

/** Input is admitted by the truthful parent; project_id comes from its frozen intent. */
export function productionHomeInput(call: Pick<NodeCall, "name" | "input">) {
  let schema: z.ZodType<Record<string, unknown>>;
  switch (call.name) {
    case "instance.prepare-production-user":
      schema = Home.strict();
      break;
    case "instance.prepare-production-source":
      schema = Source.extend({ allow_existing: z.boolean(), git_read: GitRead })
        .strict();
      break;
    case "instance.resolve-production-source":
      schema = Release.extend({
        branch: z.string().min(1).max(255).refine((v) =>
          !v.startsWith("-") && scalar(v)
        ),
        clone_target: z.boolean(),
        git_read: GitRead,
      }).strict();
      break;
    case "instance.inspect-production-profile":
      schema = Identity.extend({
        relative_root: ProjectRoot,
        checkout: Path,
        application: Path,
        entry_point: z.enum(["artisan", "bin/console"]),
      }).strict();
      break;
    case "instance.validate-release-layout":
    case "instance.clear-current-release":
      schema = Release.strict();
      break;
    case "instance.remove-production-home":
      schema = Source.strict();
      break;
    case "instance.grant-production-caddy-access":
      schema = Home.extend({
        relative_root: ProjectRoot,
        clone_target: z.boolean(),
      }).strict();
      break;
    case "instance.inspect-clone-candidate":
      schema = Identity.extend({
        environment: z.literal("production"),
        base: Path,
        repository: Repository,
        configured_branch: z.string().min(1).max(255),
        target_branch: z.string().min(1).max(255),
        expected_source: Path,
        git_read: GitRead,
      }).strict();
      break;
    default:
      throw new Error("instance.source_kind_unavailable");
  }
  const input = schema.parse(call.input);
  const user = `orbit-app-${input.project_id}`;
  const home = `/home/${user}`;
  if (
    input.user !== user || (input.home !== undefined && input.home !== home) ||
    (input.base !== undefined && input.base !== home)
  ) {
    throw new Error("instance.production_account_mismatch");
  }
  if (
    typeof input.application_suffix === "string" &&
    input.application_suffix !== "" &&
    (!input.application_suffix.startsWith("/") ||
      !Relative.safeParse(input.application_suffix.slice(1)).success)
  ) {
    throw new Error("instance.source_invalid");
  }
  if (
    typeof input.checkout === "string" &&
    (!input.checkout.startsWith(`${home}/releases/`) ||
      input.checkout.slice(`${home}/releases/`.length).includes("/") ||
      typeof input.application !== "string" ||
      !(input.application === input.checkout ||
        input.application.startsWith(`${input.checkout}/`)))
  ) {
    throw new Error("instance.source_invalid");
  }
  return input;
}

/** Fixed local program; private Git configuration arrives on stdin, never argv or resources. */
export const productionHomeProgram = String.raw`
import base64,grp,json,os,pwd,re,shutil,stat,subprocess,sys,tempfile
HOME_ROOT='/home'
STATE_ROOT='/var/lib/orbit/app-instance-sources'
ROOT_UID=0
class Refusal(Exception):pass
def check(ok):
 if not ok:raise Refusal()
def exists(path):return os.path.lexists(path)
def metadata(path):
 try:return os.lstat(path)
 except FileNotFoundError:return None
def canonical(path):
 check(path.startswith('/') and all(p and p not in ('.','..') for p in path[1:].split('/')))
 current=''
 for part in path[1:].split('/'):
  current+='/'+part;info=metadata(current)
  if info is not None:check(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode))
 return path
def owned_directory(path,uid,gid):
 canonical(path);info=os.lstat(path);check(stat.S_ISDIR(info.st_mode) and info.st_uid==uid and info.st_gid==gid)
def owned_tree(path,uid,gid):
 owned_directory(path,uid,gid)
 for root,dirs,files in os.walk(path,followlinks=False):
  for name in dirs+files:
   info=os.lstat(root+'/'+name);check(info.st_uid==uid and info.st_gid==gid)
def read(path,maximum=1048576):
 fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW|os.O_NONBLOCK|os.O_CLOEXEC)
 try:
  info=os.fstat(fd);check(stat.S_ISREG(info.st_mode) and info.st_size<=maximum)
  with os.fdopen(fd,'rb',closefd=False) as stream:value=stream.read(maximum+1)
  check(len(value)<=maximum);return value
 finally:os.close(fd)
def entry(data):
 account=pwd.getpwnam(data['user']);check(account.pw_uid!=0 and account.pw_dir==data['home'] and grp.getgrgid(account.pw_gid).gr_name==data['user'] and account.pw_shell=='/usr/sbin/nologin' and account.pw_uid<1000)
 return account
def drop(account):
 if os.geteuid()!=account.pw_uid:
  os.setgroups([]);os.setgid(account.pw_gid);os.setuid(account.pw_uid)
def run(program,args,account=None,env=None,cwd='/',maximum=2097152):
 process=subprocess.Popen([program]+args,cwd=cwd,env=env or {'PATH':'/usr/local/bin:/usr/bin:/bin','LANG':'C.UTF-8'},stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,preexec_fn=(lambda:drop(account)) if account else None)
 output=bytearray()
 try:
  while True:
   chunk=process.stdout.read(65536)
   if not chunk:break
   output.extend(chunk);check(len(output)<=maximum)
  check(process.wait(timeout=900)==0);return bytes(output)
 finally:
  if process.poll() is None:process.kill()
  process.wait()
def git(data,args,private=False):
 account=entry(data);command=GIT_PRIVATE if private else GIT_PUBLIC
 env={'PATH':'/usr/local/bin:/usr/bin:/bin','LANG':'C.UTF-8','LC_ALL':'C.UTF-8','HOME':account.pw_dir,'GIT_CONFIG_NOSYSTEM':'1','GIT_CONFIG_GLOBAL':'/dev/null','GIT_CONFIG_SYSTEM':'/dev/null','GIT_OPTIONAL_LOCKS':'0','GIT_TERMINAL_PROMPT':'0'}
 env.update(command['env']);return run('/usr/bin/git',command['args']+args,account,env)
def state(data):return STATE_ROOT+'/'+str(data['instance'])
def clone_marker(data):return (data['repository']+'\0'+data['user']+'\0'+data['home']+'\0').encode()
def root_owned(path,mode,directory=False):
 info=os.lstat(path);check((stat.S_ISDIR(info.st_mode) if directory else stat.S_ISREG(info.st_mode)) and info.st_uid==ROOT_UID and info.st_gid==ROOT_UID and stat.S_IMODE(info.st_mode)==mode)
def marker(data,name,value):
 root_owned(STATE_ROOT,0o700,True);root_owned(state(data),0o700,True);root_owned(state(data)+'/'+name,0o600);check(read(state(data)+'/'+name)==value)
def write_marker(path,value):
 fd,temporary=tempfile.mkstemp(prefix='.marker-',dir=os.path.dirname(path))
 try:
  os.fchmod(fd,0o600)
  with os.fdopen(fd,'wb',closefd=False) as stream:stream.write(value);stream.flush();os.fsync(fd)
  os.replace(temporary,path)
 finally:
  os.close(fd)
  if exists(temporary):os.unlink(temporary)
def check_home(data):
 check(data['user']=='orbit-app-'+str(data['project_id']) and data['home']==HOME_ROOT+'/'+data['user']);account=entry(data);owned_directory(data['home'],account.pw_uid,account.pw_gid);return account
def layout(data):
 account=check_home(data);marker(data,'release-layout',clone_marker(data)+b'initial\0');owned_tree(data['home'],account.pw_uid,account.pw_gid)
 releases=data['home']+'/releases';owned_directory(releases,account.pw_uid,account.pw_gid)
 env=data['home']+'/.env';info=os.lstat(env);check(stat.S_ISREG(info.st_mode) and stat.S_IMODE(info.st_mode)==0o600)
 return account
def prepare_user(data):
 check(data['user']=='orbit-app-'+str(data['project_id']) and data['home']==HOME_ROOT+'/'+data['user']);canonical(HOME_ROOT)
 try:entry(data)
 except KeyError:
  check(not exists(data['home']));run('/usr/sbin/useradd',['--system','--user-group','--home-dir',data['home'],'--shell','/usr/sbin/nologin','--',data['user']])
 account=entry(data)
 if exists(data['home']):owned_directory(data['home'],account.pw_uid,account.pw_gid)
 else:os.mkdir(data['home'],0o700);os.chown(data['home'],account.pw_uid,account.pw_gid)
 return {}
def prepare_source(data):
 account=check_home(data);home=data['home'];owned_tree(home,account.pw_uid,account.pw_gid);release=home+'/releases/initial'
 if exists(release+'/.git'):
  check(data['allow_existing']);layout(data);marker(data,'initial-clone',clone_marker(data));check(not exists(home+'/current'));owned_directory(release+'/.git',account.pw_uid,account.pw_gid)
  check(git(data,['-C',release,'rev-parse','--show-toplevel']).decode().rstrip('\n')==release)
  check(git(data,['-C',release,'config','--null','--get','remote.origin.url'])==data['repository'].encode()+b'\0');return {}
 check(not os.listdir(home) and not exists(state(data)));os.mkdir(home+'/releases',0o700);os.chown(home+'/releases',account.pw_uid,account.pw_gid)
 fd=os.open(home+'/.env',os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600);os.fchown(fd,account.pw_uid,account.pw_gid);os.close(fd)
 git(data,['clone','--no-checkout','--origin','origin','--',data['repository'],release],True)
 canonical(STATE_ROOT)
 if not exists(STATE_ROOT):os.makedirs(STATE_ROOT,mode=0o700)
 root_owned(STATE_ROOT,0o700,True);os.mkdir(state(data),0o700)
 write_marker(state(data)+'/initial-clone',clone_marker(data));write_marker(state(data)+'/release-layout',clone_marker(data)+b'initial\0');layout(data);return {}
def application(data,release):return release+data.get('application_suffix','')
def env_target(data):return '../'*(2+data.get('application_suffix','').count('/'))+'.env'
def resolve(data):
 layout(data);home=data['home'];check(not exists(home+'/current'));release=home+'/releases/initial';owned_directory(release,*[entry(data).pw_uid,entry(data).pw_gid])
 check(git(data,['-C',release,'config','--null','--get','remote.origin.url'])==data['repository'].encode()+b'\0')
 try:filters=git(data,['-C',release,'config','--get-regexp',r'^filter\..*\.(clean|smudge|process)$'])
 except Refusal:filters=b''
 check(not filters);git(data,['check-ref-format','--branch',data['branch']]);git(data,['-C',release,'fetch','--no-tags','--prune','--',data['repository'],'+refs/heads/*:refs/remotes/origin/*'],True)
 source='refs/remotes/origin/'+data['branch'];git(data,['-C',release,'show-ref','--verify','--quiet',source]);git(data,['-C',release,'checkout','--quiet','-B',data['branch'],source]);git(data,['-C',release,'branch','--quiet','--set-upstream-to=origin/'+data['branch'],data['branch']])
 app=application(data,release);canonical(app);check(os.path.isdir(app));link=app+'/.env';target=env_target(data)
 if os.path.islink(link):check(os.readlink(link)==target)
 elif exists(link):
  check(data['clone_target']);info=os.lstat(link);check(stat.S_ISREG(info.st_mode));tracked=data.get('application_suffix','').lstrip('/');tracked=(tracked+'/' if tracked else '')+'.env'
  for arguments in (['ls-files','--error-unmatch','--',tracked],['diff','--quiet','--',tracked],['diff','--cached','--quiet','--',tracked]):git(data,['-C',release]+arguments)
  os.unlink(link);os.symlink(target,link);os.lchown(link,entry(data).pw_uid,entry(data).pw_gid)
 else:os.symlink(target,link);os.lchown(link,entry(data).pw_uid,entry(data).pw_gid)
 check(os.path.realpath(link)==home+'/.env');return {'branch':data['branch'],'commit':git(data,['-C',release,'rev-parse','--verify','HEAD^{commit}']).decode().rstrip('\n')}
def profile(data):
 home=HOME_ROOT+'/'+data['user'];data=dict(data,home=home);account=check_home(data);checkout=data['checkout'];app=data['application']
 unsafe={'kind':'unsafe','composer':None,'entry_point':None}
 try:
  canonical(checkout);canonical(app);check(app==checkout or app.startswith(checkout+'/'));root=os.path.realpath(checkout+'/'+data['relative_root']);check(root==checkout or root.startswith(checkout+'/'));owned_tree(checkout,account.pw_uid,account.pw_gid)
  manifest=metadata(app+'/composer.json');entrypoint=metadata(app+'/'+data['entry_point'])
  if manifest is None:return {'kind':'partial' if entrypoint else 'none','composer':None,'entry_point':None}
  check(stat.S_ISREG(manifest.st_mode) and manifest.st_uid==account.pw_uid);value=read(app+'/composer.json')
  canonical(os.path.dirname(app+'/'+data['entry_point']));return {'kind':'composer','composer':base64.b64encode(value).decode(),'entry_point':'absent' if entrypoint is None else 'regular' if stat.S_ISREG(entrypoint.st_mode) else 'unsafe'}
 except (Refusal,OSError):return unsafe
def selected(data):
 layout(data);home=data['home'];current=home+'/current';info=metadata(current)
 if info is None:return None
 check(stat.S_ISLNK(info.st_mode));account=entry(data);check(info.st_uid==account.pw_uid and info.st_gid==account.pw_gid)
 target=os.readlink(current);check(re.fullmatch(r'releases/[A-Za-z0-9][A-Za-z0-9._-]*',target) is not None);release=home+'/'+target;canonical(release);check(os.path.isdir(release))
 link=application(data,release)+'/.env';check(os.path.islink(link) and os.readlink(link)==env_target(data) and os.path.realpath(link)==home+'/.env');root=os.path.realpath(release+'/'+data['relative_root']);check(root==release or root.startswith(release+'/'));return target[len('releases/'):]
def clear_current(data):
 if selected(data) is not None:os.unlink(data['home']+'/current')
 return {'current':None}
def grant_caddy(data):
 account=check_home(data);home=data['home'];owned_tree(home,account.pw_uid,account.pw_gid);current=home+'/current';release=None
 if exists(current):
  check(os.path.islink(current));target=os.readlink(current);check(re.fullmatch(r'releases/[A-Za-z0-9][A-Za-z0-9._-]*',target) is not None);release=home+'/'+target
 elif data['clone_target']:release=home+'/releases/initial'
 ancestors=[];root=None
 if release:
  canonical(release);root=release if data['relative_root']=='.' else release+'/'+data['relative_root'];canonical(root);check(os.path.isdir(root))
  for walk,dirs,files in os.walk(root,followlinks=False):
   for name in dirs+files:check(not stat.S_ISLNK(os.lstat(walk+'/'+name).st_mode))
  path=os.path.dirname(root)
  while path!=home:check(path.startswith(home+'/'));ancestors.append(path);path=os.path.dirname(path)
 run('/usr/bin/setfacl',['-n','-P','-R','-m','u:caddy:---','--',home])
 for walk,dirs,files in os.walk(home,followlinks=False):run('/usr/bin/setfacl',['-m','d:u:caddy:---','--',walk])
 for path in [HOME_ROOT,home]+ancestors:run('/usr/bin/setfacl',['-m','u:caddy:--x','--',path])
 if root:
  run('/usr/bin/setfacl',['-P','-R','-m','u:caddy:r-X','--',root])
  for walk,dirs,files in os.walk(root,followlinks=False):run('/usr/bin/setfacl',['-m','d:u:caddy:r-x','--',walk])
 return {}
def remove_home(data):
 check(data['home']==HOME_ROOT+'/'+data['user']);source_state=state(data)
 try:account=entry(data)
 except KeyError:account=None
 if not exists(source_state):check(account is None and not exists(data['home']));return {'removed':False}
 root_owned(STATE_ROOT,0o700,True);root_owned(source_state,0o700,True);marker(data,'release-layout',clone_marker(data)+b'initial\0')
 if account:
  for name in os.listdir('/proc'):
   if name.isdigit():
    try:check(os.stat('/proc/'+name).st_uid!=account.pw_uid)
    except FileNotFoundError:pass
 if exists(data['home']):
  canonical(data['home'])
  if account:owned_directory(data['home'],account.pw_uid,account.pw_gid)
  shutil.rmtree(data['home'])
 if account:run('/usr/sbin/userdel',['--',data['user']])
 try:pwd.getpwnam(data['user']);raise Refusal()
 except KeyError:pass
 check(not exists(data['home']));shutil.rmtree(source_state);return {'removed':True}
def inspect_candidate(data):
 data=dict(data,home=data['base']);account=check_home(data);current=data['home']+'/current'
 check(os.path.islink(current));info=os.lstat(current);check(info.st_uid==account.pw_uid and info.st_gid==account.pw_gid)
 target=os.readlink(current);check(re.fullmatch(r'releases/[A-Za-z0-9][A-Za-z0-9._-]*',target) is not None)
 checkout=data['home']+'/'+target;canonical(checkout);check(checkout==data['expected_source']);owned_tree(checkout,account.pw_uid,account.pw_gid)
 check(git(data,['-C',checkout,'rev-parse','--show-toplevel']).decode().rstrip('\n')==checkout)
 try:branch=git(data,['-C',checkout,'symbolic-ref','--quiet','--short','HEAD']).decode().rstrip('\n')
 except Refusal:branch=None
 check(branch is None or branch==data['configured_branch']);check(not git(data,['-C',checkout,'status','--porcelain=v1','-z','--untracked-files=all','--ignore-submodules=none']))
 index=git(data,['-C',checkout,'ls-files','--stage','-z']);check(not any(row.startswith(b'160000 ') for row in index.split(b'\0')))
 commit=git(data,['-C',checkout,'rev-parse','--verify','HEAD^{commit}']).decode().rstrip('\n');check(re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?',commit) is not None)
 scratch=tempfile.mkdtemp(prefix='orbit-production-candidate-');os.chown(scratch,account.pw_uid,account.pw_gid)
 try:
  bare=scratch+'/repository.git';git(data,['init','--quiet','--bare','--',bare]);git(data,['--git-dir='+bare,'fetch','--quiet','--no-tags','--prune','--',data['repository'],'+refs/heads/*:refs/remotes/origin/*'],True)
  git(data,['--git-dir='+bare,'cat-file','-e',commit+'^{commit}']);git(data,['--git-dir='+bare,'show-ref','--verify','--quiet','refs/remotes/origin/'+data['target_branch']])
 finally:shutil.rmtree(scratch)
 return {'source':checkout,'commit':commit}
def perform(data):
 global GIT_PRIVATE,GIT_PUBLIC
 GIT_PRIVATE=data['git_private'];GIT_PUBLIC=data['git_public'];value=data['input'];action=data['action']
 if action=='instance.prepare-production-user':return prepare_user(value)
 if action=='instance.prepare-production-source':return prepare_source(value)
 if action=='instance.resolve-production-source':return resolve(value)
 if action=='instance.inspect-production-profile':return profile(value)
 if action=='instance.validate-release-layout':return {'current':selected(value)}
 if action=='instance.clear-current-release':return clear_current(value)
 if action=='instance.grant-production-caddy-access':return grant_caddy(value)
 if action=='instance.remove-production-home':return remove_home(value)
 if action=='instance.inspect-clone-candidate':return inspect_candidate(value)
 raise Refusal()
def main():
 try:
  check(os.geteuid()==0);out=perform(json.load(sys.stdin))
 except:out={'error_code':'instance.source_invalid'}
 print(json.dumps(out,separators=(',',':')))
`;

export type ProductionHomeExecutor = (
  program: string,
  body: Record<string, unknown>,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

const executeProductionHome: ProductionHomeExecutor = async (
  program,
  body,
  signal,
) => {
  signal?.throwIfAborted();
  const child = localCommand("/usr/bin/sudo", {
    args: ["-n", "--", "/usr/bin/python3", "-I", "-c", `${program}\nmain()\n`],
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
  const timer = setTimeout(abort, 900000);
  try {
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(JSON.stringify(body)));
    await writer.close();
    let size = 0;
    const parts: Uint8Array[] = [];
    for await (const part of child.stdout) {
      size += part.length;
      if (size > 2097152) throw new Error();
      parts.push(part);
    }
    if (!(await child.status).success || signal?.aborted) throw new Error();
    const bytes = new Uint8Array(size);
    let at = 0;
    for (const part of parts) {
      bytes.set(part, at);
      at += part.length;
    }
    return z.record(z.string(), z.unknown()).parse(
      JSON.parse(new TextDecoder("utf-8", { fatal: true }).decode(bytes)),
    );
  } finally {
    clearTimeout(timer);
    signal?.removeEventListener("abort", abort);
    abort();
    await child.status;
  }
};

/** Called only inside the parent's awaited private-material scope for source Git effects. */
export async function runLocalProductionHome(
  call: Pick<NodeCall, "name" | "input">,
  signal?: AbortSignal,
  execute = executeProductionHome,
): Promise<Record<string, unknown>> {
  try {
    const input = productionHomeInput(call);
    const read = input.git_read === undefined
      ? undefined
      : GitRead.parse(input.git_read);
    delete input.git_read;
    const body = {
      action: call.name,
      input,
      git_private: sourceGitCommand([], read, ["https", "ssh", "file"]),
      git_public: sourceGitCommand([]),
    };
    return await execute(productionHomeProgram, body, signal);
  } catch (error) {
    return {
      error_code: error instanceof Error &&
          [
            "instance.production_account_mismatch",
            "instance.source_kind_unavailable",
          ].includes(error.message)
        ? error.message
        : "instance.source_invalid",
    };
  }
}
