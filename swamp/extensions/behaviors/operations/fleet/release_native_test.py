"""Exercise shipped parent DAGs in the native engine, with only effects and HTTP faked."""
import json
import os
from pathlib import Path
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class FleetReleaseNative(NativeTestCase):
    native_timeout = 60

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operations', 'node-worker')
        module = self.repo / 'extensions/behaviors/operations/fleet-recipe/fleet_release.ts'
        module.write_text('import { http } from "./release_native_fixture.ts";\n' +
                          module.read_text().replace('fleetRelease();', 'fleetRelease(() => http, () => new Date("2026-10-31T02:30:00Z"));'))
        # Only the SSH and file transports are held: the observer fetch records the effect, the SSH reads print the running digest.
        (self.repo / 'extensions/behaviors/fleet-host-fixture.ts').write_text('''import {z} from "npm:zod@4.4.3";
const node=(host:string)=>Number(host.split(".").pop());
const load=()=>JSON.parse(Deno.readTextFileSync("fleet-native.json"));const save=(s:any)=>Deno.writeTextFileSync("fleet-native.json",JSON.stringify(s));
export const behavior={type:"@fixture/fleet-host",version:"2026.11.02.1",globalArguments:z.object({nodeHost:z.string()}).passthrough(),resources:{output:{schema:z.object({stdout:z.string()}),lifetime:"1d",garbageCollection:10},state:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{
apply:{description:"Fake observer fetch",arguments:z.object({}),execute:async(_:any,ctx:any)=>{const s=load();const n=node(ctx.globalArgs.nodeHost);s.effects.push(`observer:${n}`);save(s);
if(s.observer_failure===n)throw new Error("Checksum mismatch");
return {dataHandles:[await ctx.writeResource("state",ctx.globalArgs.nodeHost,(s.unchanged?{status:"compliant",changes:[]}:{status:"applied",changes:["downloaded"]}))]};}},
exec:{description:"Fake SSH",arguments:z.object({argv:z.array(z.string())}).passthrough(),execute:async(a:any,ctx:any)=>{const s=load();const n=node(ctx.globalArgs.nodeHost);
if(a.argv.includes("restart")){s.restarts=(s.restarts||[]).concat([n]);save(s);return {stdout:""};}
const out={stdout:`${s.running?.[n]??"b".repeat(64)}  /proc/41/exe\\nactive\\n`};await ctx.writeResource("output","output",out);return out;}}}};''')
        shutil.copy(ROOT / 'extensions/behaviors/operations/fleet/release_native_fixture.ts', module.parent)
        # The real node-cli-install child has its own native test; this one records the effect and fails on request.
        (self.repo / 'extensions/behaviors/fleet-cli-fixture.ts').write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@fixture/fleet-cli",version:"2026.11.02.1",resources:{result:{schema:z.record(z.string(),z.unknown()),lifetime:"1d",garbageCollection:10}},methods:{
install:{description:"Fake native CLI child",arguments:z.object({node_id:z.number(),version:z.string()}),execute:async(a:{node_id:number;version:string},ctx:any)=>{
const state=JSON.parse(await Deno.readTextFile("fleet-native.json"));state.effects.push(`cli:${a.node_id}`);await Deno.writeTextFile("fleet-native.json",JSON.stringify(state));
if(state.failure===a.node_id&&!state.uncertain)throw new Error("checksum mismatch");
const result={installed:a.version,configured:true,version:a.version};await ctx.writeResource("result","result",result);return result;}}}};''')
        (self.repo / 'workflows/workflow-node-cli-install.yaml').write_text(json.dumps({
            'id': '440d10ad-68c2-4f59-a3ae-0ce705a84e47', 'name': 'node-cli-install', 'inputs': {'type': 'object', 'properties': {
                'node_id': {'type': 'integer'}, 'intent': {'type': 'object'}}, 'required': ['node_id', 'intent']},
            'jobs': [{'name': 'cli', 'steps': [{'name': 'result', 'task': {'type': 'behavior', 'behaviorType': '@fixture/fleet-cli',
                'behaviorName': '${{ "cli-" + string(inputs.node_id) }}', 'methodName': 'install',
                'inputs': {'node_id': '${{ inputs.node_id }}', 'version': '${{ inputs.intent.cli.release.version }}'}}}]}]}))
        for name in ('workflow-operation-fleet-rollout.yaml', 'workflow-operation-fleet-cli-converge.yaml', 'workflow-fleet-release-node.yaml'):
            import yaml
            workflow = yaml.safe_load((ROOT / 'workflows' / name).read_text())
            for job in workflow['jobs']:
                job.pop('labels', None)
                for step in job['steps']:
                    step.pop('target', None); step.pop('labels', None)
                    task = step['task']
                    if task.get('behaviorType') in ('@swamp/ssh', '@swamp/cfgmgmt/fetch'):
                        task['behaviorType'] = '@fixture/fleet-host'
                        task['globalArgs'] = {'nodeHost': '${{ steps.gate.outputs.ssh.target.host }}'}
                        task['inputs'] = {'argv': task['inputs']['argv']} if 'argv' in task['inputs'] else {}
            (self.repo / 'workflows' / name).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.state = {'bound': True, 'calls': [], 'effects': [], 'failure': None, 'uncertain': False,
                      'operation': {'id': 7, 'kind': 'fleet.rollout', 'node_id': 1, 'status': 'queued',
                                    'cancel_requested': False, 'result': None, 'input': {'nodes': [self.node(i) for i in (2, 3, 4)]}}}

    def node(self, i):
        return {'node_id': i, 'row_id': i, 'user': 'node', 'release': {'version': '0.100.0', 'url': 'https://example.com/cli', 'sha256': 'a' * 64},
                'profile': '{}', 'skip_reason': None, 'plan_error': None, 'daytime': True,
                'agent': {'url': 'https://example.com/agent', 'sha256': 'b' * 64, 'version': '0.5.0'},
                'ssh': {'control_node_id': 209, 'identity_file': '/etc/orbit/swamp-role-worker/ssh-identity',
                        'target': {'host': f'10.44.0.{i}', 'user': 'node', 'port': 22, 'host_keys': ['ssh-ed25519 AAAA']}}}

    def run_parent(self, kind='rollout', success=True):
        self.state['operation']['kind'] = 'fleet.' + kind.replace('-', '_')
        (self.repo / 'fleet-native.json').write_text(json.dumps(self.state))
        workflow = 'operation-fleet-' + kind
        self.cli('workflow', 'validate', workflow)
        result = self.cli('workflow', 'run', workflow, '--input', json.dumps({'operation_id': 7, 'node_id': 1, 'kind': self.state['operation']['kind']}), success=success)
        state = json.loads((self.repo / 'fleet-native.json').read_text())
        return state, result

    def test_every_step_runs_on_209_and_only_a_rollout_publishes_the_observer(self):
        import yaml
        steps = {step['name']: step for job in yaml.safe_load((ROOT / 'workflows/workflow-fleet-release-node.yaml').read_text())['jobs'] for step in job['steps']}
        for name in ('gate', 'cli', 'record'):
            self.assertEqual(steps[name]['labels'], {'role': 'swamp'}, name)
        for name in ('observer_fetch', 'observer_read', 'observer_restart', 'observer_check', 'observer'):
            self.assertEqual(steps[name]['labels'], {'role': 'swamp'}, name)
            self.assertTrue(steps[name]['guard'].startswith('${{ inputs.kind != "fleet.rollout" ||'), name)
        self.assertEqual(steps['observer_fetch']['task']['behaviorType'], '@swamp/cfgmgmt/fetch')

    def test_ordered_rollout_in_one_native_run(self):
        state, _ = self.run_parent()
        self.assertEqual(state['effects'], ['cli:2', 'observer:2', 'cli:3', 'observer:3', 'cli:4', 'observer:4'])
        self.assertEqual(state['operation']['status'], 'succeeded')
        self.assertEqual(state['operation']['result']['rollout_status'], 'completed')

    def test_cli_parent_never_publishes_observer(self):
        state, _ = self.run_parent('cli-converge')
        self.assertEqual(state['effects'], ['cli:2', 'cli:3', 'cli:4'])
        self.assertEqual(state['operation']['status'], 'succeeded')

    def test_refusal_stops_later_nodes(self):
        self.state['failure'] = 3
        state, _ = self.run_parent(success=False)
        self.assertEqual(state['effects'], ['cli:2', 'observer:2', 'cli:3'])
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result']['halted_node_id'], 3)
        self.assertEqual(state['operation']['result']['nodes'][1]['error_code'], 'cli.install_failed')
        self.assertEqual(state['operation']['result']['nodes'][2]['outcome'], 'pending')

    def test_failed_observer_fetch_stops_later_nodes(self):
        self.state['observer_failure'] = 3
        state, _ = self.run_parent(success=False)
        self.assertEqual(state['effects'], ['cli:2', 'observer:2', 'cli:3', 'observer:3'])
        self.assertEqual(state['operation']['status'], 'failed')
        self.assertEqual(state['operation']['result']['nodes'][1]['error_code'], 'agent.download_failed')
        self.assertEqual(state['operation']['result']['nodes'][2]['outcome'], 'pending')

    def test_a_pinned_running_agent_restarts_only_when_the_binary_changed(self):
        state, _ = self.run_parent()
        self.assertEqual(state['restarts'], [2, 3, 4])
        self.assertEqual(state['operation']['result']['nodes'][0]['agent_version'], '0.5.0')

    def test_an_unchanged_pinned_agent_is_not_restarted(self):
        self.state['unchanged'] = True
        state, _ = self.run_parent()
        self.assertNotIn('restarts', state)
        self.assertEqual(state['operation']['result']['nodes'][0]['outcome'], 'converged')

    def test_an_agent_still_running_another_binary_fails_its_check(self):
        self.state['running'] = {'3': 'c' * 64}
        state, _ = self.run_parent(success=False)
        self.assertEqual(state['operation']['result']['nodes'][1]['error_code'], 'agent.observer_unhealthy')

    def test_closed_window_defers_shared_nodes_without_effects(self):
        for node in self.state['operation']['input']['nodes']:
            node['daytime'] = False
        # 02:30Z is 03:30 Amsterdam; use a summer boundary by pinning the fixture's clock.
        module = self.repo / 'extensions/behaviors/operations/fleet-recipe/fleet_release.ts'
        module.write_text(module.read_text().replace('2026-10-31T02:30:00Z', '2026-10-31T12:30:00Z'))
        state, _ = self.run_parent()
        self.assertEqual(state['effects'], [])
        self.assertEqual(state['operation']['result']['rollout_status'], 'running')


if __name__ == '__main__':
    unittest.main()
