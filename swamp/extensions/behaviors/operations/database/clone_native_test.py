"""Official native clone children, vault inputs, cleanup and terminal resume."""
import json
import os
import shutil
import signal
import subprocess
import sys
import time
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from database_command_native import DatabaseCommands,reply

class CloneNative(DatabaseCommands):
 fail=unittest.TestCase.fail
 def setUp(self):
  super().setUp()
  self.prepare_commands(['workflow-operation-database-clone.yaml','workflow-database-clone-mysql.yaml','workflow-database-clone-sqlite.yaml','workflow-operation-database-create.yaml','workflow-database-create-action.yaml'])
  shutil.copy(ROOT/'extensions/behaviors/operations/database/clone_input.ts',self.repo/'extensions/behaviors/operations/database/clone_input.ts')
  fake=self.repo/'fixture-ssh';s=fake.read_text()
  s=s.replace("m=json.load(sys.stdin);assert m['password'] not in repr(args)","m={} if scripts[0].startswith('sqlite_clone_') else json.load(sys.stdin)\nif m:assert m['password'] not in repr(args)")
  old="print(json.dumps({'databases':v.get('inventory',[])}"
  s=s.replace(old,"print(json.dumps({'bytes':4096,'sha256':'a'*64,'mode':420,'copy':'snapshot'} if action=='sqlite_clone_export' else {'backend':'active'} if action in ('mysql_clone_copy','sqlite_clone_local','sqlite_clone_install') else {'databases':v.get('inventory',[])}")
  fake.write_text(s)
  ssh={'host':'127.0.0.1','user':'orbit','host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}
  self.i={'variant':'mysql','node_id':9,'source_node_id':9,'source_ssh':ssh,'target_ssh':ssh,'mysql':{'repair':False,'driver':'mysql','username':'proof_owner','databases':['target','target_test'],'test_prefix':'target_test','locale':None,'host':'10.44.0.9','port':3306,'container':'orbit-process-42-proof','index':None,'patterns':[],'allow':[],'source_database':'source'}}
  self.public={'operation_id':17,'node_id':9,'kind':'database.clone','intent':self.i,'execution_secrets':{'database_clone':{'vault':'orbit-control-inputs','key':'database-clone-intent-17'}}}
  self.secrets=('private-clone-admin','private-clone-owner-password-unique-123456')
  self.cli('vault','create','local_encryption','orbit-control-inputs');self.cli('vault','put','orbit-control-inputs','database-clone-intent-17',input=json.dumps({'password':self.secrets[0],'payload':{'password':self.secrets[1],'salt':'YWFhYWFhYWFhYWFhYWFhYQ=='}}))
 def sqlite(self,remote):
  ssh=self.i['source_ssh'];self.i.clear();self.i.update(variant='sqlite_remote' if remote else 'sqlite_local',node_id=10 if remote else 9,source_node_id=9,source_ssh=ssh,target_ssh=ssh,source={'variant':'clone','node_id':9,'transport_user':'orbit','checkout':'/apps/source','source':'/apps/source/database/database.sqlite','directory':'/tmp/orbit-sqlite-clone-'+'c'*32},target={'node_id':10 if remote else 9,'transport_user':'orbit','checkout':'/apps/target','target':'/apps/target/database/database.sqlite','incoming':'/apps/target/database/.orbit-sqlite-'+'d'*32});self.public['execution_secrets']={};self.public['node_id']=self.i['node_id']
 def run_clone(self,success=True):
  self.cli('workflow','validate','operation-database-clone')
  return reply(self.cli('workflow','run','operation-database-clone','--input',json.dumps(self.public),success=success).stdout)
 def actions(self):return [x['action']for x in self.calls()]
 def test_mysql_source_proof_precedes_native_create_and_copy(self):
  run=self.run_clone();actions=self.actions();self.assertEqual(actions[0],'mysql_clone_probe');self.assertEqual(actions[-1],'mysql_clone_copy');self.assertLess(actions.index('sql_create_database'),actions.index('mysql_clone_copy'));self.assertEqual(self.outcome(run),{'backend':'active'});self.assert_private_absent(json.dumps(self.history(run)))
 def test_source_refusal_performs_no_mutation(self):
  DatabaseCommands.fail(self,'mysql_clone_probe','database.source_missing');run=self.run_clone(False);self.assertEqual(self.actions(),['mysql_clone_probe']);self.assertEqual(self.outcome(run),{'error_code':'database.clone_outcome_unknown'})
 def test_local_sqlite_uses_only_the_local_copy_and_no_vault(self):
  self.sqlite(False);run=self.run_clone();self.assertEqual(self.actions(),['sqlite_clone_local']);self.assertEqual(self.outcome(run),{'backend':'active'})
 def test_remote_sqlite_snapshot_and_both_cleanup_branches(self):
  self.sqlite(True);run=self.run_clone();a=self.actions();self.assertEqual(a[:3],['sqlite_clone_export','sqlite_clone_prepare','sqlite_clone_install']);self.assertCountEqual(a[3:],['sqlite_clone_cleanup_source','sqlite_clone_cleanup_target']);self.assertEqual(self.outcome(run),{'backend':'active'})
 def test_failed_remote_install_cleans_both_sides_and_never_claims_success(self):
  self.sqlite(True);DatabaseCommands.fail(self,'sqlite_clone_install');run=self.run_clone(False);self.assertIn('sqlite_clone_cleanup_source',self.actions());self.assertIn('sqlite_clone_cleanup_target',self.actions());self.assertEqual(self.outcome(run),{'error_code':'database.clone_outcome_unknown'})
 def test_terminal_projection_resume_after_another_root_never_replays_copy(self):
  self.sqlite(True);marker=self.repo/'delivery-failed';marker.touch();self.workflows['workflow-operation-database-clone.yaml']['jobs'][-1]['steps'].append({'name':'fixture_delivery','dependsOn':[{'step':'accept_result','condition':{'type':'succeeded'}}],'task':{'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-delivery-${{ run.id }}','methodName':'execute','inputs':{'run':f'test ! -e "{marker}"'}}});self.save();first=self.run_clone(False);marker.unlink();self.public['operation_id']=18;self.run_clone();before=self.calls();self.cli('workflow','resume','operation-database-clone','--run',first['id']);self.assertEqual(self.calls(),before);self.assertEqual(self.outcome(first),{'backend':'active'})
 def test_cancel_after_remote_export_still_cleans_source_and_incoming(self):
  self.sqlite(True);marker=self.repo/'cancel-ready';fake=self.repo/'fixture-ssh';s=fake.read_text().replace("if v.get('transport_fail')==action:sys.exit(255)","if action=='sqlite_clone_prepare':\n import time\n Path("+repr(str(marker))+").touch();time.sleep(5)\nif v.get('transport_fail')==action:sys.exit(255)");fake.write_text(s)
  env=dict(os.environ,SWAMP_NO_UPDATE_CHECK='1',SWAMP_NO_TELEMETRY='1')
  for key in ['SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT','SWAMP_EXTENSIONS_DIR']:env.pop(key,None)
  process=subprocess.Popen([os.environ.get('SWAMP_BIN','swamp'),'workflow','run','operation-database-clone','--input',json.dumps(self.public),'--json'],cwd=self.repo,env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
  try:
   until=time.monotonic()+90
   while not marker.exists():
    self.assertIsNone(process.poll(),'Native cancellation gate not reached');self.assertLess(time.monotonic(),until,'Native cancellation gate timed out');time.sleep(.05)
   process.send_signal(signal.SIGINT);stdout,stderr=process.communicate(timeout=90);self.assert_private_absent(stdout,stderr);self.assertEqual(reply(stdout)['status'],'cancelled');self.assertIn('sqlite_clone_cleanup_source',self.actions());self.assertIn('sqlite_clone_cleanup_target',self.actions())
  finally:
   if process.poll() is None:process.kill();process.communicate(timeout=10)

if __name__=='__main__':unittest.main()
