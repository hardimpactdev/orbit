"""Actual native WebSocket graph, official SSH, real disposable file/link snapshots."""
import json
import os
import pwd
import shutil
import socket
import sys
import tempfile
import unittest
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class WebSocketNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        remote = tempfile.TemporaryDirectory(prefix='orbit-websocket-host-')
        self.addCleanup(remote.cleanup)
        self.host = Path(remote.name)
        self.copy_behaviors('operations/node-role-converge-command', 'operations/node-role-command', 'operations/node', 'operations/integrations', 'operations/fleet', 'operations/_lib')
        routes = self.repo/'extensions/behaviors/operations/routes'
        routes.mkdir()
        shutil.copy(ROOT/'extensions/behaviors/operations/routes/caddy-input.ts', routes)
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.unit=self.host/'orbit-websocket.service'
        self.user=pwd.getpwuid(os.getuid()).pw_name
        self.unit_before='# Managed by Orbit.\nUser='+self.user+'\nold unit\n'
        self.unit.write_text(self.unit_before)
        self.install=self.host/'reverb'
        self.previous=Path(str(self.install)+'.releases')/('release.'+'a'*32)
        self.previous.mkdir(parents=True)
        self.install.symlink_to(self.previous)
        self.audit=self.host/'audit.jsonl'
        self.failure=self.host/'failure'
        self.state=self.host/'state.json';self.state.write_text(json.dumps({'active':True,'enabled':True}))
        fixture=ROOT/'tests/_support/websocket_role_ssh.py'
        binary=self.repo/'ssh-fixture'
        binary.write_text('#!'+sys.executable+'\n'+fixture.read_text().replace('FIXTURE_ROOT = None','FIXTURE_ROOT = '+repr(str(self.host))))
        binary.chmod(0o700)
        flow=yaml.safe_load((ROOT/'workflows/workflow-node-role-websocket.yaml').read_text())
        def rewrite(value):
            if isinstance(value,dict):
                return {k:rewrite(v) for k,v in value.items() if k!='target'}
            if isinstance(value,list):return [rewrite(v) for v in value]
            if isinstance(value,str):return value.replace('/etc/systemd/system/orbit-websocket.service',str(self.unit))
            return value
        flow=rewrite(flow)
        for step in flow['jobs'][0]['steps']:
            task=step['task']
            if task.get('behaviorType')=='@swamp/ssh':task['globalArgs']['sshBinary']=str(binary)
        (self.repo/'workflows/workflow-node-role-websocket.yaml').write_text(yaml.safe_dump(flow,sort_keys=False))
        self.listener=socket.socket();self.listener.bind(('127.0.0.1',0));self.listener.listen(32)
        self.addCleanup(self.listener.close)
        identity=self.repo/'identity';identity.write_text('disposable fixture')
        self.payload={'runtime':{'node_id':5,'assignment_id':17,'user':self.user,'install_path':str(self.install),'repository':'https://example.invalid/reverb.git','ref':'b'*40,'port':self.listener.getsockname()[1]},'ssh':{'identity_file':str(identity),'target':{'host':'host.invalid','user':self.user,'port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}},'material':{'vault':'orbit-control-inputs','key':'role-runtime'}}
        secret='synthetic-'+os.urandom(12).hex()
        self.secrets=(secret,)
        self.environment='APP_KEY='+secret+'\n'
        self.cli('vault','create','local_encryption','orbit-control-inputs')
        self.cli('vault','put','orbit-control-inputs','role-runtime',input=json.dumps({'websocket_environment':self.environment}))

    def run_runtime(self, success=True):
        self.cli('workflow','validate','node-role-websocket')
        run=json.loads(self.cli('workflow','run','node-role-websocket','--input',json.dumps(self.payload),success=success).stdout)
        history=json.loads(self.cli('workflow','history','get',run['id']).stdout)
        return history

    def test_success_validates_before_cutover_and_keeps_environment_private(self):
        history=self.run_runtime()
        self.assertEqual(history['status'],'succeeded')
        release=self.install.resolve();self.assertNotEqual(release,self.previous)
        self.assertEqual((release/'.env').read_text(),self.environment)
        actions=[json.loads(row)['action'] for row in self.audit.read_text().splitlines()]
        self.assertLess(actions.index('unit_verify'),actions.index('stop'))
        self.assertLess(actions.index('artisan_verify'),actions.index('stop'))
        self.assertTrue(json.loads(self.state.read_text())['active'])

    def test_failed_activation_restores_prior_release_and_unit(self):
        self.failure.write_text('restart')
        history=self.run_runtime(False)
        self.assertEqual(history['status'],'failed')
        self.assertEqual(self.install.resolve(),self.previous)
        self.assertEqual(self.unit.read_text(),self.unit_before)
        self.assertTrue(json.loads(self.state.read_text())['active'])
        steps={s['name']:s for j in history['jobs'] for s in j['steps']}
        self.assertEqual(steps['websocket_recover']['status'],'succeeded')
        self.assertEqual(steps['result']['status'],'skipped')
        self.failure.unlink()
        # Retrying only activation must never accept the restored old release.
        unsafe=json.loads(self.cli('workflow','resume','node-role-websocket','--run',history['id'],'--from','websocket_restart',success=False).stdout)
        self.assertEqual(unsafe['id'],history['id'])
        self.assertEqual(self.install.resolve(),self.previous)
        # Republish from the native unit step, retaining the original before snapshot.
        resumed=json.loads(self.cli('workflow','resume','node-role-websocket','--run',history['id'],'--from','websocket_unit_assets').stdout)
        self.assertEqual(resumed['id'],history['id'])
        self.assertEqual(resumed['status'],'succeeded')
        self.assertNotEqual(self.install.resolve(),self.previous)
        self.assertEqual((self.install.resolve()/'.env').read_text(),self.environment)

    def test_release_validation_failure_leaves_prior_runtime_ready(self):
        self.failure.write_text('artisan_verify')
        self.run_runtime(False)
        self.assertEqual(self.install.resolve(),self.previous)
        self.assertTrue(json.loads(self.state.read_text())['active'])


if __name__=='__main__':unittest.main()
