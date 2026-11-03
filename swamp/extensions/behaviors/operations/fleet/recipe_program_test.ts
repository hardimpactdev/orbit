/** Recipe policy cases: one isolated Python observation per Deno test. */
import { assertEquals, assertMatch, assertObjectMatch } from "jsr:@std/assert@1.0.19";
import { python } from "./recipe_fixture_test.ts";

type Case = [string, string, unknown];
const refusal = (code = "node.recipe_failed", extra = {}) => ({ class: "RecipeRefusal", code, ...extra });
const groups: Record<string, Case[]> = {
RecipeIncusPolicy: [
["current owned install does not require draining active instances", String.raw`
f.instances=[{'project':'other','name':'busy','type':'container','status':'Running'}]
print(json.dumps([f.preflight(),any(a[0]=='incus' for a in f.calls)]))`, [{ready:true},false]],
["pin change requires every project instance stopped", String.raw`
f.installed['incus']='7.0.0';errors=[]
for status in ('Running','Frozen','Error','Starting','Stopping'):
 f.instances=[{'project':'foreign','name':'busy','type':'virtual-machine','status':status}];errors.append(error(f.preflight))
f.instances=[{'project':'foreign','name':'stopped','type':'virtual-machine','status':'Stopped'}]
print(json.dumps([errors,f.preflight(),['incus','list','--all-projects','--format=json'] in f.calls]))`, [Array(5).fill(refusal('node.recipe_incus_busy')),{ready:true},true]],
["source pin or obsolete agent changes also require draining", String.raw`
errors=[]
for cause in ('source','preference','agent'):
 if cause=='source':f.host.path('/etc/apt/sources.list.d/incus.sources').write_text('Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0\nSuites: resolute\nComponents: main\n')
 elif cause=='preference':f.host.path('/etc/apt/preferences.d/orbit-incus').write_text('old')
 else:f.installed['incus-agent']='7.0.1'
 f.instances=[{'project':'default','name':'busy','type':'container','status':'Running'}];errors.append(error(f.preflight))
print(json.dumps(errors))`,Array(3).fill(refusal("node.recipe_incus_busy"))],
["fresh install needs no Incus daemon", String.raw`
f.installed={};print(json.dumps([f.preflight(),any(a[0]=='incus' for a in f.calls)]))`,[{ready:true},false]],
["malformed inventory platform and Docker provider refuse", String.raw`
f.installed['incus']='old';f.instances=[{'status':'Stopped'}];a=error(f.preflight)
f.host.path('/etc/os-release').write_text('ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n');b=error(f.preflight)
f.host.path('/etc/os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n');f.installed['docker-ce']='1'
print(json.dumps([a,b,error(f.preflight)]))`,[refusal(),refusal(),refusal('node.recipe_docker_provider')]],
["mixed provider and symlink preference refuse without mutation", String.raw`
p=f.host.path('/etc/apt/sources.list.d/incus.sources');content='Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0 https://untrusted.test\nSuites: resolute\nComponents: main\n';p.write_text(content)
a=error(f.preflight);unchanged=p.read_text();p.unlink();p=f.host.path('/etc/apt/preferences.d/orbit-incus');p.unlink();p.symlink_to('/dev/null')
print(json.dumps([a,unchanged,error(f.preflight)]))`,[refusal(),"Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0 https://untrusted.test\nSuites: resolute\nComponents: main\n",refusal()]],
["provider retirement preserves unrelated paragraphs and conflicting backup", String.raw`
p=f.host.path('/etc/apt/sources.list.d/mixed.sources');other='Types: deb\nURIs: https://other.test\nSuites: other\nComponents: main\n';own='Types: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0\nSuites: resolute\nComponents: main\n';p.write_text(other+'\n'+own)
changes=ns['provider_changes'](f.host,'incus');f.host.path('/etc/apt/mixed.sources.orbit-incus-backup').write_text('foreign')
print(json.dumps([changes[0][2].decode(),error(lambda:ns['provider_changes'](f.host,'incus')),p.read_text()]))`,["Types: deb\nURIs: https://other.test\nSuites: other\nComponents: main\n\n",refusal(),"Types: deb\nURIs: https://other.test\nSuites: other\nComponents: main\n\nTypes: deb\nURIs: https://pkgs.zabbly.com/incus/lts-7.0\nSuites: resolute\nComponents: main\n"]],
],
RecipeSystemPrograms: [
["archive escape link pivot and expanded bound refuse before writes", String.raw`
results=[]
for i,member in enumerate([('../escape',b'x','file'),('/escape',b'x','file'),('dir/link','../../escape','link')]):
 archive=f.archive('bad'+str(i)+'.tgz',[member]);output=f.root/('output'+str(i));results.append([error(lambda:ns['extract_archive'](archive,output)),output.exists()])
archive=f.archive('pivot.tgz',[('dir','safe','link'),('dir/payload',b'bad','file')]);pivot=error(lambda:ns['extract_archive'](archive,f.root/'pivot'))
ns['MAX_EXPANDED']=1;archive=f.archive('oversize.tgz',[('payload',b'too big','file')])
print(json.dumps([results,pivot,error(lambda:ns['extract_archive'](archive,f.root/'oversize'))]))`,[Array(3).fill([refusal(),false]),refusal(),refusal()]],
["archive root directory marker is allowed", String.raw`
archive=f.archive('root-marker.tgz',[('.',b'','directory'),('./tool',b'verified','file')]);target=f.root/'root-marker';ns['extract_archive'](archive,target)
print(json.dumps((target/'tool').read_text()))`,"verified"],
["archive preserves safe relative links and clears special permissions", String.raw`
archive=f.archive('good.tgz',[('runtime/bin/node',b'ELF','file'),('runtime/bin/npm','../lib/npm.js','link'),('runtime/lib/npm.js',b'js','file'),('runtime/bin/second','runtime/bin/node','hardlink')]);target=f.root/'unpacked';ns['extract_archive'](archive,target)
print(json.dumps([(target/'runtime/bin/npm').read_text(),(target/'runtime/bin/second').read_text(),(target/'runtime/bin/node').stat().st_mode&0o7022]))`,["js","ELF",0]],
["complete JavaScript install uses verified archives and managed scope", String.raw`
node=f.archive('node.tgz',[('node-v22.1.0-linux-x64/bin/node',b'ELF','file')]);vp=f.archive('vp.tgz',[('vp',b'ELF','file')]);pnpm=f.archive('pnpm.tgz',[('package/package.json',b'{"version":"10.1.0"}','file'),('package/bin/pnpm.cjs',b'js','file')]);data=f.data({'node':f.pin('22.1.0',node),'vp-cli':f.pin('0.2.0',vp),'pnpm':f.pin('10.1.0',pnpm)})
r=ns['install_javascript'](f.host,data,f.account,{'node':node,'vp-cli':vp,'pnpm':pnpm});scope=f.account['home']/'.local/share/vite-plus'
print(json.dumps([r,(scope/'js_runtime/node/22.1.0').is_symlink(),json.loads((scope/'.orbit-recipe-node.json').read_text()),str((scope/'bin/node').readlink()),'VP_HOME=' in f.host.path('/usr/local/bin/node').read_text(),any('orbit-agent' in str(a) for a in f.calls),ns['install_javascript'](f.host,data,f.account)]))`,[{changed:true,retired:[]},false,{version:"22.1.0"},"vp",true,false,{changed:false,retired:[]}]],
["bad checksum refuses before runtime or launcher publication", String.raw`
archive=f.archive('bad-checksum.tgz',[('vp',b'ELF','file')]);pin=f.pin('0.2.0',archive);pin['artifacts']['amd64']['sha256']='a'*64
print(json.dumps([error(lambda:ns['install_javascript'](f.host,f.data({'vp-cli':pin}),f.account,{'vp-cli':archive})),f.host.path('/usr/local/bin/vp').exists(),(f.account['home']/'.local/share/vite-plus/bin/vp').exists()]))`,[refusal(),false,false]],
["same version Node tree must match verified archive", String.raw`
archive=f.archive('node.tgz',[('node-v22.1.0-linux-x64/bin/node',b'ELF','file')]);scope=f.account['home']/'.local/share/vite-plus';scope.mkdir();ns['publish_node'](f.host,scope,f.account,'22.1.0',archive);p=scope/'js_runtime/node/22.1.0/bin/node';p.write_bytes(b'foreign')
print(json.dumps([error(lambda:ns['publish_node'](f.host,scope,f.account,'22.1.0',archive)),p.read_text()]))`,[refusal(),"foreign"]],
["foreign or symlink runtime scope refuses", String.raw`
scope=f.account['home']/'.local/share/vite-plus';scope.symlink_to(f.root);a=error(lambda:ns['runtime_scope'](f.host,f.account));scope.unlink();scope.mkdir();(scope/'js_runtime').symlink_to(f.root);archive=f.archive('node.tgz',[('node-v22.1.0-linux-x64/bin/node',b'ELF','file')])
print(json.dumps([a,error(lambda:ns['publish_node'](f.host,scope,f.account,'22.1.0',archive))]))`,[refusal(),refusal()]],
["foreign file is not replaced by owned publication", String.raw`
p=f.host.path('/usr/local/bin/node');p.write_text('foreign');account={**f.account,'uid':f.uid+1}
print(json.dumps([error(lambda:ns['publish_owned'](f.host,p,b'replacement',account)),p.read_text()]))`,[refusal(),"foreign"]],
],
PhpBuildPrograms: [
["current PHP build runs no package or service action", String.raw`
r=ns['install_php_build'](f.host,f.input)
print(json.dumps([r['outcome'],r['data']['changed'],any(a[0] in ('apt-get','curl') or a[:2] in (['systemctl','reload'],['systemctl','restart']) for a in f.calls)]))`,["current",[],false]],
["PHP upgrade verifies installs and keeps default pool disabled", String.raw`
f.upgrade();r=ns['install_php_build'](f.host,f.input)
print(json.dumps([r['outcome'],f.installed,(f.root/'etc/php/8.5/fpm/pool.d/www.conf').exists(),(f.root/'usr/sbin/policy-rc.d').exists(),all('--proto-redir' in a for a in f.calls if a[0]=='curl'),r['data']['changed']]))`,["applied",{"php8.5-cli":"8.5.2","php8.5-fpm":"8.5.2"},false,false,true,["php8.5-cli","php8.5-fpm"]]],
["module failure restores old packages and original ini", String.raw`
f.upgrade();f.module_failure=True;p=f.root/'etc/php/8.5/cli/conf.d/99-cve-2026-109618-upload-progress.ini';p.write_text('old local bytes')
a=error(lambda:ns['install_php_build'](f.host,f.input))
print(json.dumps([a,f.installed,p.read_text(),(f.root/'usr/sbin/policy-rc.d').exists()]))`,[refusal('php.build_modules_mismatch',{restored:true}),{"php8.5-cli":"8.5.1","php8.5-fpm":"8.5.1"},"old local bytes",false]],
["failed package rollback never claims restoration", String.raw`
f.upgrade();f.module_failure=True;f.rollback_failure=True
print(json.dumps(error(lambda:ns['install_php_build'](f.host,f.input))))`,refusal('php.build_rollback_failed',{restored:false})],
["foreign PHP source and extra package refuse before effects", String.raw`
p=f.root/'etc/apt/sources.list.d/php.sources';p.write_text('URIs: https://packages.sury.org/php\n');a=error(lambda:ns['install_php_build'](f.host,f.input));exists=p.exists();download=any(a[0]=='curl' for a in f.calls);p.unlink();f.installed['php8.5-foreign']='8.5.1'
print(json.dumps([a,exists,download,error(lambda:ns['install_php_build'](f.host,f.input))]))`,[refusal('php.build_conflicting_source'),true,false,refusal('php.build_extra_packages')]],
["conflicting shared PHP pin refuses before inventory or effects", String.raw`
second=copy.deepcopy(f.input['versions']['8.5']);second['packages'][0]['sha256']='f'*64;f.input['versions']['8.6']=second
print(json.dumps([error(lambda:ns['install_php_build'](f.host,f.input)),f.calls]))`,[refusal('php.build_input_invalid'),[]]],
["FPM validation rolls back before restart", String.raw`
f.upgrade();f.input['fpm']='all';f.active=True;f.fpm_invalid=True
print(json.dumps([error(lambda:ns['install_php_build'](f.host,f.input)),any(a[:2]==['systemctl','restart'] for a in f.calls)]))`,[refusal('php.build_fpm_invalid',{restored:true}),false]],
],
RecipeApplyPrograms: [
["package apply pins snapshot and plans changed package services", String.raw`
r=ns['apply_packages'](f.host,f.data,f.account);plan=ns['service_plan'](f.host,r);install=next(a for a in f.calls if a[:2]==['apt-get','install'])
print(json.dumps([r,f.installed['curl'],any(a[:2]==['systemctl','restart'] for a in f.calls),plan,(f.root/'usr/sbin/policy-rc.d').exists(),'--no-remove' in install,'--allow-downgrades' in install,install[-1]]))`,[{changed_packages:["curl"],changed_tools:["curl"],restarted_services:["curl-daemon.service"],newer_than_recipe:{},healthy:true,restart_units:["curl-daemon.service"],health_units:["curl-daemon.service"]},"8.1",false,{restart_units:["curl-daemon.service"],health_units:["consumer.service","curl-daemon.service"]},false,true,false,"curl=8.1"]],
["current package preserves healthy units without restart", String.raw`
f.installed['curl']='8.1';r=ns['apply_packages'](f.host,f.data,f.account)
print(json.dumps([r['changed_packages'],r['restarted_services'],any(a[:2]==['systemctl','restart'] for a in f.calls)]))`,[[],[],false]],
["newer trusted Ubuntu package is preserved", String.raw`
f.installed['curl']='9.0';r=ns['apply_packages'](f.host,f.data,f.account)
print(json.dumps([f.installed['curl'],r['newer_than_recipe'],any(a[:2]==['apt-get','install'] for a in f.calls)]))`,["9.0",{curl:{installed:"9.0",version:"8.1"}},false]],
["dependency downgrade and malformed simulation refuse before install", String.raw`
f.plan='Inst unrelated [9.0] (8.0 Ubuntu:26.04/resolute [amd64])\n';a=error(lambda:ns['exact_deb_install'](f.host,[f.root/'incus.deb'],{}, {'unrelated':'9.0'},[]));installed=any(a[:2]==['apt-get','install'] and '--simulate' not in a for a in f.calls);f.plan='Inst ???\n'
print(json.dumps([a,installed,error(lambda:ns['exact_deb_install'](f.host,[f.root/'incus.deb'],{}, {},[]))]))`,[refusal(),false,refusal()]],
["exact artifact downgrade is permitted", String.raw`
f.plan='Inst incus [9.0] (8.0 local-deb [amd64])\n';ns['exact_deb_install'](f.host,[f.root/'incus.deb'],{}, {'incus':'9.0'},[])
print(json.dumps(any(a[:2]==['apt-get','install'] and '--simulate' not in a for a in f.calls)))`,true],
["existing restart policy refuses before source mutation", String.raw`
p=f.root/'usr/sbin/policy-rc.d';p.write_text('operator policy');a=error(lambda:ns['apply_packages'](f.host,f.data,f.account))
print(json.dumps([a,p.read_text(),any(a[0]=='apt-get' for a in f.calls)]))`,[refusal(),"operator policy",false]],
["dependent health failure refuses and restart policy is removed", String.raw`
f.failed_unit='consumer.service';r=ns['apply_packages'](f.host,f.data,f.account);plan=ns['service_plan'](f.host,r)
def health():
 for unit in plan['health_units']:ns['service_health'](f.host,unit)
print(json.dumps([error(health),(f.root/'usr/sbin/policy-rc.d').exists()]))`,[refusal(undefined,{stage:"health:consumer.service"}),false]],
["unsigned and mixed origins require trusted source evidence", String.raw`
global_text='  500 https://third-party.test/ubuntu resolute/main amd64 Packages\n      release o=Ubuntu,a=resolute,n=resolute,c=main,b=amd64\n';package_text='Version table:\n *** 9.0 500\n        500 https://third-party.test/ubuntu resolute/main amd64 Packages\n        100 /var/lib/dpkg/status\n'
print(json.dumps([ns['parse_ubuntu_origin'](global_text,package_text,'9.0',set()),ns['parse_ubuntu_origin'](global_text,package_text,'9.0',{('https://third-party.test/ubuntu','resolute/main')})]))`,[{trusted:false,untrusted:true,installed:true},{trusted:true,untrusted:false,installed:true}]],
],
RecipeBrowserPrograms: [
["browser install pins root tree and exact executable policy", String.raw`
pin,archive=f.archive();ns['install_browser'](f.host,pin,archive);policy=f.host.path(ns['BROWSER_POLICY']).read_text()
print(json.dumps([ns['browser_installed'](f.host,pin),policy,sum('--skip-kernel-load' in a for a in f.calls),any('systemctl' in a for a in f.calls)]))`,["130.0.1.2",{includes:"/opt/orbit/chrome-headless-shell/130.0.1.2/chrome-headless-shell-linux64/chrome-headless-shell flags=(unconfined)"},1,false]],
["changed browser binary and unrelated policy refuse", String.raw`
pin,archive=f.archive();ns['install_browser'](f.host,pin,archive);binary=f.host.path(ns['BROWSER_BASE']+'/'+pin['version'])/ns['BROWSER_EXECUTABLE'];binary.write_bytes(b'foreign');a=error(lambda:ns['browser_installed'](f.host,pin));p=f.host.path(ns['BROWSER_POLICY']);p.write_text('unrelated admin policy')
print(json.dumps([a,error(lambda:ns['install_browser'](f.host,pin,archive)),p.read_text()]))`,[refusal(),refusal(),"unrelated admin policy"]],
["kernel load failure restores previous browser policy", String.raw`
old,archive=f.archive();ns['install_browser'](f.host,old,archive);previous=f.host.path(ns['BROWSER_POLICY']).read_text();new,archive=f.archive('131.0.1.2',b'new verified Chrome');f.replace_failure=True;a=error(lambda:ns['install_browser'](f.host,new,archive))
print(json.dumps([a,f.host.path(ns['BROWSER_POLICY']).read_text()==previous,f.host.path(ns['BROWSER_BASE']+'/'+old['version']).is_dir()]))`,[refusal(),true,true]],
["caller controlled browser URL refuses before effects", String.raw`
pin,archive=f.archive();pin['artifacts']['amd64']['url']='https://unrelated.test/chrome.zip'
print(json.dumps([error(lambda:ns['install_browser'](f.host,pin,archive)),f.host.path(ns['BROWSER_BASE']).exists(),f.calls]))`,[refusal(),false,[]]],
],
RecipeHistoricalOrigins: [
["historical failures are cached with a three timestamp bound", String.raw`
with tempfile.TemporaryDirectory() as tmp:
 calls=[]
 class Host:
  def run(self,argv,required=True,timeout=600,environment=None):calls.append((argv,environment));raise ns['RecipeRefusal']('snapshot-unavailable')
 snapshots=ns['SnapshotOrigins'](Host(),Path(tmp));errors=[]
 for timestamp in ('20261030T000000Z','20261030T010000Z','20261030T020000Z'):
  for _ in range(2):errors.append(error(lambda:snapshots.origin('curl','9.0',timestamp)))
 before=len(calls);last=error(lambda:snapshots.origin('curl','9.0','20261030T030000Z'))
 print(json.dumps([errors,before,last,len(calls),[[a[:2],'-c' in a,env['APT_CONFIG']==a[a.index('-c')+1],'Debug::NoLocking=true' in a] for a,env in calls]]))`,[Array(6).fill(refusal()),3,refusal(undefined,{stage:"snapshot-lookup-limit"}),3,Array(3).fill([["apt-get","update"],true,true,true])]],
["install timestamp uses only the matching installed version", String.raw`
with tempfile.TemporaryDirectory() as tmp:
 root=Path(tmp);(root/'var/log').mkdir(parents=True);(root/'var/log/dpkg.log').write_text('2026-10-30 00:00:00 install curl:amd64 <none> 8.0\n2026-10-31 01:02:03 upgrade curl:amd64 8.0 9.0\n2026-10-31 01:02:04 status installed curl:amd64 9.0\n');host=ns['RecipeHost'](root,os.getuid(),os.getgid())
 print(json.dumps([ns['install_snapshot'](host,'curl','9.0'),ns['install_snapshot'](host,'curl','10.0')]))`,[{matches:"^202610[0-9]{2}T[0-9]{6}Z$",differs:"20261030T000000Z"},null]],
],
RecipeDeliveryPolicy: [
["delivery reads observer bytes and invokes CLI as managed account", String.raw`
r=ns['verify_recipe_delivery'](f.host,f.input,f.account)
print(json.dumps([r,len(f.calls),f.calls[0][:4]]))`,[{identity:"sha256:"+"f".repeat(64),agent_sha256:"0db7a95c040d4df9a6d7e242fab77c09acb5fe8186275a657aa6c03b0f2924bf",cli_version:"0.12.0"},1,["sudo","-n","-u","test"]]],
["changed observer refuses before CLI execution", String.raw`
(f.root/'usr/local/bin/orbit-agent').write_bytes(b'wrong binary')
print(json.dumps([error(lambda:ns['verify_recipe_delivery'](f.host,f.input,f.account)),f.calls]))`,[refusal(),[]]],
["foreign CLI link refuses without execution", String.raw`
p=f.root/'usr/local/bin/orbit';p.unlink();p.symlink_to('/tmp/foreign-executable')
print(json.dumps([error(lambda:ns['verify_recipe_delivery'](f.host,f.input,f.account)),f.calls]))`,[refusal(),[]]],
],
};

// Match refusal fields without coupling cases to unrelated exception metadata.
function check(actual: unknown, expected: unknown): void {
  if (Array.isArray(expected)) {
    assertEquals(Array.isArray(actual), true);
    const values = actual as unknown[];
    assertEquals(values.length, expected.length);
    expected.forEach((value, i) => check(values[i], value));
  } else if (expected && typeof expected === "object" && "class" in expected) {
    assertObjectMatch(actual as Record<string, unknown>, expected);
    assertEquals(typeof (actual as Record<string, unknown>).code, "string");
  } else if (expected && typeof expected === "object" && "includes" in expected) {
    assertEquals((actual as string).includes(expected.includes as string), true);
  } else if (expected && typeof expected === "object" && "matches" in expected && "differs" in expected) {
    assertMatch(actual as string, new RegExp(expected.matches as string));
    assertEquals(actual === expected.differs, false);
  } else assertEquals(actual, expected);
}
for (const [group, cases] of Object.entries(groups)) {
  for (const [name, source, expected] of cases) {
    Deno.test(name, async () => check(await python(group, source), expected));
  }
}
