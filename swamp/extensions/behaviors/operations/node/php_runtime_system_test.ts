import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { PhpRuntimeProgram, phpRuntimeSystem } from "./php_runtime_system.ts";

const setup = String.raw`
import os,tempfile,shutil,json,subprocess
from pathlib import Path
ns={'__name__':'sandbox'};exec(SOURCE,ns)
base=Path(tempfile.mkdtemp());ns.update(ROOT=base,UID=os.getuid(),GID=os.getgid())
for v in ('8.4','8.5'):
    root=base/('etc/php/'+v+'/fpm');(root/'pool.d').mkdir(parents=True)
    (root/'conf.d').mkdir();(root.parent/'mods-available').mkdir()
    (root/'php-fpm.conf').write_text('[global]\ninclude=/etc/php/'+v+'/fpm/pool.d/*.conf\n')
    (root/'pool.d/www.conf').write_text('unrelated pool\n')
(base/'apps/present').mkdir(parents=True)
(base/'etc/os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')
(base/'usr/sbin').mkdir(parents=True);(base/'usr/sbin/php-fpm8.5').write_text('binary')
services={};changes=[];validations=[];fail_validate=False;fail_activation=None;fail_recovery=False;profile_info=True;profile_pcov=False
ini='post_max_size = 64M\nupload_max_filesize = 64M\nopcache.enable = On\nopcache.jit = disable\nopcache.jit_buffer_size = 0\nopcache.memory_consumption = 256\nopcache.interned_strings_buffer = 32\nopcache.max_accelerated_files = 20000\n'
profile={'version':'8.5','runtime_ini':ini,'release':{'id':'ubuntu','codenames':['resolute']}}
def pool(i,d='/apps/present'): return '[orbit-app-instance-'+str(i)+']\nchdir = '+d+'\n'
def fixed_service(argv,required=True):
    global fail_activation
    code=0;out=''
    if 'php-fpm' in argv[0]:
        if '--test' in argv:
            candidate=Path(argv[3]);text=candidate.read_text();directory=Path(next(l.split('=',1)[1].strip() for l in text.splitlines() if l.startswith('include'))).parent
            assert (directory/'www.conf').read_text()=='unrelated pool\n'
            assert (directory/'orbit-scopes.conf').exists()
            validations.append(text)
            if fail_validate: code=1
        elif '-i' in argv:
            out=str(base/'etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini')+'\n'
            if profile_info: out+='\n'.join(k+' => '+v+' => '+v for k,v in ns['settings'](profile).items())
        elif '-m' in argv: out='pcov\n' if profile_pcov else '[PHP Modules]\nopcache\n'
    elif argv[0]=='/usr/bin/systemctl':
        action=argv[1];unit=argv[-1]
        if action=='list-units': return 0,''
        assert unit.startswith('php') and unit.endswith(('-fpm','-fpm.service'))
        v=unit[3:].split('-')[0];s=services.setdefault(v,[True,True])
        if action=='is-active': code=0 if s[0] else 3
        elif action=='is-enabled': code=0 if s[1] else 1
        else:
            pp=base/('etc/php/'+v+'/fpm/pool.d/orbit-scopes.conf')
            changes.append((action,v,pp.read_text() if pp.exists() else None))
            if action=='enable': s[1]=True
            elif action=='disable': s[1]=False
            elif action=='stop': s[0]=False
            elif action=='reload-or-restart':
                if fail_activation==v:
                    code=1
                    if not fail_recovery: fail_activation=None
                else: s[0]=True
    if code and required: ns['fail']('php.shared_pool_failed')
    return code,out
ns['call']=fixed_service
`;
async function python(body: string) {
  const result = await new Deno.Command("/usr/bin/python3", {
    args: [
      "-c",
      "SOURCE=" + JSON.stringify(PhpRuntimeProgram) + "\n" + setup +
      "\ntry:\n" + body.split("\n").map((l) => "    " + l).join("\n") +
      "\nfinally:\n    shutil.rmtree(base)\n",
    ],
    stdout: "piped",
    stderr: "piped",
  }).output();
  if (!result.success) throw new Error(new TextDecoder().decode(result.stderr));
}
Deno.test("shared pools validate complete staged config and preserve inode on repeat", async () => {
  await python(String.raw`
p={'configurations':{'8.4':pool(1),'8.5':pool(2)}}
assert ns['shared_pools'](p)=={'changed':True,'missing_pools':[]}
assert len(validations)==2
file=ns['pool_path']('8.5');inode=file.stat().st_ino
assert file.stat().st_mode&0o7777==0o644
changes.clear()
assert ns['shared_pools'](p)['changed']==False
assert changes==[] and file.stat().st_ino==inode
`);
});
Deno.test("version swap retires both sockets before activating either destination", async () => {
  await python(String.raw`
ns['pool_path']('8.4').write_text(pool(1));ns['pool_path']('8.5').write_text(pool(2))
assert ns['shared_pools']({'configurations':{'8.4':pool(2),'8.5':pool(1)}})['changed']
assert changes==[('reload-or-restart','8.4',None),('reload-or-restart','8.5',None),('reload-or-restart','8.4',pool(2)),('reload-or-restart','8.5',pool(1))]
`);
});
Deno.test("missing directories are reported and omitted while stale pools retire", async () => {
  await python(String.raw`
ns['pool_path']('8.4').write_text(pool(9,'/apps/gone'))
r=ns['shared_pools']({'configurations':{'8.5':pool(1)+pool(2,'/apps/missing')}})
assert r['missing_pools']==[dict(pool='orbit-app-instance-2',version='8.5',directory='/apps/missing',installed=False)]
assert ns['pool_path']('8.5').read_text()==pool(1)
assert not ns['pool_path']('8.4').exists()
`);
});
Deno.test("invalid input, unsafe sibling and ambiguous includes refuse before publication", async () => {
  await python(String.raw`
for configurations in [{'../8.5':pool(1)},{'8.5':'[global]\ninclude=/evil\n'},{'8.5':pool(1,'relative')},{'8.4':pool(1),'8.5':pool(1)}]:
    try: ns['shared_pools']({'configurations':configurations});raise AssertionError('accepted')
    except ns['Refusal']: pass
main=base/'etc/php/8.5/fpm/php-fpm.conf';original=main.read_text();main.write_text(original+original)
try: ns['shared_pools']({'configurations':{'8.4':pool(1),'8.5':pool(2)}});raise AssertionError('accepted')
except ns['Refusal']: pass
main.write_text(original)
(base/'etc/php/8.5/fpm/pool.d/foreign.conf').symlink_to('www.conf')
try: ns['shared_pools']({'configurations':{'8.4':pool(1),'8.5':pool(2)}});raise AssertionError('accepted')
except ns['Refusal']: pass
assert changes==[] and not ns['pool_path']('8.4').exists()
`);
});
Deno.test("failed validation leaves every live file and service unchanged", async () => {
  await python(String.raw`
f=ns['pool_path']('8.5');f.write_text(pool(2));inode=f.stat().st_ino
fail_validate=True
try: ns['shared_pools']({'configurations':{'8.5':pool(1)}});raise AssertionError('accepted')
except ns['Refusal']: pass
assert f.read_text()==pool(2) and f.stat().st_ino==inode and changes==[]
`);
});
Deno.test("failed activation restores modes and services without restoring missing directories", async () => {
  await python(String.raw`
f=ns['pool_path']('8.5');f.write_text(pool(1)+pool(9,'/apps/gone'));f.chmod(0o600)
services['8.4']=[False,False];fail_activation='8.4'
r=ns['shared_pools']({'configurations':{'8.4':pool(1)}})
assert r['error_code']=='php.shared_pool_failed'
assert f.read_text()==pool(1) and f.stat().st_mode&0o7777==0o600
assert not ns['pool_path']('8.4').exists()
assert services['8.4']==[False,False] and services['8.5']==[True,True]
`);
});
Deno.test("failed recovery is explicit and empty prior files retain mode", async () => {
  await python(String.raw`
f=ns['pool_path']('8.5');f.write_text('');f.chmod(0o600)
fail_activation='8.5';fail_recovery=True
r=ns['shared_pools']({'configurations':{'8.5':pool(1)}})
assert r['error_code']=='php.shared_pool_recovery_failed'
assert f.read_text()=='' and f.stat().st_mode&0o7777==0o600
`);
});
Deno.test("runtime settings enforce size relationships, exact keys and platform", async () => {
  await python(String.raw`
assert len(ns['settings'](profile))==8
for post,upload,valid in [('1G','1024M',True),('64M','65537K',False),('2G','64M',False),('64M','65536K',True)]:
    p=dict(profile,runtime_ini=ini.replace('post_max_size = 64M','post_max_size = '+post).replace('upload_max_filesize = 64M','upload_max_filesize = '+upload))
    try: ns['settings'](p);assert valid
    except ns['Refusal']: assert not valid
for bad in [ini+'opcache.enable = On\n',ini.replace('opcache.jit = disable','evil = disable')]:
    try: ns['settings'](dict(profile,runtime_ini=bad));raise AssertionError('accepted')
    except ns['Refusal']: pass
(base/'etc/os-release').write_text('ID=debian\nVERSION_CODENAME=trixie\n')
try: ns['configure'](profile);raise AssertionError('accepted')
except ns['Refusal'] as e: assert e.code=='php.runtime_platform_unsupported'
assert changes==[]
`);
});
Deno.test("runtime publication verifies loaded INI and idempotent control timeout", async () => {
  await python(String.raw`
r=ns['configure'](profile);assert r['changed'] and r['reloaded']
module=base/'etc/php/8.5/mods-available/orbit-runtime.ini';main=base/'etc/php/8.5/fpm/php-fpm.conf'
assert module.read_text()==ini and module.stat().st_mode&0o7777==0o644
assert main.read_text().count('process_control_timeout = 30s')==1
inode=module.stat().st_ino;changes.clear()
r=ns['configure'](profile);assert not r['changed'] and not r['reloaded']
assert not any(c[0]=='reload' for c in changes) and module.stat().st_ino==inode
`);
});
Deno.test("unmanaged INI override rolls back file, symlink and FPM master bytes", async () => {
  await python(String.raw`
module=base/'etc/php/8.5/mods-available/orbit-runtime.ini';module.write_text('previous')
link=base/'etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';link.symlink_to('old.ini')
main=base/'etc/php/8.5/fpm/php-fpm.conf';before=main.read_text()
profile_info=False
r=ns['configure'](profile)
assert r==dict(changed=False,error_code='php.runtime_configuration_failed',restored=True)
assert module.read_text()=='previous' and os.readlink(link)=='old.ini' and main.read_text()==before
assert changes==[]
`);
});
Deno.test("PCOV FPM refusal restores prior configuration after reload", async () => {
  await python(String.raw`
main=base/'etc/php/8.5/fpm/php-fpm.conf';before=main.read_text();profile_pcov=True
r=ns['configure'](profile)
assert r['error_code']=='php.runtime_configuration_failed' and r['restored']
assert main.read_text()==before and not (base/'etc/php/8.5/mods-available/orbit-runtime.ini').exists()
assert len([c for c in changes if c[0]=='reload'])==2
`);
});
Deno.test("transport sends data only on stdin and bounds uncertain diagnostics", async () => {
  const action = phpRuntimeSystem((command) => {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(-1), ["php.converge-shared-pools"]);
    assertEquals(command.stdin, JSON.stringify({ configurations: {} }));
    return Promise.resolve({
      success: true,
      stdout: '{"changed":false,"missing_pools":[]}',
    });
  });
  assertEquals(
    await action("php.converge-shared-pools", { configurations: {} }),
    { changed: false, missing_pools: [] },
  );
  await assertRejects(
    () =>
      phpRuntimeSystem(() =>
        Promise.resolve({ success: false, stdout: "PRIVATE" })
      )(
        "php.converge-shared-pools",
        { configurations: {} },
      ),
    Error,
    "no confirmed outcome",
  );
});

Deno.test("runtime missing build is a no-op and occupied module link refuses", async () => {
  await python(String.raw`
binary=base/'usr/sbin/php-fpm8.5';binary.unlink()
assert ns['configure'](profile)=={'changed':False,'reloaded':False} and changes==[]
binary.write_text('binary')
link=base/'etc/php/8.5/fpm/conf.d/99-orbit-runtime.ini';link.write_text('foreign')
try: ns['configure'](profile);raise AssertionError('accepted')
except ns['Refusal']: pass
assert link.read_text()=='foreign' and changes==[]
`);
});
Deno.test("invalid dedicated FPM configuration defers reload without changing unrelated units", async () => {
  await python(String.raw`
dedicated=base/'etc/orbit/dedicated.conf';dedicated.parent.mkdir();dedicated.write_text('[global]\nprocess_control_timeout = 1s\n[pool]\n')
unit='orbit-example-php8.5-fpm.service'
original=ns['call'];calls=[]
def with_dedicated(argv,required=True):
    calls.append(argv)
    if argv[0]=='/usr/bin/systemctl' and argv[1]=='list-units': return 0,unit+' loaded active running\nforeign.service loaded active running\n'
    if argv[0]=='/usr/bin/systemctl' and argv[1]=='show': return 0,'--fpm-config /etc/orbit/dedicated.conf'
    if '--fpm-config' in argv and '-t' in argv: return 1,''
    return original(argv,required)
ns['call']=with_dedicated
r=ns['configure'](profile)
assert r['changed'] and r['reloaded'] and r['deferred']==[unit]
assert 'process_control_timeout = 30s' in dedicated.read_text()
assert ['/usr/bin/systemctl','reload',unit] not in calls
assert not any('foreign.service' in c for c in calls)
`);
});
