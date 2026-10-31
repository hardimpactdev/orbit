"""Enrollment compensation boundaries through official SSH and native fan-out.

The SSH binary seam returns the supplier's public action outputs. Supplier tests
cover host effects; these tests cover the owning parent's ordering and recovery.
"""
import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class EnrollmentNetworkNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-peer-command', 'operations/node-hub-command')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.calls = self.repo / 'calls.jsonl'
        self.failure = self.repo / 'failure'
        identity = self.repo / 'identity'
        identity.write_text('synthetic unused fixture identity')
        self.ssh = self.repo / 'ssh-fixture'
        self.ssh.write_text('#!' + sys.executable + '\n' + '''
import ast,json,re,shlex,sys
from pathlib import Path
body=sys.stdin.read()
command=sys.argv[-1]
if body:
    tree=ast.parse(body)
    fields={node.targets[0].id:ast.literal_eval(node.value) for node in tree.body
            if isinstance(node,ast.Assign) and isinstance(node.targets[0],ast.Name)
            and node.targets[0].id in ('ACTION_JSON','HUB_ACTION_JSON')}
    if 'HUB_ACTION_JSON' in fields:
        data=json.loads(fields['HUB_ACTION_JSON'])
        action='hub_restore' if data['restoring'] else 'hub_publish'
        result={'schema_version':1,'hub_id':1,'context_node_id':2,'projected':True,
                'restored':data['restoring'],'changed':True}
    else:
        data=json.loads(fields['ACTION_JSON'])
        action=re.findall(r'^finish\\((\\w+)\\)',body,re.M)[-1]
        result={'snapshot':{'directory':'/run/orbit-peer-backup-fixture','present':['orbit.conf'],
                            'active':True,'enabled':'enabled','resolver':{},'recorded_resolver':None},
                'prepare':{'public_key':'A'*43+'='},'render':{'published':True},
                'record':{'dns_recorded':True},'restore':{'restored':True},'cleanup':{'cleaned':True}}[action]
else:
    data={}
    argv=shlex.split(command)
    action='restart' if 'restart' in argv else 'late' if '/usr/bin/false' in argv else 'exec'
with Path(CALLS).open('a') as stream: stream.write(json.dumps({'action':action,'input':data})+'\\n')
failure=Path(FAILURE)
if failure.exists() and failure.read_text()==action:
    failure.unlink()
    raise SystemExit(1)
if action=='late': raise SystemExit(1)
if body: print(json.dumps(result))
'''.replace('CALLS', repr(str(self.calls))).replace('FAILURE', repr(str(self.failure))))
        self.ssh.chmod(0o700)
        self.identity = identity
        public = 'A' * 43 + '='
        peer = {'node_id': 2, 'name': 'old', 'address': '10.44.0.2', 'public_key': public}
        self.input = {'intent': {'node_id': 2, 'executor_node_id': 14, 'mode': 'linux',
            'bootstrap': {'managed_user': 'orbit', 'target': {'user': 'root', 'host': 'dev.invalid', 'port': 22}},
            'network': {'existing_key': public, 'peer': {'server_public_key': 'H' * 43 + '=',
                'endpoint': '192.0.2.1:51820', 'address': '10.44.0.3/24', 'subnet': '10.44.0.0/24',
                'dns_server': '10.44.0.1', 'domains': ['orbit'], 'operator_dns': None, 'retain': True}},
            'hub': {'schema_version': 1, 'hub_id': 1, 'hub_address': '10.44.0.1/24', 'port': 51820,
                'context_node_id': 2, 'inventory': [peer], 'before': peer,
                'desired': {**peer, 'name': 'candidate', 'address': '10.44.0.3', 'public_key': None}}},
            'observation': {'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}

    def run_parent(self, *, late_failure=False, failure=None):
        if failure:
            self.failure.write_text(failure)
        self.calls.unlink(missing_ok=True)
        source = (ROOT / 'extensions/behaviors/operations/node-bootstrap/enrollment_network_steps.ts').as_uri()
        program = ('import {enrollmentNetworkForwardSteps as forward,enrollmentNetworkRestoreSteps as restore} from ' +
                   json.dumps(source) + ';console.log(JSON.stringify([...forward("network","inputs.intent",' +
                   '"inputs.observation","begin"),...restore("network","inputs.intent","inputs.observation",' +
                   '"parent_settled",\'("parent_settled" in steps && steps.parent_settled.status == "succeeded")\')]));')
        deno = os.environ.get('DENO_BIN', shutil.which('deno') or '/tmp/lane4-deno-2.9.8/deno')
        generated = json.loads(subprocess.run([deno, 'eval', program], check=True,
                                             capture_output=True, text=True).stdout)
        globals_ = {'name': 'fixture', 'sshBinary': str(self.ssh), 'transport': {
            'kind': 'ssh', 'user': 'orbit', 'identityFile': str(self.identity),
            'hostKeys': self.input['observation']['host_keys']},
            'hosts': [{'name': 'peer', 'address': 'dev.invalid'}]}
        def ssh_step(name, dependency, argv):
            return {'name': name, **({'dependsOn': dependency} if dependency else {}), 'task': {
                'type': 'behavior', 'behaviorType': '@swamp/ssh', 'behaviorName': 'fixture',
                'methodName': 'exec', 'globalArgs': globals_, 'inputs': {'hosts': 'name:peer', 'argv': argv}}}
        settled = ssh_step('parent_settled', [{'step': 'network_verify', 'condition': {'type': 'succeeded'}}],
                           ['/usr/bin/false' if late_failure else '/usr/bin/true'])
        # The parent boundary always settles, even when a network step fails.
        settled['dependsOn'][0]['condition']['type'] = 'always'
        if not late_failure:
            settled['guard'] = '${{ !("network_verify-0" in steps && steps["network_verify-0"].status == "succeeded") }}'
        for step in generated:
            step.pop('target')
            step.pop('labels')
            task = step['task']
            if task['behaviorType'] == '@swamp/ssh':
                current = task['globalArgs']
                current['sshBinary'] = str(self.ssh)
                if isinstance(current['transport'], dict):
                    current['transport'].pop('identityContent')
                    current['transport']['identityFile'] = str(self.identity)
                else:
                    current['transport'] = globals_['transport']
                    current['hosts'] = [{'name': 'hub', 'address': 'hub.invalid'}]
        forward = generated[:next(i for i, step in enumerate(generated) if step['name'] == 'network_restore_peer_asset')]
        restore = generated[len(forward):]
        workflow = {'id': 'be44723a-6d4d-4e63-a462-b4a0d80f7d57', 'name': 'enrollment-network-fixture',
            'inputs': {'type': 'object', 'properties': {'intent': {'type': 'object'}, 'observation': {'type': 'object'}}},
            'jobs': [{'name': 'enrollment', 'steps': [ssh_step('begin', None, ['/usr/bin/true']),
                                                   *forward, settled, *restore]}]}
        (self.repo / 'workflows/workflow-enrollment-network-fixture.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('workflow', 'validate', 'enrollment-network-fixture')
        run = json.loads(self.cli('workflow', 'run', 'enrollment-network-fixture', '--input', json.dumps(self.input),
                                   success=not late_failure and not failure).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        return history, [json.loads(row) for row in self.calls.read_text().splitlines()]

    def test_success_keeps_the_candidate_and_cleans_the_snapshot(self):
        history, calls = self.run_parent()
        self.assertEqual(history['status'], 'succeeded')
        actions = [row['action'] for row in calls]
        self.assertEqual(actions.count('cleanup'), 1)
        self.assertNotIn('restore', actions)
        publication = next(row['input'] for row in calls if row['action'] == 'hub_publish')
        self.assertEqual(publication['after']['name'], 'candidate')
        self.assertEqual(publication['after']['public_key'], 'A' * 43 + '=')

    def test_network_failure_restores_peer_and_prior_hub_before_cleanup(self):
        history, calls = self.run_parent(failure='restart')
        self.assertEqual(history['status'], 'failed')
        actions = [row['action'] for row in calls]
        self.assertLess(actions.index('restore'), actions.index('hub_restore'))
        self.assertLess(actions.index('hub_restore'), actions.index('cleanup'))
        restoration = next(row['input'] for row in calls if row['action'] == 'hub_restore')
        self.assertEqual(restoration['after']['name'], 'old')
        self.assertEqual(restoration['after']['address'], '10.44.0.2')

    def test_later_parent_failure_still_restores_an_already_verified_network(self):
        history, calls = self.run_parent(late_failure=True)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.assertEqual(steps['network_verify-0']['status'], 'succeeded')
        self.assertEqual(steps['parent_settled']['status'], 'failed')
        actions = [row['action'] for row in calls]
        self.assertLess(actions.index('late'), actions.index('restore'))
        self.assertLess(actions.index('hub_restore'), actions.index('cleanup'))

    def test_failed_restoration_keeps_the_snapshot_for_native_recovery(self):
        history, calls = self.run_parent(late_failure=True, failure='restore')
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.assertEqual(steps['network_restore_peer_command-0']['status'], 'failed')
        self.assertEqual(steps['network_restore_peer-0']['status'], 'skipped')
        actions = [row['action'] for row in calls]
        self.assertIn('hub_restore', actions)
        self.assertNotIn('cleanup', actions)
