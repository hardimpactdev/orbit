"""Real official local DAG, with only SSH replaced by disposable public fixtures."""
import json
import sys
import unittest
from pathlib import Path

import yaml

ROOT=Path(__file__).resolve().parents[4]
sys.path.insert(0,str(ROOT/'tests'))
from native_harness import NativeTestCase


class RecipeNative(NativeTestCase):
    native_timeout=120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/fleet-recipe','operations/node-role-command','operations/node-role-converge-command','operations/node-bootstrap','operations/fleet','operations/node','operations/_lib','operations/routes','operations/tools','operations/certificates','node-worker','automatic-deploy')
        workflow=yaml.safe_load((ROOT/'workflows/workflow-operation-fleet-recipe-direct.yaml').read_text())
        for job in workflow['jobs']:
            for step in job['steps']:
                step.pop('target',None)
                task=step.get('task',{})
                if task.get('behaviorType')=='@swamp/ssh':
                    task['behaviorType']='@fixture/recipe-ssh'
                    task.pop('globalArgs',None)
                # No actual identity value or credential transport is needed by this fake.
                if 'vault.get' in str(task):
                    task['inputs']['stdin']='synthetic-observer-reference'
        self.workflow=workflow
        self.path=self.repo/'workflows/workflow-operation-fleet-recipe-direct.yaml'
        self.path.write_text(yaml.safe_dump(workflow,sort_keys=False))
        # The unselected native children still need static identities for validation.
        for number,name in enumerate(['operation-route-caddy','operation-fleet-peer-dns','route-certificate-remove','route-lan-apply']):
            child={'id':f'c9d03e50-2fe8-4ab8-83bf-{number:012d}','name':name,'inputs':yaml.safe_load((ROOT/f'workflows/workflow-{name}.yaml').read_text())['inputs'],'jobs':[{'name':'fake','steps':[{'name':'apply','task':{'type':'assert','expr':'true','message':'Fixture child succeeded'}}]}]}
            (self.repo/f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(child))
        self.intent={'node_id':9,'identity':'sha256:'+'b'*64,'snapshot':'20261031T000000Z','agent_sha256':'a'*64,'cli_version':'0.12.0','automatic':False,'daytime':False,'recipe':{'user':'orbit','snapshot':'20261031T000000Z','tools':{}},'php':None,'incus_hold':[],
            'footprint':{'schema_version':1,'node_id':9,'agent_sha256':'a'*64,'force_artifacts':True,'first_visit':False,'refusal':None,'network':None,'artifacts':[{'name':name,'digest':'c'*64,'required':True,'repair':False} for name in ('agent','tmpfiles')],'payloads':{'agent':{'configuration':'public-config','certificate':'public-ca','unit':'[Service]\nProtectHome=yes\n','apps_root':None,'checkouts':[],'url':'https://delivery.test/observer'},'tmpfiles':{'rule':'e /tmp/orbit-* - - - 1d'}}},
            'cli':{'user':'orbit','release':{'version':'0.12.0','url':'https://delivery.test/orbit','sha256':'d'*64},'profile':'public-profile','skip_reason':None,'plan_error':None},
            'ssh':{'control_node_id':209,'identity_file':'/synthetic','target':{'host':'10.44.0.9','user':'orbit','port':22,'host_keys':['synthetic-host-key']}}}
        self.save()

    def save(self,**extra):
        (self.repo/'fixture.json').write_text(json.dumps({'calls':[],**extra}))

    def calls(self):
        path=self.repo/'calls.jsonl'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def start(self,success=True):
        self.cli('workflow','validate','operation-fleet-recipe-direct')
        result=self.cli('workflow','run','operation-fleet-recipe-direct','--input',json.dumps({'operation_id':7,'node_id':self.intent['node_id'],'kind':'fleet.recipe','intent':self.intent,'execution_secrets':{'observer':{'vault':'orbit-control-inputs','key':'recipe-operation-7-observer'}}}),success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def history(self,root):
        return json.loads(self.cli('workflow','history','get',root).stdout)

    def test_complete_recipe_footprint_and_delivery_in_one_native_root(self):
        root=self.start()
        calls=self.calls()
        self.assertLess(calls.index('packages'),calls.index('browser'))
        self.assertLess(calls.index('browser'),calls.index('javascript'))
        self.assertLess(calls.index('javascript'),calls.index('services'))
        self.assertLess(calls.index('verify'),calls.index('delivery'))
        result=next(s for j in self.history(root)['jobs'] for s in j['steps'] if s['name']=='result')
        public=json.loads(result['outputs']['stdout'])
        self.assertTrue(public['footprint']['footprint_proven'])
        self.assertEqual(public['identity'],self.intent['identity'])
        self.assertEqual(len(public['footprint']['artifacts']),2)

    def test_unchanged_node_with_no_service_units_still_finishes(self):
        self.save(noServices=True)
        root=self.start()
        self.assertIn('delivery',self.calls())
        self.assertEqual(self.history(root)['status'],'succeeded')

    def test_caddy_render_refusal_keeps_the_last_good_publication(self):
        self.intent['footprint']['artifacts'].append({'name':'caddy','digest':'c'*64,'required':True,'repair':False})
        self.intent['footprint']['payloads']['caddy']={'error_code':'caddy.render_failed','stage':'render'}
        root=self.start()
        result=next(s for j in self.history(root)['jobs'] for s in j['steps'] if s['name']=='result')
        caddy=next(a for a in json.loads(result['outputs']['stdout'])['footprint']['artifacts'] if a['name']=='caddy')
        self.assertEqual(caddy,{'name':'caddy','status':'skipped','reason':'caddy_render_refused'})
        self.assertNotIn('caddy-validation',self.calls())

    def test_caddy_validation_refusal_is_a_safe_native_skip(self):
        self.save(caddyInvalid=True)
        self.intent['footprint']['artifacts'].append({'name':'caddy','digest':'c'*64,'required':True,'repair':False})
        self.intent['footprint']['payloads']['caddy']={'content':'public candidate','version':'b'*32,'listen_addresses':[],'unserved':False}
        root=self.start()
        history=self.history(root)
        child=next(s for j in history['jobs'] for s in j['steps'] if s['name']=='caddy-0')
        self.assertEqual(child['status'],'skipped')
        result=next(s for j in history['jobs'] for s in j['steps'] if s['name']=='result')
        caddy=next(a for a in json.loads(result['outputs']['stdout'])['footprint']['artifacts'] if a['name']=='caddy')
        self.assertEqual(caddy['reason'],'caddy_validate_refused')

    def test_optional_runtime_versions_and_native_children_are_not_dropped(self):
        fp=self.intent['footprint']
        for name in ('caddy','php-runtime','private-dns','route-residue'):
            fp['artifacts'].append({'name':name,'digest':'c'*64,'required':True,'repair':False})
        fp['payloads']['caddy']={'content':'public candidate','version':'b'*32,'listen_addresses':[],'unserved':False}
        fp['payloads']['php-runtime']={'configurations':[{'version':'8.4'},{'version':'8.5'}]}
        fp['payloads']['private-dns']={'listener':{},'publication':{'records':'public records','catalog':'{}'}}
        fp['payloads']['route-residue']={'rows':[{'steps':['caddy']}],'caddy':fp['payloads']['caddy'],'php':{'8.5':'user = __orbit_converge_group__'},'certificates':['route-1'],'lan':{'node_id':9,'rules':[],'migrate':True},'ingress_commands':None}
        self.intent['php']={'versions':{'8.5':{}}}
        root=self.start()
        history=self.history(root)
        names={s['name']:s['status'] for j in history['jobs'] for s in j['steps']}
        for name in ('php_plan-0','php_build-0','php_runtime-0','php_runtime-1','caddy-0','listener-0','dns-0','residue_php-0','residue_cert-route-1','residue_lan-0'):
            self.assertEqual(names[name],'succeeded',name)
        self.assertEqual(self.calls().count('php.configure-runtime'),2)
        calls=self.calls()
        self.assertLess(calls.index('/usr/bin/systemctl restart orbit-private-dns.service'),calls.index('node.private-dns-health'))

    def test_private_dns_validation_refusal_keeps_live_files_untouched(self):
        self.save(dnsValidationInvalid=True)
        self.intent['footprint']['artifacts'].append({'name':'private-dns','digest':'c'*64,'required':True,'repair':False})
        self.intent['footprint']['payloads']['private-dns']={'listener':{},'publication':{'records':'public records','catalog':'{}'}}
        root=self.start(success=False)
        history=self.history(root)
        steps={s['name']:s for job in history['jobs'] for s in job['steps']}
        self.assertEqual(steps['dns_records_command-0']['status'],'skipped')
        self.assertNotIn('node.private-dns-restore',self.calls())

    def test_private_dns_failed_catalog_proof_stops_delivery(self):
        self.save(dnsInvalid=True)
        self.intent['footprint']['artifacts'].append({'name':'private-dns','digest':'c'*64,'required':True,'repair':False})
        self.intent['footprint']['payloads']['private-dns']={'listener':{},'publication':{'records':'public records','catalog':'{}'}}
        self.start(success=False)
        self.assertIn('node.private-dns-health',self.calls())
        self.assertNotIn('delivery',self.calls())
        self.assertIn('node.private-dns-restore',self.calls())
        self.assertIn('/usr/bin/systemctl stop orbit-private-dns.service',self.calls())
        self.assertIn('/usr/bin/systemctl disable orbit-private-dns.socket',self.calls())

    def test_refusal_before_first_host_effect(self):
        self.intent['footprint']['refusal']='node.footprint_failed'
        self.start(success=False)
        self.assertEqual(self.calls(),[])

    def test_uncertain_runtime_native_resume_keeps_completed_package_step(self):
        self.save(fail='javascript')
        root=self.start(success=False)
        self.assertNotIn('delivery',self.calls())
        self.assertEqual(self.calls().count('packages'),1)
        self.cli('workflow','resume','operation-fleet-recipe-direct','--run',root,'--from','javascript_command')
        self.assertEqual(self.calls().count('packages'),1)
        self.assertEqual(self.calls().count('javascript'),2)
        self.assertEqual(self.history(root)['status'],'succeeded')

    def test_all_node_targets_use_209_with_their_saved_host_keys(self):
        source=yaml.safe_load((ROOT/'workflows/workflow-operation-fleet-recipe-direct.yaml').read_text())
        for node in (4,8,9,14,45,202,206,207,208,209,210,212):
            for job in source['jobs']:
                for step in job['steps']:
                    self.assertEqual(step['target'],'orbit-node-209')
                    globals_=step.get('task',{}).get('globalArgs',{})
                    if globals_.get('hosts'):
                        self.assertIn('inputs.intent.ssh.target.host_keys',globals_['hosts'][0]['transport']['hostKeys'])
        self.assertNotIn('/api/v1/direct-operations',str(source))
        self.assertNotIn('orbit-node-" +',str(source))


if __name__=='__main__':
    unittest.main()
