"""Real Node-user sudo integration. Run only in a fresh disposable scratch.
Compile app_step_native_fixture.ts to /usr/local/bin/lane2-sudo-step-fixture first.
The scratch container is the cleanup boundary; never run on an enrolled Node.
"""
import fcntl,hashlib,json,os,pwd,shlex,shutil,subprocess,time,unittest
from pathlib import Path
NODE='orbit-node-proof';APP='orbit-app-99009';HOME=Path('/home')/APP;RELEASE=HOME/'releases/proof-1'
def run(*argv,**kwargs):return subprocess.run(argv,check=True,capture_output=True,text=True,timeout=20,cwd='/tmp',**kwargs).stdout.strip()
class AppSteps(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  if os.geteuid()!=0 or not Path('/run/lane2-native-scratch').is_file():raise unittest.SkipTest('requires an explicitly marked root scratch container')
  for user in [NODE,APP]:
   try:pwd.getpwnam(user);raise AssertionError('fresh scratch required')
   except KeyError:pass
   run('useradd','--create-home','--user-group',user)
  run('usermod','-aG','sudo',NODE)
  Path('/etc/sudoers.d/lane2-node-proof').write_text(NODE+' ALL=(ALL) NOPASSWD: ALL\n');Path('/etc/sudoers.d/lane2-node-proof').chmod(0o440)
  RELEASE.mkdir(parents=True);(HOME/'.env').write_text('APP_KEY="synthetic-private-value"\nMULTILINE="one\\ntwo"\n')
  run('chown','-R',APP+':'+APP,str(HOME))
  for directory in [HOME,HOME/'releases',RELEASE]:directory.chmod(0o700)
  (HOME/'.env').chmod(0o600)
  checkout=Path('/home')/NODE/'apps/canary';checkout.mkdir(parents=True);run('chown','-R',NODE+':'+NODE,str(checkout.parent));checkout.chmod(0o700);(checkout/'.env').write_text('APP_ENV=testing\n');run('chown',NODE+':'+NODE,str(checkout/'.env'))
 @classmethod
 def tearDownClass(cls):pass
 def call(self,script,**kwargs):
  value=json.loads(run('runuser','-u',NODE,'--','/usr/local/bin/lane2-sudo-step-fixture',input=json.dumps({'script':script,**kwargs})))
  self.assertNotIn('synthetic-private-value',json.dumps(value));return value
 def test_identity_and_redaction(self):
  probe="""import json,os
from pathlib import Path
status={line.split(':',1)[0]:line.split(':',1)[1].strip() for line in Path('/proc/self/status').read_text().splitlines()}
fds={}
for p in Path('/proc/self/fd').iterdir():
 try:fds[p.name]=os.readlink(p)
 except FileNotFoundError:pass
Path('identity.json').write_text(json.dumps(dict(uid=os.getresuid(),gid=os.getresgid(),cap=status['CapEff'],nnp=status['NoNewPrivs'],cwd=os.getcwd(),fds=fds,keys=list(os.environ))))
print(os.environ['APP_KEY'])
print('Authorization: Bearer private-structured-token')
print('probe-ok')
"""
  result=self.call('exec /usr/bin/python3 -I -c '+shlex.quote(probe))
  self.assertEqual(result['exit_code'],0,result);self.assertIn('probe-ok',result['output_tail']);self.assertNotIn('private-structured-token',str(result))
  evidence=json.loads((RELEASE/'identity.json').read_text());a=pwd.getpwnam(APP)
  self.assertEqual(evidence['uid'],[a.pw_uid]*3);self.assertEqual(evidence['gid'],[a.pw_gid]*3);self.assertEqual(int(evidence['cap'],16),0);self.assertEqual(evidence['nnp'],'1');self.assertEqual(evidence['cwd'],str(RELEASE));self.assertEqual(set(evidence['fds']),{'0','1','2','3'});self.assertNotIn('SWAMP_API_KEY',evidence['keys']);Path('/root/lane2-sudo-step-identity.json').write_text(json.dumps(evidence))
 def test_checkout_account_and_setup_teardown(self):
  checkout=Path('/home')/NODE/'apps/canary'
  target={'variant':'checkout','user':NODE,'directory':str(checkout),'seed_path':'/seed/admitted','seed_commit':'a'*40}
  for phase in ['setup','teardown']:
   result=self.call('test "$USER" = '+NODE+'; test "$APP_ENV" = testing; test "$ORBIT_SEED_PATH" = /seed/admitted; printf "checkout-ok\\n"',changes={'target':target,'phase':phase})
   self.assertEqual(result['exit_code'],0,result);self.assertIn('checkout-ok',result['output_tail'])
  target['user']='root'
  with self.assertRaises(subprocess.CalledProcessError):self.call('touch forbidden',changes={'target':target})
 def test_managed_checkout_primary_group_write_and_world_write(self):
  checkout=Path('/home')/NODE/'apps/canary';target={'variant':'checkout','user':NODE,'directory':str(checkout),'seed_path':'','seed_commit':''}
  try:
   checkout.chmod(0o775)
   self.assertEqual(self.call('printf "managed-checkout-ok\\n"',changes={'target':target})['exit_code'],0)
   checkout.chmod(0o777)
   self.assertEqual(self.call('touch forbidden',changes={'target':target}),{'error_code':'instance.step_layout_invalid'})
   self.assertFalse((checkout/'forbidden').exists())
   checkout.chmod(0o775);run('chown',NODE+':root',str(checkout))
   self.assertEqual(self.call('touch forbidden',changes={'target':target}),{'error_code':'instance.step_layout_invalid'})
  finally:run('chown',NODE+':'+NODE,str(checkout));checkout.chmod(0o700)
 def test_checkout_flock_excludes_existing_deploy_and_cleanup(self):
  checkout=Path('/home')/NODE/'apps/canary';fd=os.open(checkout,os.O_RDONLY|os.O_DIRECTORY)
  target={'variant':'checkout','user':NODE,'directory':str(checkout),'seed_path':'','seed_commit':''}
  try:
   fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
   self.assertEqual(self.call('touch forbidden',changes={'target':target}),{'error_code':'instance.lifecycle_busy'})
   self.assertFalse((checkout/'forbidden').exists())
  finally:os.close(fd)
 def test_managed_vp_scope_and_home_precedence(self):
  checkout=Path('/home')/NODE/'apps/canary';home=Path('/home')/NODE
  target={'variant':'checkout','user':NODE,'directory':str(checkout),'seed_path':'','seed_commit':''}
  scopes=[Path('/opt/orbit/vite-plus'),home/'.vite-plus',home/'.local/share/vite-plus']
  for scope in scopes:
   (scope/'bin').mkdir(parents=True);(scope/'bin/vp').write_text('#!/bin/sh\nexit 0\n');(scope/'bin/vp').chmod(0o755);run('chown','-R',NODE+':'+NODE,str(scope))
  try:
   result=self.call('test "$VP_HOME" = /opt/orbit/vite-plus; vp; printf "vp-opt-ok\\n"',changes={'target':target})
   self.assertEqual(result['exit_code'],0,result);self.assertIn('vp-opt-ok',result['output_tail'])
   scopes[0].rename(scopes[0].with_name('vp-held'))
   result=self.call('test "$VP_HOME" = "$HOME/.vite-plus"; vp; printf "vp-home-ok\\n"',changes={'target':target})
   self.assertEqual(result['exit_code'],0,result);self.assertIn('vp-home-ok',result['output_tail'])
  finally:
   if scopes[0].with_name('vp-held').exists():scopes[0].with_name('vp-held').rename(scopes[0])
 def test_admitted_pinned_vp_survives_wrapper_fd_closure_without_access_changes(self):
  store=Path('/opt/orbit/vite-plus');binary=store/'bin/vp';existed=store.exists()
  (store/'bin').mkdir(parents=True,exist_ok=True);old=binary.read_bytes() if binary.exists() else None
  fake="""#!/usr/bin/python3 -I
import json,os,sys
from pathlib import Path
args=sys.argv[1:]
Path('vp-argv.json').write_text(json.dumps(args))
try:os.close(3)
except OSError:pass
os.execve(args[6],args[6:],os.environ)
"""
  binary.write_text(fake);binary.chmod(0o755);run('chown','-R',NODE+':'+NODE,str(store))
  runtime={'store':str(store),'owner':NODE,'vp_version':'0.3.3','node_version':'24.22.0','package_manager':'npm','package_manager_version':'11.12.0','package_manager_hash':None}
  try:
   result=self.call('test "$VP_HOME" = '+str(store)+'; printf "pinned-vp-ok\\n"',changes={'phase':'before_activation','vp_environment':runtime})
   self.assertEqual(result['exit_code'],0,result);self.assertIn('pinned-vp-ok',result['output_tail'])
   args=json.loads((RELEASE/'vp-argv.json').read_text());self.assertEqual(args[:7],['env','exec','--node','24.22.0','--package-manager','npm@11.12.0','/bin/bash'])
   self.assertFalse(Path(args[-1]).exists(),'sealed script fd survived supervisor completion')
   binary.chmod(0o777)
   self.assertIn('error_code',self.call('touch forbidden-vp',changes={'vp_environment':runtime}));self.assertFalse((RELEASE/'forbidden-vp').exists())
   binary.chmod(0o755)
   checkout=Path('/home')/NODE/'apps/canary'
   target={'variant':'checkout','user':NODE,'directory':str(checkout),'seed_path':'','seed_commit':''}
   foreign={**runtime,'owner':APP}
   self.assertEqual(self.call('touch forbidden-owner',changes={'target':target,'vp_environment':foreign}),{'error_code':'tool.vp_scope_conflict'})
   self.assertFalse((checkout/'forbidden-owner').exists())
  finally:
   if old is not None:binary.write_bytes(old);binary.chmod(0o755)
   elif not existed:shutil.rmtree(store)
   else:binary.unlink()
 def test_timeout_kills_setsid_sigterm_ignoring_child(self):
  child="import os,signal,time;from pathlib import Path;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);Path('escaped.pid').write_text(str(os.getpid()));time.sleep(60)"
  script='/usr/bin/python3 -I -c '+shlex.quote(child)+' &\nwhile [ ! -f escaped.pid ];do sleep 0.01;done\nsleep 60'
  start=time.monotonic();result=self.call(script,seconds=1);self.assertTrue(result['timed_out'],result);self.assertLess(time.monotonic()-start,4)
  pid=int((RELEASE/'escaped.pid').read_text());self.assertFalse(Path('/proc/'+str(pid)).exists(),'escaped descendant survived')
 def test_cancellation_kills_escaped_descendant(self):
  child="import os,signal,time;from pathlib import Path;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);Path('cancelled.pid').write_text(str(os.getpid()));time.sleep(60)"
  script='/usr/bin/python3 -I -c '+shlex.quote(child)+' &\nwhile [ ! -f cancelled.pid ];do sleep 0.01;done\nsleep 60'
  start=time.monotonic();result=self.call(script,seconds=5,abort_after_ms=500)
  self.assertEqual(result['exit_code'],130,result);self.assertLess(time.monotonic()-start,3)
  pid=int((RELEASE/'cancelled.pid').read_text());self.assertFalse(Path('/proc/'+str(pid)).exists())
 def test_digest_and_symlink_and_writable_paths_refuse(self):
  self.assertEqual(self.call('touch forbidden',changes={'command_digest':'0'*64}),{'error_code':'instance.step_not_admitted'})
  self.assertFalse((RELEASE/'forbidden').exists())
  for path in [HOME,HOME/'releases',RELEASE]:
   path.chmod(0o770);self.assertIn('error_code',self.call('touch forbidden'));path.chmod(0o700)
   real=path.with_suffix('.real');path.rename(real);path.symlink_to(real);self.assertIn('error_code',self.call('touch forbidden'));path.unlink();real.rename(path)
  self.assertFalse((RELEASE/'forbidden').exists())
 def test_loader_env_and_oversized_or_partial_output(self):
  env=HOME/'.env';old=env.read_text();env.write_text('BASH_ENV="/tmp/foreign"\n');self.assertIn('error_code',self.call('touch forbidden'));env.write_text(old)
  result=self.call("printf 'synthetic-private-val'");self.assertEqual(result['output_tail'],'');self.assertTrue(result['truncated'])
  result=self.call("/usr/bin/python3 -c \"print('x'*10000)\"");self.assertEqual(result['output_tail'],'');self.assertTrue(result['truncated'])
if __name__=='__main__':unittest.main()
