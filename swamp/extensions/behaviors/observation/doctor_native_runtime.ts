import type { Row } from "./doctor_contract.ts";
import { toolSystemProgram } from "../operations/tools/system_program.ts";
import { homebrewProgram } from "../operations/tools/homebrew_program.ts";

const object = (v: unknown): Row => {
  if (!v || typeof v !== "object" || Array.isArray(v)) {
    throw new Error("The native runtime observation is unavailable");
  }
  return v as Row;
};
const absent = () => ({
  present: false,
  status: null,
  systemd_observations: [],
});
const uuid =
  /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/;
const digest = async (text: string) =>
  Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text)),
    ),
    (v) => v.toString(16).padStart(2, "0"),
  ).join("");

function processIdentity(
  input: Row,
): { runtime: string; name: string; key: string } {
  const id = input.process_id, runtime = input.runtime, name = input.name;
  if (
    !Number.isInteger(id) || Number(id) < 1 ||
    !["systemd", "docker"].includes(String(runtime)) ||
    typeof name !== "string" ||
    !new RegExp(
      `^orbit-process-${id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?${
        runtime === "systemd" ? "\\.service" : ""
      }$`,
    ).test(name)
  ) throw new Error("Invalid admitted Process identity");
  return {
    runtime: String(runtime),
    name,
    key: `${runtime}:${
      runtime === "systemd" ? name.replace(/\.service$/, "") : name
    }`,
  };
}

/** Docker status and complete inventory come from the frozen continuous observer. */
export function processObservation(input: Row, observations: Row): Row {
  const { runtime, key } = processIdentity(input);
  if (
    runtime !== "docker" || observations.freshness !== "fresh" ||
    observations.docker !== "available"
  ) throw new Error("Fresh continuous Docker health is unavailable");
  const units = object(observations.units), status = units[key];
  if (status === undefined) return absent();
  if (
    typeof status !== "string" ||
    !["running", "created", "exited", "restarting", "paused", "dead"].includes(
      status,
    )
  ) throw new Error("The continuous Docker observation is incomplete");
  return {
    present: true,
    status: ["running", "created", "exited"].includes(status)
      ? status
      : "other",
    systemd_observations: [],
  };
}

/** Fixed read program. Inputs contain digests and admitted facts, never Schedule commands. */
export const runtimeReadProgram = String.raw`
import hashlib,ipaddress,json,os,pwd,grp,re,stat,subprocess,sys,time
from pathlib import Path
ROOT=Path('/')
LIMIT=4*1024*1024
class Unavailable(Exception):pass
def bounded(path):
 with open(path,'rb') as f:
  data=f.read(LIMIT+1)
  if len(data)>LIMIT:raise Unavailable()
  return data.decode('utf-8')
def run(argv):
 process_read=argv[:2]==['/usr/bin/systemctl','show']
 value=subprocess.run(argv,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE if process_read else subprocess.DEVNULL,timeout=3 if process_read else 15)
 if len(value.stdout)>LIMIT or process_read and value.stderr:raise Unavailable()
 return value.returncode,value.stdout.decode('utf-8').strip()
def hash_(value):return hashlib.sha256(value.encode()).hexdigest()
def subset(value,prefixes):return '\n'.join(line for line in value.splitlines() if any(line.startswith(p) for p in prefixes))
def inspect_process(data):
 id=data['process_id'];name=data['name'];desired=data['desired']
 if not isinstance(id,int) or id<1 or not re.fullmatch(r'orbit-process-'+str(id)+r'-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.service',name) or desired not in ('running','stopped'):raise Unavailable()
 path=ROOT/'etc/systemd/system'/name
 try:content=bounded(path)
 except FileNotFoundError:return {'present':False,'status':None,'systemd_observations':[]}
 if ('X-Orbit-Process-ID='+str(id)) not in content.splitlines():raise Unavailable()
 deadline=time.monotonic()+10
 def sample():
  code,output=run(['/usr/bin/systemctl','show','--property=ActiveState,SubState,NRestarts,LoadState','--',name])
  if code or time.monotonic()>deadline:raise Unavailable()
  props={}
  for line in output.splitlines():
   if '=' not in line:raise Unavailable()
   key,value=line.split('=',1)
   if key not in ('ActiveState','SubState','NRestarts','LoadState') or key in props:raise Unavailable()
   props[key]=value
  if props.get('LoadState')=='not-found':return None
  if props.get('LoadState')!='loaded' or props.get('ActiveState') not in ('active','reloading','inactive','failed','activating','deactivating','maintenance','unknown') or props.get('SubState') not in ('dead','condition','start-pre','start','start-post','running','exited','reload','reload-signal','reload-notify','stop','stop-watchdog','stop-sigterm','stop-sigkill','stop-post','final-watchdog','final-sigterm','final-sigkill','failed','dead-before-auto-restart','failed-before-auto-restart','dead-resources-pinned','auto-restart','auto-restart-queued','cleaning','mounting'):raise Unavailable()
  raw=props.get('NRestarts','');restarts=None
  if raw:
   if not re.fullmatch(r'0|[1-9][0-9]*',raw) or int(raw)>4294967295:raise Unavailable()
   restarts=int(raw)
  return {'active_state':props['ActiveState'],'sub_state':props['SubState'],'restarts':restarts}
 before=sample()
 if before is None:return {'present':False,'status':None,'systemd_observations':[]}
 samples=[before]
 if desired=='running' and not (before['active_state']=='activating' and before['sub_state']=='auto-restart'):
  if deadline-time.monotonic()<=2:raise Unavailable()
  time.sleep(2)
  after=sample()
  if after is None:return {'present':False,'status':None,'systemd_observations':[]}
  samples.append(after)
 return {'present':True,'status':{'active':'active','inactive':'inactive'}.get(samples[-1]['active_state'],'other'),'systemd_observations':samples}

def inspect_schedule(data):
 id=data['id']
 if not re.fullmatch(r'[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}',id):raise Unavailable()
 script=ROOT/'etc/orbit/schedules'/(id+'.sh');service=ROOT/'etc/systemd/system'/('orbit-schedule-'+id+'.service');timer=ROOT/'etc/systemd/system'/('orbit-schedule-'+id+'.timer')
 result={key:False for key in ('artifacts_present','permissions_match','specification_match','timer_state_match','calendar_match','execution_context_match','completion_callback_match')}
 try:metadata=[p.lstat() for p in (script,service,timer)]
 except FileNotFoundError:return result
 if not all(stat.S_ISREG(s.st_mode) for s in metadata):return result
 result['artifacts_present']=True
 contents=[bounded(p) for p in (script,service,timer)]
 try:
  account=pwd.getpwnam(data['user']);group=grp.getgrnam(data['group'])
  permissions=all(s.st_uid==0 and s.st_gid==gid and stat.S_IMODE(s.st_mode)==mode for s,gid,mode in zip(metadata,(group.gr_gid,0,0),(0o750,0o644,0o644)))
  if permissions:
   for flag in ('-r','-x'):
    options={} if os.geteuid()!=0 else {'user':account.pw_uid,'group':account.pw_gid,'extra_groups':os.getgrouplist(account.pw_name,account.pw_gid)}
    permissions=permissions and subprocess.run(['/usr/bin/test',flag,str(script)],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=5,**options).returncode==0
  result['permissions_match']=permissions and ('# X-Orbit-Schedule-ID='+id) in contents[0].splitlines() and all(('X-Orbit-Schedule-ID='+id) in text.splitlines() for text in contents[1:])
 except KeyError:result['permissions_match']=False
 result['specification_match']=[hash_(v) for v in contents]==data['hashes']
 enabled=run(['/usr/bin/systemctl','is-enabled','--','orbit-schedule-'+id+'.timer'])[1]
 active=run(['/usr/bin/systemctl','is-active','--','orbit-schedule-'+id+'.timer'])[1]
 if not enabled or not active:raise Unavailable()
 result['timer_state_match']=enabled=='enabled' and active=='active' if data['desired']=='enabled' else enabled!='enabled' and active!='active'
 result['calendar_match']=hash_(subset(contents[2],['OnCalendar=']))==data['calendar_hash']
 result['execution_context_match']=hash_(subset(contents[1],['User=','Group=','WorkingDirectory=','Environment=','ExecStart=']))==data['context_hash'] and hash_(subset(contents[0],['# X-Orbit-Execution-Context=']))==data['script_context_hash']
 result['completion_callback_match']=hash_(subset(contents[1],['ExecStopPost=']))==data['callback_hash']
 return result

def orphan_ids(data):
 known=data['known_ids'];found=set()
 if not isinstance(known,list) or any(not isinstance(v,str) for v in known):raise Unavailable()
 for directory,commands in [('etc/orbit/schedules',True),('etc/systemd/system',False)]:
  path=ROOT/directory
  if not path.exists():continue
  for entry in path.iterdir():
   if not stat.S_ISREG(entry.lstat().st_mode):continue
   match=re.fullmatch(r'(.+)\.sh' if commands else r'orbit-schedule-(.+)\.(?:service|timer)',entry.name)
   if match and re.fullmatch(r'[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}',match[1]) and match[1] not in known:found.add(match[1])
 return {'ids':sorted(found)}

def endpoint(value):
 if value in ('any','Anywhere','0.0.0.0/0','::/0'):return 'any'
 try:
  return str(ipaddress.ip_network(value,strict=False)) if '/' in value and int(value.split('/')[1]) not in (32,128) else str(ipaddress.ip_interface(value).ip)
 except ValueError:return None

def port(value):
 if not re.fullmatch(r'\d{1,5}(?::\d{1,5})?',value):return None
 numbers=[int(v) for v in value.split(':')]
 if any(v<1 or v>65535 for v in numbers) or len(numbers)==2 and numbers[0]>numbers[1]:return None
 return ':'.join(str(v) for v in numbers)

def interface(value):
 result=value.rsplit(' on ',1)
 if len(result)==2 and re.fullmatch(r'[a-zA-Z0-9_.:-]{1,64}',result[1]):return result[0].strip(),result[1]
 return value.strip(),None

def firewall_row(line):
 comment=line.rsplit('#',1)[1].strip() if '#' in line else ''
 row={'comment':comment,'shape':None}
 match=re.fullmatch(r'(?:\[\s*(\d+)\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?',line.strip())
 if not match or (match[6] or '')!=comment:return row
 family='v6' if '(v6)' in match[2] or '(v6)' in match[5] else 'v4'
 destination,out_interface=interface(match[2].replace('(v6)','').strip());source,in_interface=interface(match[5].replace('(v6)','').strip())
 target=re.fullmatch(r'(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:/(tcp|udp))?',destination)
 if target:destination,number,protocol=endpoint(target[1] or 'any'),port(target[2]),target[3] or 'any'
 else:destination,number,protocol=endpoint(destination),'any','any'
 source=endpoint(source)
 if destination is None or source is None or number is None:return row
 direction=match[4].lower()
 if direction=='in':in_interface,out_interface=out_interface or in_interface,None
 elif direction=='out':in_interface,out_interface=None,out_interface or in_interface
 row['shape']={'comment':comment,'action':match[3].lower(),'direction':direction,'source':source,'destination':destination,'port':number,'protocol':protocol,'in_interface':in_interface,'out_interface':out_interface,'family':family}
 return row

def firewall(data):
 if data.get('stored') is not False:raise Unavailable()
 try:code,text=run(['/usr/sbin/ufw','status','numbered'])
 except FileNotFoundError:return {'backend':'absent','ownerships':['missing']*len(data['expected']),'matches':False,'live':[]}
 if code:raise Unavailable()
 backend=[line.strip().lower()[8:] for line in text.splitlines() if line.strip().lower() in ('status: active','status: inactive','status: absent')]
 if len(backend)!=1:raise Unavailable()
 actual=[firewall_row(line) for line in text.splitlines()];actual=[r for r in actual if r['comment'] or r['shape']]
 def ownership(expected):
  owned=[r for r in actual if r['comment']==expected['comment']]
  if not owned:return 'missing'
  families=set()
  for row in owned:
   shape=row['shape']
   if not shape or shape!={**expected,'family':expected['family'] or shape['family']} or shape['family'] in families:return 'drift'
   families.add(shape['family'])
  return 'exact' if families==({expected['family']} if expected['family'] else {'v4','v6'}) else 'drift'
 owners=[ownership(e) for e in data['expected']]
 exclusive=all(not any(r['comment']==prefix or r['comment'].startswith(prefix+':') for prefix in data['exclusive_families']) or r['shape'] is not None and any(r['shape']=={**e,'family':e['family'] or r['shape']['family']} for e in data['expected']) for r in actual)
 return {'backend':backend[0],'ownerships':owners,'matches':backend[0]=='active' and exclusive and all(v=='exact' for v in owners),'live':[r['shape'] for r in actual if r['shape'] and r['shape']['family']!='v6']}

def hibernation(data):
 paths=data['paths']
 if not isinstance(paths,list) or len(paths)>1000 or any(not re.fullmatch(r'/dev/shm/orbit/hibernation/app-instance-[1-9][0-9]*\.awake',v) for v in paths):raise Unavailable()
 result={}
 for path in paths:
  try:value=int((ROOT/path.lstrip('/')).stat().st_mtime);result[path]=value if value>0 else None
  except FileNotFoundError:result[path]=None
 return {'times':result}

def runtime_read(name,data):
 return {'doctor.inspect-process':inspect_process,'doctor.inspect-schedule':inspect_schedule,'doctor.schedule-orphans':orphan_ids,'firewall.inspect':firewall,'hibernation.times':hibernation}[name](data)
if __name__=='__main__':
 try:
  payload=json.loads(sys.argv[1]);result=runtime_read(payload['name'],payload['input']);output=json.dumps(result,separators=(',',':'))
  if len(output)>LIMIT:raise Unavailable()
  print(output)
 except Exception:print('{"error_code":"observation.inspection_unavailable"}')
`;

/** Shared Tools native policy supplies inventories and manager custody checks. */
export const runtimeToolProgram = toolSystemProgram + homebrewProgram +
  String.raw`
def doctor_scan(host):
 prefix=brew_scope(host);tag=brew_tag(host)
 def inventory(cask):
  kind='--cask' if cask else '--formula'
  def call(args):return host.run([str(prefix/'bin/brew'),*args],env=BREW_ENV)[1]
  value=object_(json_(call(['info','--json=v2',kind,'--installed'])))
  entries=value.get('casks' if cask else 'formulae');other=value.get('formulae' if cask else 'casks')
  if not isinstance(entries,list) or other!=[]:refuse('tool.output_invalid')
  result=[];seen=set()
  for entry in entries:
   entry=object_(entry);name=entry.get('token' if cask else 'name');package('brew',name)
   if name in seen:refuse('tool.output_invalid')
   seen.add(name);dependency=False;block=None
   if cask:
    installed=entry.get('installed');installed=installed if version(installed) and installed!='latest' else None
    try:assess_cask(entry,name,prefix,host.home)
    except Refusal as error:
     block={'tool.homebrew_cask_source_unsupported':'unsupported_source','tool.homebrew_cask_artifact_unsupported':'unsupported_artifact','tool.homebrew_cask_authorization_required':'authorization_required','tool.homebrew_cask_checksum_unavailable':'unsupported_artifact','tool.homebrew_cask_version_unavailable':'version_unreadable'}.get(error.code)
     if block is None:raise
    if block is None and installed is None:block='version_unreadable'
   else:
    kegs=entry.get('installed')
    if not isinstance(kegs,list) or not kegs or any(not isinstance(k,dict) or not isinstance(k.get('installed_on_request'),bool) for k in kegs):refuse('tool.output_invalid')
    dependency=not any(k['installed_on_request'] for k in kegs);versions=[k.get('version') for k in kegs];installed=versions[0] if all(version(v) for v in versions) and len(set(versions))==1 else None
    if not official(entry,name):block='unsupported_source'
    elif name in ('wireguard-tools','wireguard-go'):block='protected'
    elif dependency:block='dependency'
    elif entry.get('disabled',False) is True:block='unsupported_artifact'
    elif not bottle(entry,tag):block='bottle_unavailable'
    elif installed is None:block='version_unreadable'
   result.append({'name':name,'installed_version':installed,'dependency':dependency,'block':block})
  text=call(['list',kind,'-1']).rstrip('\r\n');names=[] if not text else text.replace('\r\n','\n').split('\n')
  for name in names:package('brew',name)
  if len(set(names))!=len(names) or not seen.issubset(names):refuse('tool.output_invalid')
  for name in set(names)-seen:result.append({'name':name,'installed_version':None,'dependency':False,'block':'protected' if not cask and name in ('wireguard-tools','wireguard-go') else 'unsupported_source'})
  return {'state':'complete','packages':sorted(result,key=lambda v:v['name'])}
 def observed(cask):
  try:return inventory(cask)
  except Exception:return {'state':'incomplete','packages':[]}
 return {'formulae':observed(False),'casks':observed(True) if host.node['platform']=='macos' else {'state':'unsupported','packages':[]}}

def doctor_store(host,data):
 environments=data['environments']
 if not isinstance(environments,list) or len(environments)>1000:refuse('tool.store_invalid')
 def installed(env):
  env=object_(env);store=Path(env['store']);nv=env['node_version'];manager=env['package_manager'];mv=env['package_manager_version']
  if not str(store).startswith('/') or '..' in store.parts or (env.get('package_manager_hash') is not None and (not isinstance(env['package_manager_hash'],str) or not re.fullmatch(r'sha(?:224|256|384|512)\.[a-f0-9]+',env['package_manager_hash'],re.I))) or manager not in ('npm','pnpm') or not all(isinstance(v,str) and re.fullmatch(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?',v) for v in (nv,mv)):refuse('tool.store_invalid')
  for path in (store,store/'js_runtime',store/'js_runtime/node',store/'js_runtime/node'/nv,store/'package_manager',store/'package_manager'/manager,store/'package_manager'/manager/mv):
   s=metadata(path)
   if s and (s.st_uid!=host.account.pw_uid or not stat.S_ISDIR(s.st_mode)):refuse('tool.store_invalid')
  node=store/'js_runtime/node'/nv/'bin/node';package=store/'package_manager'/manager/mv/manager
  if not node.is_file() or not os.access(node,os.X_OK):return False
  manifest=package/'package.json'
  if not manifest.exists():return False
  value=json_(read(manifest,host.account.pw_uid).decode())
  executable=package/'bin'/manager
  return isinstance(value,dict) and value.get('name')==manager and value.get('version')==mv and executable.is_file() and os.access(executable,os.X_OK)
 return {'installed':[installed(env) for env in environments]}

def doctor_tool(name,data,node):
 commands={'tool.apt-inventory':('apt','inventory'),'tool.composer-inventory':('composer','inventory'),'tool.vp-inventory':('vp','inventory'),'tool.vp-version':('vp','version'),'tool.inspect-vp-scope':('vp','scope'),'tool.homebrew-formula-inventory':('brew','inventory'),'tool.homebrew-cask-inventory':('brew-cask','inventory'),'tool.homebrew-scan':('brew','scan'),'tool.inspect-vp-store':('vp','store')}
 manager,verb=commands[name];node={**node,'manager':manager};host=Host(node)
 if node['platform'] not in ('linux','macos') or node['platform']=='macos' and manager in ('apt','composer') or node['platform']=='linux' and manager=='brew-cask':refuse('tool.manager_unsupported')
 if verb=='scan':return doctor_scan(host)
 if verb=='store':return doctor_store(host,data)
 return {'apt':apt,'composer':composer,'vp':vp,'brew':brew,'brew-cask':brew}[manager](host,verb,{})
if __name__=='__main__':
 try:
  payload=json.loads(sys.argv[1]);result=doctor_tool(payload['name'],payload['input'],payload['node'])
 except Refusal as error:
  code=error.code
  if code.startswith('tool.scope_'):
   name=payload['name'];manager='homebrew' if 'homebrew' in name else 'composer' if 'composer' in name else 'vp';code='tool.'+manager+'_scope_'+code[11:]
  result={'error_code':code}
 except Exception:result={'error_code':'tool.inspection_unavailable'}
 print(json.dumps(result,separators=(',',':')))
`;

function select(text: string, prefixes: string[]): string {
  return text.split("\n").filter((line) =>
    prefixes.some((p) => line.startsWith(p))
  ).join("\n");
}
async function scheduleIntent(input: Row): Promise<Row> {
  if (
    typeof input.id !== "string" || !uuid.test(input.id) ||
    typeof input.command !== "string" || typeof input.calendar !== "string" ||
    !["enabled", "disabled"].includes(String(input.desired))
  ) throw new Error("Invalid admitted Schedule specification");
  for (const key of ["user", "group"]) {
    if (
      typeof input[key] !== "string" ||
      !/^[a-z_][a-z0-9_-]{0,31}$/.test(String(input[key]))
    ) throw new Error("Invalid admitted Schedule account");
  }
  for (const key of ["home", "working_directory", "shell"]) {
    if (
      typeof input[key] !== "string" || !String(input[key]).startsWith("/") ||
      Array.from(String(input[key])).some((char) =>
        char.charCodeAt(0) < 32 || char.charCodeAt(0) === 127
      )
    ) throw new Error("Invalid admitted Schedule context");
  }
  const id = input.id, agent = "/usr/local/bin/orbit-agent";
  const context = {
    user: input.user,
    group: input.group,
    home: input.home,
    working_directory: input.working_directory,
    shell: input.shell,
    login_shell: input.login_shell,
  };
  const quote = (v: string) =>
    `"${
      v.replaceAll("\\", "\\\\").replaceAll('"', '\\"').replaceAll("%", "%%")
    }"`;
  const script = `# X-Orbit-Schedule-ID=${id}\n# X-Orbit-Execution-Context=${
    JSON.stringify(context)
  }\n${input.command}\n`;
  const service =
    `[Unit]\nDescription=Orbit Schedule ${id}\nX-Orbit-Schedule-ID=${id}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser=${input.user}\nGroup=${input.group}\nWorkingDirectory=${
      String(input.working_directory).replaceAll("%", "%%")
    }\nEnvironment=${quote(`HOME=${input.home}`)}\nEnvironment=${
      quote(`SHELL=${input.shell}`)
    }\nExecStart=${agent} service schedules-run ${id}\nExecStopPost=+${agent} service schedules-complete ${id}\nTimeoutStartSec=${input.timeout_seconds}\n`;
  const timer =
    `[Unit]\nDescription=Orbit Schedule timer ${id}\nX-Orbit-Schedule-ID=${id}\n\n[Timer]\nOnCalendar=${
      input.calendar.replaceAll("%", "%%")
    }\nPersistent=true\nUnit=orbit-schedule-${id}.service\n\n[Install]\nWantedBy=timers.target\n`;
  return {
    id,
    user: input.user,
    group: input.group,
    desired: input.desired,
    hashes: await Promise.all([script, service, timer].map(digest)),
    calendar_hash: await digest(select(timer, ["OnCalendar="])),
    context_hash: await digest(
      select(service, [
        "User=",
        "Group=",
        "WorkingDirectory=",
        "Environment=",
        "ExecStart=",
      ]),
    ),
    script_context_hash: await digest(
      select(script, ["# X-Orbit-Execution-Context="]),
    ),
    callback_hash: await digest(select(service, ["ExecStopPost="])),
  };
}
const toolSelectors = new Set([
  "tool.apt-inventory",
  "tool.composer-inventory",
  "tool.vp-inventory",
  "tool.vp-version",
  "tool.inspect-vp-scope",
  "tool.inspect-vp-store",
  "tool.homebrew-formula-inventory",
  "tool.homebrew-cask-inventory",
  "tool.homebrew-scan",
]);

/** Fixed native reads only; undefined delegates selectors owned by other Doctor families. */
export async function readRuntime(
  name: string,
  input: Row,
  local: (argv: string[]) => Promise<string>,
  observations: Row = {},
): Promise<Row | undefined> {
  const process = name === "doctor.inspect-process"
    ? processIdentity(input)
    : null;
  if (process?.runtime === "docker") {
    return processObservation(input, observations);
  }
  const tool = toolSelectors.has(name);
  if (
    !tool &&
    ![
      "doctor.inspect-process",
      "doctor.inspect-schedule",
      "doctor.schedule-orphans",
      "firewall.inspect",
      "hibernation.times",
    ].includes(name)
  ) return undefined;
  const data = name === "doctor.inspect-schedule"
    ? await scheduleIntent(input)
    : input;
  const node = tool ? object(observations.node) : undefined;
  const payload = JSON.stringify({
    name,
    input: data,
    ...(node
      ? {
        node: {
          user: node.user,
          platform: node.platform,
          architecture: node.architecture,
        },
      }
      : {}),
  });
  const python = [
    "/usr/bin/python3",
    "-c",
    tool ? runtimeToolProgram : runtimeReadProgram,
    payload,
  ];
  const argv = !tool || node?.platform === "linux"
    ? ["/usr/bin/sudo", "-n", "--", ...python]
    : python;
  const result = object(JSON.parse(await local(argv)));
  if (typeof result.error_code === "string") throw result;
  if (
    process?.runtime === "systemd" && result.present === true &&
    observations.freshness === "fresh"
  ) {
    const status = object(observations.units)[process.key];
    if (status !== undefined) {
      if (
        typeof status !== "string" ||
        ![
          "active",
          "inactive",
          "failed",
          "activating",
          "deactivating",
          "reloading",
          "maintenance",
          "unknown",
        ].includes(status)
      ) throw new Error("The continuous systemd status is incomplete");
      result.status = status === "active"
        ? "active"
        : status === "inactive"
        ? "inactive"
        : "other";
    }
  }
  return result;
}
