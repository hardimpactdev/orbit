"""Native Router set and unset ordering in a disposable repository."""
import json
from pathlib import Path
import shutil
import sys
import unittest
import yaml
ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase
class ClusterNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy','operation-runner','operations/_lib','operations/certificates')
        for directory,names in [('operations/node',['role_baseline_system.ts','system_command.ts']),('node-worker',['command.ts'])]:
            target=self.repo/'extensions/behaviors'/directory;target.mkdir(parents=True,exist_ok=True)
            for name in names: shutil.copy(ROOT/'extensions/behaviors'/directory/name,target)
        failure=self.repo/'extensions/behaviors/operations/failure.ts'
        failure.write_text('import {fixtureHttp} from "./routes/cluster-router_native_fixture.ts";\n'+(ROOT/'extensions/behaviors/operations/failure.ts').read_text().replace('export const behavior = nativeFailure();','export const behavior = nativeFailure(() => fixtureHttp);'))
        route=self.repo/'extensions/behaviors/operations/routes';route.mkdir(parents=True)
        for name in ['cluster-router.ts','cluster-router_native_fixture.ts','verification.ts','removal-local.ts','lan.ts','local-program.ts','firewall-program.ts','firewall-local.ts','router-preparation.ts','router-lan.ts','caddy-program.ts']:
            shutil.copy(ROOT/'extensions/behaviors/operations/routes'/name,route)
        cert=self.repo/'extensions/behaviors/operations/certificates/mod.ts'
        cert.write_text('import {fixtureHttp,record} from "../routes/cluster-router_native_fixture.ts";\n'+cert.read_text().replace('...certificates()', '...certificates(() => fixtureHttp, { request: async () => { await record("certificate-request"); return {state:"current"}; }, publish: async () => { await record("certificate-publish"); return {applied:true}; }, inspect: async () => { await record("certificate-inspect"); return {present:true}; }, remove: async () => ({applied:true}) })'))
        module=route/'cluster-router.ts'
        text=module.read_text().replace('export const behavior = clusterRouter();','export const behavior = clusterRouter(() => fixtureHttp, invoke, undefined, readAccount);')
        # Only replace the actual clock in this fixture; source keeps the full 31 seconds.
        self.assertIn('setTimeout(resolve, 31000)',text)
        text=text.replace('setTimeout(resolve, 31000)','setTimeout(() => { record("dns-grace-31").then(resolve); }, 1)')
        module.write_text('import {fixtureHttp,invoke,record,readAccount} from "./cluster-router_native_fixture.ts";\n'+text)
        (route/'publication.ts').write_text('import {z} from "npm:zod@4.4.3"; import {record} from "./cluster-router_native_fixture.ts"; export const behavior={type:"@nckrtl/route-publication",version:"2026.10.31.1",globalArguments:z.object({}),methods:Object.fromEntries(["publishDns","publishCaddy"].map(name=>[name,{description:name,arguments:z.object({operation_id:z.number(),node_id:z.number(),kind:z.string(),run_id:z.string(),target_node_id:z.number()}),execute:()=>record(name)}]))};')
        source=yaml.safe_load((ROOT/'workflows/workflow-operation-cluster-router.yaml').read_text())
        for job in source['jobs']:
            for step in job['steps']:
                step.pop('target',None);step.pop('labels',None)
        (self.repo/'workflows/workflow-operation-cluster-router.yaml').write_text(yaml.safe_dump(source,sort_keys=False))
        identity=dict(node_id=18,agent_sha256='a'*64,user='orbit',home='/home/orbit')
        candidate=dict(identity,assignment_id=2,active=False,caddy_package={},prerequisites={},firewall=dict(desired=[],remove=[],enable=False),router_lan=dict(desired=[],expand=True,prune=True),probes=[])
        old=dict(identity,node_id=9,assignment_id=1,firewall=dict(desired=[],remove=[],enable=False),router_lan=dict(desired=[],expand=False,prune=True),certificate_scopes=[])
        inventory=dict(cluster_id=3,candidate=candidate,noop=False,old_nodes=[old],leaves=[],workloads=[],route_ids=[],edge_node_ids=[],dns_node_ids=[1])
        self.state=dict(events=[],operation=dict(id=17,kind='cluster.router_set',node_id=18,status='queued',cancel_requested=False,input=dict(cluster_router=inventory)))
        self.save()
    def save(self):
        (self.repo/'native-cluster.json').write_text(json.dumps(self.state))
    def run_workflow(self,success=True):
        self.cli('workflow','validate','operation-cluster-router')
        self.cli('workflow','run','operation-cluster-router','--input','operation_id=17','--input','node_id=18','--input','kind='+self.state['operation']['kind'],success=success)
        return json.loads((self.repo/'native-cluster.json').read_text())
    def test_set_prepares_selects_publishes_waits_and_cleans_old_baseline(self):
        state=self.run_workflow()
        self.assertEqual(state['events'],['caddy.install-package','role.install-prerequisites','firewall.rules','firewall.router-lan','router-serving','publishCaddy','router-selected','publishDns','publishDns','dns-grace-31','old-router-withdrawn','publishCaddy','firewall.rules','firewall.router-lan','record'])
    def test_unset_skips_candidate_and_cleans_old_baseline_after_dns(self):
        self.state['operation']['kind']='cluster.router_unset'
        self.state['operation']['input']['cluster_router']['candidate']=None;self.save()
        state=self.run_workflow()
        self.assertNotIn('role.install-prerequisites',state['events'])
        self.assertLess(state['events'].index('old-router-withdrawn'),state['events'].index('publishDns'))
        self.assertEqual(state['events'][-3:],['firewall.rules','firewall.router-lan','record'])
    def test_nonempty_certificate_batch_runs_on_candidate_before_router_publication(self):
        leaf=dict(node_id=18,scope='route-7-router',home='/home/orbit',agent_sha256='a'*64,domain='demo.orbit',version='b'*16,root_hash='c'*64,user='orbit')
        self.state['operation']['input']['cluster_router']['leaves']=[leaf];self.save()
        state=self.run_workflow()
        self.assertLess(state['events'].index('certificate-request'),state['events'].index('certificate-publish'))
        self.assertLess(state['events'].index('certificate-inspect'),state['events'].index('router-serving'))
    def test_home_less_unset_resolves_old_account_before_certificate_retirement(self):
        self.state['operation']['kind']='cluster.router_unset'
        inventory=self.state['operation']['input']['cluster_router'];inventory['candidate']=None
        old=inventory['old_nodes'][0];old.pop('home');old['certificate_scopes']=['route-7-router'];self.save()
        state=self.run_workflow()
        self.assertEqual('succeeded',state['operation']['status'])
        self.assertEqual(['orbit'],state['accounts'])
        self.assertLess(state['events'].index('publishCaddy'),state['events'].index('certificate.remove-leaf'))
        self.assertLess(state['events'].index('certificate.inspect-leaf'),state['events'].index('firewall.rules'))
    def test_home_less_set_resolves_candidate_prerequisites_on_the_worker(self):
        inventory=self.state['operation']['input']['cluster_router']
        inventory['candidate'].pop('home');inventory['candidate']['prerequisites']={'packages':['git']}
        inventory['old_nodes']=[];self.save();state=self.run_workflow()
        self.assertEqual('succeeded',state['operation']['status'])
        self.assertEqual(['orbit'],state['accounts'])
        prepared=next(call for call in state['calls'] if call.get('name')=='role.install-prerequisites')
        self.assertEqual(dict(packages=['git'],user='orbit',group='orbit',home='/home/orbit'),prepared['input'])
    def test_current_router_set_records_success_without_any_host_or_dns_effect(self):
        inventory=self.state['operation']['input']['cluster_router']
        inventory.update(noop=True,old_nodes=[],edge_node_ids=[14],dns_node_ids=[1,14],workloads=[dict(node_id=13,agent_sha256='a'*64,user='orbit',home='/home/orbit',route_id=7,firewall=dict(route_id=7,router='192.0.2.18',workload='192.0.2.13'))])
        inventory['candidate']['active']=True;self.save()
        state=self.run_workflow()
        self.assertEqual(['record'],state['events'])
        self.assertEqual('succeeded',state['operation']['status'])
    def test_cancellation_starts_no_router_effect(self):
        self.state['operation']['cancel_requested']=True;self.save()
        state=self.run_workflow(success=False)
        self.assertEqual(state['events'],[])
    def test_dns_failure_preserves_old_caddy_certificates_and_baseline(self):
        self.state['fail']='publishDns';self.save();state=self.run_workflow(success=False)
        self.assertNotIn('old-router-withdrawn',state['events'])
        self.assertNotIn('record',state['events'])
if __name__=='__main__':unittest.main()
