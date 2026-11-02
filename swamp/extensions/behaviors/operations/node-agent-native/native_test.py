"""Official local native DAGs; only SSH is replaced, no Node is contacted."""
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest

import yaml
ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase


class NodeAgentNative(NativeTestCase):
    native_timeout=120

    def setUp(self):
        super().setUp()
        self.host=tempfile.TemporaryDirectory(prefix='orbit-agent-host-')
        self.addCleanup(self.host.cleanup)
        Path(self.host.name,'secret').write_text('original-'+self.repo.name)
        self.copy_behaviors('operations','node-worker','automatic-deploy')
        self.pin='a'*64
        self.state={'pin':self.pin,'host':self.host.name}
        self.save()
        self.agent={'configuration':'gateway_url="https://gateway.orbit"\n','certificate':'public CA','unit':'ProtectHome=yes\n','checkouts':[],'apps_root':None,'user':'orbit','url':'https://delivery.test/agent'}
        self.ssh={'control_node_id':209,'identity_file':'/synthetic','target':{'host':'10.44.0.9','user':'orbit','port':22,'host_keys':['synthetic-key']}}
        for number,name in enumerate(['operation-route-caddy','operation-fleet-peer-dns','route-certificate-remove','route-lan-apply']):
            child={'id':f'cb107a8c-4b2d-4bce-91c3-{number:012d}','name':name,'inputs':yaml.safe_load((ROOT/f'workflows/workflow-{name}.yaml').read_text())['inputs'],'jobs':[{'name':'fixture','steps':[{'name':'done','task':{'type':'assert','expr':'true','message':'Fixture native child'}}]}]}
            (self.repo/f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(child))

    def save(self):
        (self.repo/'fixture.json').write_text(json.dumps(self.state))

    def calls(self):
        p=self.repo/'calls.jsonl'
        return [json.loads(line) for line in p.read_text().splitlines()] if p.exists() else []

    def start(self,kind,intent,success=True):
        name={'node.settings':'operation-node-settings','node.agent_rekey':'operation-node-agent-rekey','node.converge':'operation-node-converge-direct'}[kind]
        workflow=yaml.safe_load((ROOT/f'workflows/workflow-{name}.yaml').read_text())
        for job in workflow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('labels'),{'role': 'swamp'})
                task=step.get('task',{})
                if task.get('behaviorType')=='@swamp/ssh':
                    task['behaviorType']='@fixture/node-agent-ssh'
                    task.pop('globalArgs',None)
        (self.repo/f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(workflow,sort_keys=False))
        self.cli('workflow','validate',name)
        reply=self.cli('workflow','run',name,'--input',json.dumps({'operation_id':9,'node_id':9,'kind':kind,'intent':intent,'execution_secrets':{}}),success=success)
        return name,json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]['id']

    def history(self,run):
        return json.loads(self.cli('workflow','history','get',run).stdout)

    def result(self,run):
        return next(s['outputs'] for j in self.history(run)['jobs'] for s in j['steps'] if s['name']=='result')

    def settings(self,root='/srv/apps'):
        return {'agent_pin':self.pin,'ssh':self.ssh,'storage':{'root':root,'user':'orbit','create_missing':True,'protected_roots':['/etc'],'managed_checkouts':[]}}

    def converge(self):
        secret=Path(self.host.name,'secret').read_bytes()
        return {'schema_version':1,'node_id':9,'agent_sha256':self.pin,'user':'orbit','ssh':self.ssh,'verifier_hash':hashlib.sha256(secret).hexdigest(),'force_artifacts':True,'first_visit':False,'refusal':None,'network':None,
            'artifacts':[{'name':n,'digest':'b'*64,'required':True,'repair':False} for n in ['agent','tmpfiles']],
            'payloads':{'agent':self.agent,'tmpfiles':{'rule':'e /tmp/orbit-* - - - 1d'}}}

    def test_settings_calls_existing_commands_without_target_worker(self):
        _,run=self.start('node.settings',self.settings())
        self.assertTrue(self.result(run)['prepared'])
        calls=self.calls()
        self.assertEqual([c['command'] for c in calls],['account.lookup','node.prepare-storage-root'])
        self.assertEqual(calls[1]['envelope']['input']['group'],'orbit')

    def test_settings_refuses_protected_path_before_storage_effect(self):
        _,run=self.start('node.settings',self.settings('/etc/private'),False)
        self.assertEqual(self.result(run)['error_code'],'node.settings_root_failed')
        self.assertEqual([c['command'] for c in self.calls()],['account.lookup'])

    def test_settings_agent_refusal_cannot_commit_settings(self):
        self.state['refuse']='node.prepare-storage-root';self.save()
        _,run=self.start('node.settings',self.settings(),False)
        self.assertEqual(self.result(run)['error_code'],'node.settings_root_failed')

    def test_rekey_returns_only_the_public_verifier_and_keeps_secret_on_node(self):
        _,run=self.start('node.agent_rekey',{'node_id':9,'agent_sha256':self.pin,'configuration':self.agent,'ssh':self.ssh,'configure_operation_id':8})
        result=self.result(run)
        secret=Path(self.host.name,'secret').read_text()
        self.assertEqual(result['verifier_hash'],hashlib.sha256(secret.encode()).hexdigest())
        self.assertTrue(result['rekeyed'])
        self.secrets=(secret,)
        self.assert_private_absent(json.dumps(self.history(run)))

    def test_native_resume_reuses_account_read_after_uncertain_rekey(self):
        self.state['fail']='agent.configure';self.save()
        name,run=self.start('node.agent_rekey',{'node_id':9,'agent_sha256':self.pin,'configuration':self.agent,'ssh':self.ssh,'configure_operation_id':8},False)
        self.cli('workflow','resume',name,'--run',run,'--from','observer_command')
        self.assertEqual(self.history(run)['status'],'succeeded')
        self.assertEqual([c['command'] for c in self.calls()].count('account.lookup'),1)
        self.assertEqual([c['command'] for c in self.calls()].count('agent.configure'),2)
        self.assertEqual(self.result(run)['verifier_hash'],hashlib.sha256(Path(self.host.name,'secret').read_bytes()).hexdigest())

    def test_converge_calls_frozen_install_and_configure_without_worker_material(self):
        _,run=self.start('node.converge',self.converge())
        result=self.result(run)
        self.assertTrue(result['footprint_proven'])
        calls=self.calls()
        command=next(c for c in calls if c['command']=='agent.configure')
        self.assertFalse(command['envelope']['input']['allow_secret_rotation'])
        self.assertIsNone(command['envelope']['input']['worker_credentials'])
        self.assertEqual(command['timeoutSec'],420)
        self.assertIn('agent.install',[c['command'] for c in calls])
        self.assertTrue(any(c['argv'] and c['argv'][0]=='/usr/bin/rm' for c in calls))

    def test_converge_install_failure_stops_host_effects_and_cleans_candidate(self):
        self.state['fail']='agent.install';self.save()
        _,run=self.start('node.converge',self.converge(),False)
        self.assertFalse(self.result(run)['footprint_proven'])
        self.assertNotIn('agent.configure',[c['command'] for c in self.calls()])
        self.assertTrue(any(c['argv'] and c['argv'][0]=='/usr/bin/rm' for c in self.calls()))

    def test_populated_native_footprint_keeps_optional_agent_actions(self):
        intent=self.converge()
        for n in ['caddy-package','php-runtime','private-dns']:
            intent['artifacts'].append({'name':n,'digest':'c'*64,'required':True,'repair':False})
        intent['payloads'].update({'caddy-package':{'release':'2.10.2','amd64_sha512':'f'*128,'minimum_version':'2.10.2'},'php-runtime':{'configurations':[{'version':'8.4','runtime_ini':'public','release':{}},{'version':'8.5','runtime_ini':'public','release':{}}]},'private-dns':{'listener':{},'publication':{'records':'public records','catalog':'{}'}}})
        _,run=self.start('node.converge',intent)
        self.assertTrue(self.result(run)['footprint_proven'])
        calls=[c['command'] for c in self.calls()]
        self.assertEqual(calls.count('php.configure-runtime'),2)
        self.assertIn('node.install-private-dns-listener',calls)
        self.assertIn('caddy.install-package',calls)


if __name__=='__main__':
    unittest.main()
