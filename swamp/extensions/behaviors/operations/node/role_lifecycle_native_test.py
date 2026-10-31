"""The real native engine composes target and source under one root; all host effects are injected."""
import sys
from pathlib import Path
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parent))
from role_direct_native_test import DirectRoleNative


def removal(node_id=13):
    return {'schema_version':1,'node_id':node_id,'assignment_id':33,'role':'ci-runner','agent_sha256':'a'*64,
            'user':'nckrtl','purge_data':False,'offline':False,
            'publication':{'caddy_node_ids':[],'php_node_ids':[],'dns_node_ids':[]},
            'certificates':{'leaves':[],'withdrawals':[]},'metrics_fleet':None,'runtime':None,
            'caddy':None,'firewall':None,'public_ssh':None}


class RoleLifecycleNative(DirectRoleNative):
    def test_remove_composes_the_shared_source_chain_without_a_child_run(self):
        self.workflow_name='operation-node-role-remove-direct'
        self.set_workflow()
        self.state['operation']['kind']='node.role_remove'
        self.state['operation']['input']=removal()
        self.save();self.run_workflow()
        state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertEqual(state['effects'],['source:13:'+stage for stage in ['public-ssh','runtime','ci-host','gateway-dns','firewall']])

    def test_failed_metrics_fleet_never_starts_source_withdrawal(self):
        self.workflow_name='operation-node-role-remove-direct';self.set_workflow()
        self.state['operation']['kind']='node.role_remove';self.state['operation']['input']=removal()
        self.state['operation']['input']['metrics_fleet']={'metrics_node_id':13,'metrics_address':'10.44.0.13','fleet':[{'node_id':13,'address':'10.44.0.13','agent_sha256':'a'*64,'worker':True,'selected':True,'caddy_managed':False,'fpm':False,'service':{'config':None},'pools':[]}]}
        self.state['metrics_failure']='apply';self.save()
        module=self.repo/'extensions/behaviors/operations/node/role_metrics.ts'
        module.write_text('import {fixtureMetricsStage} from "./role_removal_native_fixture.ts";\n'+module.read_text().replace('roleMetrics(() => fixtureHttp);','roleMetrics(() => fixtureHttp,fixtureMetricsStage);'))
        self.run_workflow(success=False);state=self.read()
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result']['error_code'],'node_role.remove_failed')
        self.assertFalse(any(str(event).startswith('source:') for event in state['effects']))

    def set_workflow(self):
        import json,subprocess
        source=Path(__file__).resolve().parents[4]/('workflows/workflow-'+self.workflow_name+'.yaml')
        result=subprocess.run(['/tmp/lane0d-deno/deno','eval','import {parse} from "jsr:@std/yaml@1.0.12";console.log(JSON.stringify(parse(await new Response(Deno.stdin.readable).text())));'],input=source.read_text(),text=True,capture_output=True,check=True)
        def local(v):
            if isinstance(v,list):return [local(x) for x in v]
            if isinstance(v,dict):return {k:local(x) for k,x in v.items() if k not in ['target','labels']}
            return v
        (self.repo/'workflows'/source.name).write_text(json.dumps(local(json.loads(result.stdout))))

    def relocate(self,source=18):
        self.workflow_name='operation-node-role-relocate-direct';self.set_workflow()
        self.state['operation']['kind']='node.role_relocate'
        self.state['operation']['input'].update(source_removal=removal(source),withdrawal_grace_seconds=31)
        self.save()

    def test_relocation_keeps_exact_light_source_identity_after_target_ready(self):
        self.relocate(18);self.run_workflow();state=self.read()
        self.assertEqual(state['operation']['status'],'succeeded')
        self.assertTrue(state['target_ready'])
        self.assertIn('grace:31',state['effects'])
        self.assertIn('source:18:runtime',state['effects'])
        self.assertEqual(state['effects'].count('source:18:runtime'),1)

    def test_source_failure_reports_failed_parent_after_confirmed_target(self):
        self.relocate(1);self.state['source_failure']='runtime';self.save()
        self.run_workflow(success=False);state=self.read()
        self.assertTrue(state['target_ready'])
        self.assertEqual(state['operation']['status'],'failed')
        self.assertEqual(state['operation']['result']['error_code'],'node_role.source_cleanup_failed')
        self.assertNotIn('source:1:firewall',state['effects'])


if __name__=='__main__':unittest.main()
