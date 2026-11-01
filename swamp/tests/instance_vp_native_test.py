"""Pinned native VP jobs; official SSH with disposable filesystem and CLI seams."""
import json
import os
from pathlib import Path
import subprocess
import unittest
import yaml
import pwd
import shutil
import sys
from native_harness import ROOT, NativeTestCase

class InstanceVpNative(NativeTestCase):
 def setUp(self):
  super().setUp()
  self.copy_behaviors('operations/instance-vp-command')
  for family, files in {'instance-deploy':['vp_requirements.ts'], 'tools':['constraints.ts','composer-constraints.json'], 'node-bootstrap':['tool_setup_assets.ts']}.items():
   target=self.repo/'extensions/behaviors/operations'/family; target.mkdir(parents=True,exist_ok=True)
   for file in files: shutil.copy(ROOT/'extensions/behaviors/operations'/family/file,target/file)
  shutil.copytree(ROOT/'extensions/behaviors/operations/node-bootstrap/tool_scripts',self.repo/'extensions/behaviors/operations/node-bootstrap/tool_scripts')
  self.cli('extension','pull','@swamp/ssh@2026.10.23.1')
  self.control=self.repo/'control'; self.control.mkdir()
  self.host=self.repo/'host'; self.host.mkdir()
  self.application=self.host/'app'; self.application.mkdir()
  (self.application/'package.json').write_text('{}')
  identity=self.repo/'identity'; identity.write_text('synthetic identity'); identity.chmod(0o600)
  self.input={'intent':{'application':str(self.application),'vp_setup':None,'ssh_execution':{'identity_file':str(identity),'target':{'host':'node.invalid','port':22,'user':pwd.getpwuid(os.geteuid()).pw_name,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}}}
  ssh=self.repo/'ssh'; ssh.write_text('#!'+sys.executable+'\n'+SSH.replace('CONTROL',repr(str(self.control))).replace('HOST',repr(str(self.host))));ssh.chmod(0o700)
  scp=self.repo/'scp'; scp.write_text('#!'+sys.executable+'\n'+SCP.replace('CONTROL',repr(str(self.control))).replace('HOST',repr(str(self.host))));scp.chmod(0o700)
  # The filesystem seam replaces only the observed machine scope with a disposable fixture.
  scope=self.host/'vp-store';(scope/'bin').mkdir(parents=True)
  self.scope=scope;self.calls=self.host/'vp-calls';self.old=False
  self.vp=scope/'bin/vp'
  self.vp.write_text('#!/usr/bin/env python3\nimport json,sys\nfrom pathlib import Path\np=Path('+repr(str(self.calls))+')\np.write_text((p.read_text() if p.exists() else "")+json.dumps(sys.argv[1:])+"\\n")\nif sys.argv[1:]==["--version"]: print("vp 1.4.0")\nelif "list" in sys.argv: print(json.dumps({"node":[{"version":"24.2.0"}],"package_managers":{"pnpm":[{"version":"10.0.0"}]}}))\n')
  self.vp.chmod(0o700)
  material=self.repo/'extensions/behaviors/operations/instance-vp-command/scripts/project_material.py'
  material.write_text(material.read_text().replace("candidates = [Path('/opt/orbit/vite-plus'), home / '.vite-plus', home / '.local/share/vite-plus']",'candidates = [Path('+repr(str(scope))+')]'))
  deno=os.environ.get('DENO_BIN','/tmp/lane4-deno-2.9.8/deno')
  module=(ROOT/'extensions/behaviors/operations/instance-vp-command/steps.ts').as_uri()
  graph=subprocess.run([deno,'eval','import {vpPreparationJobs} from '+json.dumps(module)+';console.log(JSON.stringify(vpPreparationJobs("vp","inputs.intent","before")))'],check=True,capture_output=True,text=True)
  jobs=[{'name':'before','steps':[{'name':'ready','task':{'type':'assert','expr':'true','message':'Fixture ready'}}]}]+json.loads(graph.stdout.replace('/tmp/orbit-instance-',str(self.control)+'/stage-'))
  for job in jobs:
   job.pop('target',None)
   for step in job['steps']:
    step.pop('target',None)
    task=step['task']
    if task.get('behaviorType')=='@swamp/ssh':
     task['globalArgs'].update(sshBinary=str(self.repo/'ssh'),scpBinary=str(self.repo/'scp'))
  workflow={'id':'97d29bb9-9313-4e16-95fb-f4db2850b3fb','name':'instance-vp-fixture','version':1,'inputs':{'intent':{'type':'object'}},'jobs':jobs}
  (self.repo/'workflows/workflow-vp.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
  # Official SSH executes only these local fixtures, plus ordinary script/copy setup.
  ssh=self.repo/'ssh'
  ssh.write_text(ssh.read_text().replace("if command.startswith('test ')","if 'vp-store/bin/vp' in command or command.startswith('test ')"))
 def run_vp(self,success=True):
  run=json.loads(self.cli('workflow','run','instance-vp-fixture','--input',json.dumps({'intent':self.input['intent']}),success=success).stdout)
  return json.loads(self.cli('workflow','history','get',run['id']).stdout)
 def pins(self):
  (self.application/'package.json').write_text(json.dumps({'devDependencies':{'vite-plus':'0.2.1'},'packageManager':'pnpm@10.0.0'}))
  (self.application/'.node-version').write_text('24.2.0')
  (self.application/'pnpm-lock.yaml').write_text('importers:\n  .:\n    devDependencies:\n      vite-plus:\n        version: 0.2.1\n')
 def test_no_vp_is_successful_without_installer_or_cli(self):
  history=self.run_vp();self.assertEqual(history['status'],'succeeded');self.assertFalse(self.calls.exists())
 def test_existing_manager_uses_stock_install_then_read_only_inventory(self):
  self.pins();history=self.run_vp();self.assertEqual(history['status'],'succeeded')
  calls=[json.loads(row) for row in self.calls.read_text().splitlines()]
  self.assertEqual(calls[0],['--version']);self.assertEqual(calls[1][-4:],['env','install','node@24.2.0','pnpm@10.0.0']);self.assertEqual(calls[2][-3:],['env','list','--json'])
 def test_old_cli_refuses_before_project_install(self):
  self.pins();self.vp.write_text(self.vp.read_text().replace('vp 1.4.0','vp 0.1.0'))
  self.run_vp(False);self.assertEqual([json.loads(row) for row in self.calls.read_text().splitlines()],[['--version']])

SSH = '''import os,subprocess,sys
if '-O' in sys.argv or '-N' in sys.argv or '-MNf' in sys.argv: sys.exit(0)
command=sys.argv[-1].replace(CONTROL,HOST)
body=sys.stdin.buffer.read().replace(CONTROL.encode(),HOST.encode())
if 'sudo' in command or b'Install only the admitted' in body: raise AssertionError('Unadmitted installer in no-VP fixture')
if command.startswith('test ') or command.startswith('mkdir ') or command.startswith('chmod ') or command.startswith('rm ') or command.startswith('python') or '/usr/bin/' in command or command.startswith('sh ') or command.startswith('/bin/'):
    result=subprocess.run(['/bin/sh','-c',command],input=body);sys.exit(result.returncode)
raise AssertionError('Unexpected SSH action: '+command)
'''
SCP = '''import pathlib,shutil,sys
source,destination=sys.argv[-2:]
def local(value):
    if 'node.invalid:' in value: return value.split(':',1)[1].replace(CONTROL,HOST)
    return value
source,destination=map(local,(source,destination))
src,dst=pathlib.Path(source),pathlib.Path(destination)
if src.is_dir():
    dst.mkdir(parents=True,exist_ok=True)
    target=dst/src.name if not source.endswith('/.') else dst
    shutil.copytree(src,target,dirs_exist_ok=True)
else:
    if dst.is_dir(): dst=dst/src.name
    shutil.copy2(src,dst)
'''


if __name__=='__main__':unittest.main()
