/** One Python driver and Deno-owned expectations for each original policy case. */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { python } from "./system_fixture_test.ts";
const cases: [string, string, unknown][] = [
["candidate account resolves without worker or persisted identity", String.raw`
observations=[]
with patch.object(M.pwd,'getpwnam',return_value=candidate),patch.object(M.os,'geteuid',return_value=0):
 for manager in ('composer','vp'):
  host=M.Host({'manager':manager,'platform':'linux','user':'candidate'});observations.append([vars(host.account),host.env['HOME']])
 with patch.object(M.os,'geteuid',return_value=1000):denied=error(lambda:M.Host({'manager':'vp','platform':'linux','user':'candidate'}))
node={'node_id':17,'manager':'vp','platform':'linux','architecture':'x86_64','user':'candidate'}
with patch.object(M,'Host') as host:
 conflict=error(lambda:M.dispatch({'name':'tool.materialize-vp','input':{'user':'prior'},'node':node}));effects=host.call_count
print(json.dumps([observations,denied,conflict,node['user'],effects]))`, [Array(2).fill([{pw_uid:2101,pw_gid:2102,pw_name:"candidate",pw_dir:"/home/candidate"},"/home/candidate"]),["Refusal","tool.account_invalid"],["Refusal","tool.account_invalid"],"candidate",0]],
["manager subprocess drops to candidate uid and home", String.raw`
child=types.SimpleNamespace(stdout=io.BytesIO(),stderr=io.BytesIO(),wait=lambda **kw:0,poll=lambda:0);selector=types.SimpleNamespace(register=lambda *args:None,get_map=lambda:{},close=lambda:None)
with patch.object(M.pwd,'getpwnam',return_value=candidate),patch.object(M.os,'geteuid',return_value=0),patch.object(M.os,'getgrouplist',return_value=[2102]),patch.object(M.selectors,'DefaultSelector',return_value=selector),patch.object(M.subprocess,'Popen',return_value=child) as launch:
 host=M.Host({'manager':'composer','platform':'linux','user':'candidate'});host.run(['/usr/bin/composer','--version']);args,options=launch.call_args
 print(json.dumps([options['user'],options['group'],options['cwd'],options['env']['HOME'],'shell' in options,'SUDO_USER' in options['env']]))`,[2101,2102,"/home/candidate","/home/candidate",false,false]],
["dispatch refuses foreign managers and unsupported platforms before effects", String.raw`
node=dict(manager='apt',user='candidate',platform='linux',architecture='x86_64');errors=[]
with patch.object(M,'Host') as host:
 for name,data in [('arbitrary',{}),('tool.vp-install',{'package':'semver'}),('tool.apt-install',{'package':'hello','argv':[]}),('tool.materialize-composer',{'user':'candidate'})]:errors.append(error(lambda:M.dispatch({'name':name,'input':data,'node':node})))
 errors.append(error(lambda:M.dispatch({'name':'tool.apt-install','input':{'package':'hello'},'node':{**node,'platform':'macos'}})))
 print(json.dumps([errors,host.call_count]))`,[[["Refusal","tool.input_invalid"],["Refusal","tool.input_invalid"],["Refusal","tool.input_invalid"],["Refusal","tool.input_invalid"],["Refusal","tool.manager_unsupported"]],0]],
["scope observations refuse foreign ownership and symlinks", String.raw`
with tempfile.TemporaryDirectory() as tmp:
 path=Path(tmp)/'scope';path.mkdir();owned=M.owned(path,os.getuid(),os.getgid());foreign=error(lambda:M.owned(path,os.getuid()+1));alias=Path(tmp)/'alias';alias.symlink_to(path);linked=error(lambda:M.owned(alias,os.getuid()));target=path/'manifest';target.write_text('{}');link=path/'linked';link.symlink_to(target)
 print(json.dumps([owned,foreign,linked,error(lambda:M.read(link,os.getuid())),error(lambda:M.read(target,os.getuid(),limit=1))]))`,[true,["Refusal","tool.scope_conflict"],["Refusal","tool.scope_conflict"],["OSError",null],["Refusal","tool.output_invalid"]]],
["first use refuses conflicts before installing prerequisites", String.raw`
host=Host(manager='composer')
with patch.object(M,'owned',side_effect=M.Refusal('tool.scope_conflict')):a=error(lambda:M.composer_materialize(host,{'user':'candidate'}))
first=host.calls;host=Host(manager='vp')
print(json.dumps([a,first,error(lambda:M.vp_materialize(host,{'user':'candidate','architecture':'amd64','tools':{}})),host.calls]))`,[["Refusal","tool.scope_conflict"],[],["Refusal","tool.vp_materialize_invalid"],[]]],
["vp artifact refuses archive escape before publication and unsafe URLs", String.raw`
with tempfile.TemporaryDirectory() as tmp:
 base=Path(tmp);archive=base/'bad.tar'
 with tarfile.open(archive,'w') as tar:
  info=tarfile.TarInfo('../escaped');info.size=4;tar.addfile(info,io.BytesIO(b'nope'))
 a=error(lambda:M.extract(archive,base/'target',Host().account));escaped=(base/'escaped').exists()
print(json.dumps([a,escaped,[error(lambda:M.https(location)) for location in ('http://example.test/x','https://user:pass@example.test/x','file:///tmp/x','https://example.test/x#fragment')]]))`,[["OutsideDestinationError",null],false,Array(4).fill(["Refusal","tool.vp_materialize_invalid"])]],
];
for (const [name, source, expected] of cases) {
  Deno.test(name, async () => assertEquals(await python(source), expected));
}
