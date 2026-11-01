"""Pinned native DAGs with official SSH and real disposable SQLite effects."""
import contextlib
import getpass
import json
import os
import shlex
import shutil
import socket
import urllib.request
import sqlite3
import subprocess
import sys
import tempfile
import time
import unittest
import uuid
from pathlib import Path
import yaml
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from database_command_native import DatabaseCommands,reply

class SqliteNative(DatabaseCommands):
 fail=unittest.TestCase.fail
 def setUp(self):
  super().setUp()
  self.prepare_commands(['workflow-database-sqlite-export.yaml','workflow-database-sqlite-seed.yaml','workflow-database-sqlite-action.yaml','workflow-operation-database-sqlite-export.yaml','workflow-operation-database-sqlite-seed.yaml','workflow-operation-database-sqlite-seed-abandon.yaml','workflow-operation-database-cleanup.yaml'])
  for name in ('sqlite_input.ts','cleanup_contract.ts'):shutil.copy(ROOT/'extensions/behaviors/operations/database'/name,self.repo/'extensions/behaviors/operations/database'/name)
  fake=self.repo/'fixture-ssh';fake.write_text('#!'+sys.executable+'\n'+'''import json,os,shlex,subprocess,sys,time
from pathlib import Path
if '-M'in sys.argv or '-O'in sys.argv:sys.exit(0)
args=shlex.split(sys.argv[-1]);scripts=[Path(a).stem for a in args if a.endswith('.py')]
if scripts:
 action=scripts[0];v=json.loads(Path(STATE).read_text())
 fd=os.open(CALLS,os.O_WRONLY|os.O_APPEND);os.write(fd,(json.dumps({'action':action})+'\\n').encode());os.close(fd)
 if v.get('fail')==action:print('{"error_code":"instance.sqlite_seed_refused"}');sys.exit(1)
 if v.get('gate')==action:Path(GATE).touch();time.sleep(4)
result=subprocess.run(args,stdin=sys.stdin,stdout=sys.stdout,stderr=subprocess.DEVNULL);sys.exit(result.returncode)
'''.replace('STATE',repr(str(self.state))).replace('CALLS',repr(str(self.calls_file))).replace('GATE',repr(str(self.repo/'gate'))));fake.chmod(0o700)
  scp=self.repo/'fixture-scp';scp.write_text('#!'+sys.executable+'\n'+'''import shutil,sys
from pathlib import Path
def path(raw):return raw.split(':',1)[1] if ':'in raw else raw
src,dst=map(path,sys.argv[-2:]);source=Path(src);target=Path(dst)
if source.is_dir():shutil.copytree(source,target,dirs_exist_ok=True)
else:shutil.copy2(source,target)
''');scp.chmod(0o700)
  self.data=tempfile.TemporaryDirectory(prefix='native-sqlite-files-');self.addCleanup(self.data.cleanup);self.home=Path(self.data.name);self.source=self.home/'source';self.target=self.home/'target';self.source.mkdir(mode=0o700);self.target.mkdir(mode=0o700);self.db=self.source/'db.sqlite'
  with contextlib.closing(sqlite3.connect(self.db))as db:db.execute('CREATE TABLE proof(v)');db.execute('INSERT INTO proof VALUES (?)',('private-sqlite-native-sentinel',));db.commit()
  self.db.chmod(0o600);self.identity=uuid.uuid4().hex*2;user=getpass.getuser();ssh={'host':'127.0.0.1','user':user,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}
  self.source_desc={'variant':'seed','node_id':9,'target_instance_id':2,'base':str(self.source),'source_path':str(self.db),'source_user':user,'transport_user':user,'operation':self.identity,'state_dir':'/var/lib/orbit/app-instance-sqlite-seeds/source-1-target-2-'+self.identity,'snapshot_path':'/tmp/orbit-sqlite-'+self.identity+'.sqlite'}
  self.target_desc={'node_id':10,'target_instance_id':2,'base':str(self.target),'target_user':user,'transport_user':user,'operation':self.identity,'state_dir':'/var/lib/orbit/app-instance-sqlite-seeds/target-2-'+self.identity,'environment':'development','relative_path':'db.sqlite'}
  self.intent={'source':self.source_desc,'target':self.target_desc,'source_ssh':ssh,'target_ssh':ssh};self.public={'operation_id':17,'node_id':10,'kind':'database.sqlite_seed','intent':self.intent,'execution_secrets':{}}
  self.addCleanup(self.clean)
 def clean(self):
  script=ROOT/'extensions/behaviors/operations/database-server-commands/scripts'
  for name in ('sqlite_target_cleanup','sqlite_source_cleanup'):
   subprocess.run(['/usr/bin/sudo','-n','--','/usr/bin/python3','-B',str(script/(name+'.py')),json.dumps(self.intent)],capture_output=True,text=True)
 def run_kind(self,kind,success=True,flags=()):
  self.public['kind']=kind;name={'database.sqlite_export':'operation-database-sqlite-export','database.sqlite_seed':'operation-database-sqlite-seed','database.sqlite_seed_abandon':'operation-database-sqlite-seed-abandon','database.sqlite_cleanup':'operation-database-cleanup'}[kind]
  self.cli('workflow','validate',name);run=reply(self.cli('workflow','run',name,'--input',json.dumps(self.public),*flags,success=success).stdout);self.assertNotIn('private-sqlite-native-sentinel',json.dumps(self.history(run)));return run
 def action_names(self):return[x['action']for x in self.calls()]
 def test_cleanup_absent_clone_uses_no_vault_and_returns_absent(self):
  self.public['intent']={'cleanup':{'variant':'clone','directory':'/tmp/orbit-sqlite-clone-'+uuid.uuid4().hex},'source_ssh':self.intent['source_ssh']};run=self.run_kind('database.sqlite_cleanup');self.assertEqual(self.outcome(run),{'backend':'absent'});self.assertEqual(self.action_names(),['sqlite_cleanup'])
 def test_export_retains_native_private_file_and_cleans_source(self):
  self.intent.pop('target');self.intent.pop('target_ssh');self.public['node_id']=9;run=self.run_kind('database.sqlite_export');self.assertEqual(self.outcome(run)['backend'],'active');self.assertEqual(self.outcome(run)['bytes'],self.db.stat().st_size);self.assertFalse(Path(self.source_desc['snapshot_path']).exists());raws=[p for p in(self.repo/'.swamp/data').rglob('raw')if b'private-sqlite-native-sentinel'in p.read_bytes()];self.assertEqual(len(raws),1)
 def test_seed_real_database_and_terminal_resume_never_replays_effect(self):
  marker=self.repo/'delivery';marker.touch();w=self.workflows['workflow-operation-database-sqlite-seed.yaml'];w['jobs'][-1]['steps'].append({'name':'delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery','methodName':'execute','inputs':{'run':'test ! -e '+str(marker)}}});self.save();first=self.run_kind('database.sqlite_seed',False);marker.unlink();before=self.calls();self.cli('workflow','resume','operation-database-sqlite-seed','--run',first['id']);self.assertEqual(self.calls(),before);self.assertEqual(self.outcome(first),{'backend':'active'});
  with contextlib.closing(sqlite3.connect(self.target/'db.sqlite'))as db:self.assertEqual(db.execute('SELECT v FROM proof').fetchone(),('private-sqlite-native-sentinel',))
  self.assertFalse(Path(self.source_desc['snapshot_path']).exists())
 def test_failed_install_cleans_source_and_incoming_without_success(self):
  self.state.write_text(json.dumps({'fail':'sqlite_target_install'}));run=self.run_kind('database.sqlite_seed',False);self.assertIn('sqlite_target_recover',self.action_names());self.assertIn('sqlite_source_cleanup',self.action_names());self.assertFalse((self.target/'db.sqlite').exists());self.assertFalse(Path(self.target_desc['state_dir']).exists());self.assertNotEqual(self.outcome(run).get('backend'),'active')
 def test_abandon_cleans_both_attempts_and_preserves_installed_database(self):
  self.run_kind('database.sqlite_seed');inode=(self.target/'db.sqlite').stat().st_ino;run=self.run_kind('database.sqlite_seed_abandon');self.assertEqual(self.outcome(run),{'backend':'absent'});self.assertEqual((self.target/'db.sqlite').stat().st_ino,inode);self.assertFalse(Path(self.target_desc['state_dir']).exists())
 def test_source_refusal_has_no_target_effect(self):
  self.state.write_text(json.dumps({'fail':'sqlite_source_export'}));run=self.run_kind('database.sqlite_seed',False);self.assertNotIn('sqlite_target_prepare',self.action_names());self.assertNotEqual(self.outcome(run).get('backend'),'active')

 @contextlib.contextmanager
 def served(self):
  self.cli('access','token','mint','fixture-admin','--principal','user:fixture')
  token=self.repo/'admin-token';token.write_text(reply(self.cli('access','token','reveal','fixture-admin','--yes').stdout)['token']);token.chmod(0o600)
  with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
  env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
  for key in ('SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT','SWAMP_EXTENSIONS_DIR'):env.pop(key,None)
  with (self.repo/'serve.log').open('w') as log:
   serve=subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'serve','--host','127.0.0.1','--port',str(port),'--auth-mode','token','--admins','user:fixture'],cwd=self.repo,env=env,stdout=log,stderr=log)
   try:
    until=time.monotonic()+15
    while True:
     try:urllib.request.urlopen(f'http://127.0.0.1:{port}/ready',timeout=1).close();break
     except OSError:self.assertIsNone(serve.poll());self.assertLess(time.monotonic(),until);time.sleep(.1)
    yield ('--server',f'ws://127.0.0.1:{port}','--token-file',str(token)),env
   finally:
    serve.terminate()
    try:serve.wait(timeout=10)
    except subprocess.TimeoutExpired:serve.kill();serve.wait(timeout=5)
 def test_failed_seed_resumes_after_both_scratch_cleanups_with_a_fresh_child(self):
  with self.served() as (flags,_):
   self.state.write_text(json.dumps({'fail':'sqlite_target_install'}));first=self.run_kind('database.sqlite_seed',False,flags)
   self.assertFalse(Path(self.source_desc['snapshot_path']).exists());self.assertFalse(Path(self.target_desc['state_dir']).exists())
   exports=self.action_names().count('sqlite_source_export');self.state.write_text(json.dumps({'fail':''}))
   self.cli('workflow','resume','operation-database-sqlite-seed','--run',first['id'],*flags)
   self.assertEqual(self.outcome(first),{'backend':'active'});self.assertEqual(self.action_names().count('sqlite_source_export'),exports+1)
 def test_cancelled_seed_cleans_started_source_and_target_work(self):
  self.public['kind']='database.sqlite_seed';self.state.write_text(json.dumps({'gate':'sqlite_target_prepare'}))
  with self.served() as (flags,env):
   process=subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'workflow','run','operation-database-sqlite-seed','--input',json.dumps(self.public),*flags,'--json'],cwd=self.repo,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
   try:
    until=time.monotonic()+60
    while not(self.repo/'gate').exists():self.assertIsNone(process.poll());self.assertLess(time.monotonic(),until);time.sleep(.05)
    folder=self.repo/'.swamp/workflow-runs'/self.workflows['workflow-operation-database-sqlite-seed.yaml']['id'];saved=yaml.safe_load(next(folder.glob('workflow-run-*.yaml')).read_text());self.cli('workflow','cancel','--run',saved['id'],*flags)
    stdout,stderr=process.communicate(timeout=90);terminal=reply(self.cli('workflow','history','get',saved['id']).stdout);self.assertEqual(terminal['status'],'cancelled');self.assertNotIn('private-sqlite-native-sentinel',stdout+stderr)
    self.assertFalse(Path(self.source_desc['snapshot_path']).exists());self.assertIn('sqlite_target_recover',self.action_names());self.assertIn('sqlite_source_cleanup',self.action_names())
   finally:
    if process.poll() is None:process.kill();process.communicate(timeout=10)

if __name__=='__main__':unittest.main()
