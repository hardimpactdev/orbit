import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { convergeSharedPools, PhpLocalError } from "./shared_local.ts";
import { phpSharedProgram } from "./shared_program.ts";
import { pythonFixture } from "./test_fixture.ts";

const fixture = String.raw`
class Fake(Host):
 def __init__(self,root):
  super().__init__();self.root=root;self.tmp=str(root);self.states={};self.calls=[];self.changes=[];self.validated=[];self.fail_validation=False;self.fail_activation=None;self.fail_recovery=False
  self.path('/etc/php').mkdir(parents=True)
 def setup(self,v,text=None,active=True,enabled=True):
  d=self.path('/etc/php/'+v+'/fpm/pool.d');d.mkdir(parents=True,exist_ok=True)
  (d.parent/'php-fpm.conf').write_text('[global]\ninclude = /etc/php/'+v+'/fpm/pool.d/*.conf\n')
  (d/'www.conf').write_text('unrelated pool\n')
  if text is not None:(d/'orbit-scopes.conf').write_text(text)
  self.states[v]=[active,enabled]
 def run(self,args,required=True,env=None,timeout=300):
  args=list(map(str,args));self.calls.append(args);code=0
  if P(args[0]).name.startswith('php-fpm'):
   assert args[1:3]==['--test','--fpm-config']
   main=P(args[3]).read_text();include=next(l[len('include = '):] for l in main.splitlines() if l.startswith('include = '));pools=P(include).parent
   assert (pools/'www.conf').read_text()=='unrelated pool\n'
   assert (pools/'orbit-scopes.conf').is_file()
   self.validated.append(args[0]);code=1 if self.fail_validation else 0
  else:
   assert args[0]=='/usr/bin/systemctl';action=args[1];v=args[-1][3:-4];state=self.states[v]
   if action=='is-active':code=0 if state[0] else 3
   elif action=='is-enabled':code=0 if state[1] else 1
   else:
    assert len(self.validated)>=2
    p=shared_path(self,v);self.changes.append((action,v,p.read_text() if p.exists() else None))
    if action=='enable':state[1]=True
    elif action=='disable':state[1]=False
    elif action=='stop':
     state[0]=False
     if self.fail_recovery and self.fail_activation==v:code=1
    elif action=='reload-or-restart':
     if self.fail_activation==v:
      code=1
      if not self.fail_recovery:self.fail_activation=None
     else:state[0]=True
    else:raise AssertionError(args)
  require(not required or code==0,'php.build_execution_failed');return code,''
def pool(name,directory):return '['+name+']\nchdir = '+str(directory)+'\nlisten = /run/php/'+name+'.sock\n'
`;
Deno.test("Shared pools validate all versions before retiring moving sockets, then activate replacements", async () => {
  await pythonFixture(
    phpSharedProgram,
    fixture + String.raw`
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));app=h.root/'app';app.mkdir();name='orbit-app-instance-7';text=pool(name,'/app')
 h.setup('8.4',text);h.setup('8.5',None,False,False)
 out=converge_shared(dict(configurations={'8.5':text}),h)
 assert out==dict(changed=True,missing_pools=[])
 assert h.changes[0]==('reload-or-restart','8.4',None)
 assert h.changes[1:]==[('enable','8.5',text),('reload-or-restart','8.5',text)]
 h.changes.clear();out=converge_shared(dict(configurations={'8.5':text}),h)
 assert not out['changed'] and not h.changes
 # A missing desired directory is returned and excluded before validation/publication.
 missing=pool('orbit-app-instance-8','/gone')
 out=converge_shared(dict(configurations={'8.5':text+missing}),h)
 assert not out['changed'] and out['missing_pools']==[dict(pool='orbit-app-instance-8',version='8.5',directory='/gone',installed=False)]
 # Installed versions absent from desired input retire their pool file, without deleting foreign pools.
 out=converge_shared(dict(configurations={}),h)
 assert out['changed'] and not shared_path(h,'8.5').exists()
 assert (shared_path(h,'8.5').parent/'www.conf').read_text()=='unrelated pool\n'
print('ok')
`,
  );
});
Deno.test("Shared pool failure restores modes and service state in reverse order, drops missing old pools", async () => {
  await pythonFixture(
    phpSharedProgram,
    fixture + String.raw`
for fail_recovery in (False,True):
 with tempfile.TemporaryDirectory() as tmp:
  h=Fake(P(tmp));app=h.root/'app';app.mkdir();text=pool('orbit-instance-7','/app');stale=pool('orbit-instance-9','/gone')
  h.setup('8.4',text+stale,True,True);h.setup('8.5',None,False,False)
  text=text.replace('\n','\r\n');shared_path(h,'8.4').write_bytes((text+stale).encode());shared_path(h,'8.4').chmod(0o640);h.fail_activation='8.5';h.fail_recovery=fail_recovery
  try:converge_shared(dict(configurations={'8.5':text}),h);raise AssertionError('activation failure ignored')
  except Refused as e:assert e.code==('php.shared_pool_recovery_failed' if fail_recovery else SHARED_FAILED)
  assert shared_path(h,'8.4').read_bytes()==text.encode() and stat.S_IMODE(shared_path(h,'8.4').stat().st_mode)==0o640
  assert not shared_path(h,'8.5').exists()
  if not fail_recovery:assert h.states=={'8.4':[True,True],'8.5':[False,False]}
  assert h.changes[-1][1]=='8.4'
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));app=h.root/'app';app.mkdir();text=pool('orbit-instance-7','/app');h.setup('8.4',text);h.setup('8.5');h.fail_validation=True
 try:converge_shared(dict(configurations={'8.5':text}),h);raise AssertionError('invalid FPM accepted')
 except Refused as e:assert e.code==SHARED_FAILED
 assert not h.changes and shared_path(h,'8.4').read_text()==text
print('ok')
`,
  );
});
Deno.test("Shared pools refuse duplicate identity, foreign paths, unowned symlinks and invalid includes before effects", async () => {
  await pythonFixture(
    phpSharedProgram,
    fixture + String.raw`
for text in ('[foreign]\nchdir = /app\n','[orbit-instance-1]\nchdir = /app\nchdir = /other\n','[orbit-instance-1]\nlisten=/run/x\n','include=/tmp/private\n'):
 try:shared_desired(dict(configurations={'8.5':text}));raise AssertionError('invalid input accepted')
 except Refused as e:assert e.code==SHARED_INVALID
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));app=h.root/'app';app.mkdir();text=pool('orbit-instance-7','/app')
 try:shared_desired(dict(configurations={'8.4':text,'8.5':text}));raise AssertionError('duplicate pool accepted')
 except Refused as e:assert e.code==SHARED_INVALID
 h.setup('8.4',text);h.setup('8.5');p=shared_path(h,'8.4');p.rename(p.with_suffix('.saved'));p.symlink_to(p.with_suffix('.saved'))
 try:converge_shared(dict(configurations={'8.5':text}),h);raise AssertionError('symlink adopted')
 except Refused as e:assert e.code==SHARED_UNSAFE
 assert not h.calls
 p.unlink();p.with_suffix('.saved').rename(p)
 main=p.parent.parent/'php-fpm.conf';main.write_text('[global]\ninclude = /tmp/arbitrary/*.conf\n')
 try:converge_shared(dict(configurations={'8.5':text}),h);raise AssertionError('foreign include accepted')
 except Refused as e:assert e.code==SHARED_INVALID
 assert not h.changes
print('ok')
`,
  );
});
Deno.test("Shared pool wrapper accepts only bounded native output and uses private stdin", async () => {
  const input = {
    configurations: { "8.5": "[orbit-instance-1]\nchdir = /home/private\n" },
  };
  assertEquals(
    await convergeSharedPools(input, undefined, (command) => {
      assertEquals(command.args.includes(input.configurations["8.5"]), false);
      assertEquals(JSON.parse(command.stdin).input, input);
      return Promise.resolve({
        code: 0,
        stdout: '{"changed":true,"missing_pools":[]}',
      });
    }),
    { changed: true, missing_pools: [] },
  );
  assertThrows(() =>
    convergeSharedPools({ configurations: { "../../private": "" } })
  );
  await assertRejects(
    () =>
      convergeSharedPools(input, undefined, () =>
        Promise.resolve({
          code: 0,
          stdout: '{"changed":true,"missing_pools":[],"secret":"private"}',
        })),
    PhpLocalError,
    "php.shared_pool_failed",
  );
});

Deno.test("Shared pool version swaps retire both owners before binding replacement sockets", async () => {
  await pythonFixture(
    phpSharedProgram,
    fixture + String.raw`
with tempfile.TemporaryDirectory() as tmp:
 h=Fake(P(tmp));h.path('/app').mkdir();one=pool('orbit-instance-1','/app');two=pool('orbit-instance-2','/app')
 h.setup('8.4',one);h.setup('8.5',two)
 assert converge_shared(dict(configurations={'8.4':two,'8.5':one}),h)['changed']
 assert h.changes==[('reload-or-restart','8.4',None),('reload-or-restart','8.5',None),('reload-or-restart','8.4',two),('reload-or-restart','8.5',one)]
 assert len(h.validated)==4
print('ok')
`,
  );
});
