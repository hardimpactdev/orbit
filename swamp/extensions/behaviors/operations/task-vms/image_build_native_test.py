"""Native image DAG and bounded artifact-reference proof. No VM, sudo, token or fleet mutation."""
import hashlib
import json
from pathlib import Path
import sys
import unittest
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class ImageBuildNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        artifact_text = 'image-artifact-' + uuid.uuid4().hex
        self.native_env['ORBIT_TEST_PI_ARTIFACT'] = artifact_text
        self.secrets = (artifact_text,)
        self.copy_behaviors('automatic-deploy', 'operations/_lib', 'operations/task-vms', 'operations/node')
        module = self.repo / 'extensions/behaviors/operations/task-vms/image_build_direct.ts'
        module.write_text('import {fixtureHttp, fixtureHost, fixtureGuest, fixtureCleanup} from "./image_build_native_fixture.ts";\n' + module.read_text().replace('...imageBuildDirect()', '...imageBuildDirect(() => fixtureHttp, fixtureHost, fixtureGuest, fixtureCleanup)'))
        fake=self.repo/'ssh'
        fake.write_text('#!'+sys.executable+'\n'+"""import json,sys,shlex
from pathlib import Path
p=Path('image-build-fixture.json');s=json.loads(p.read_text());args=shlex.split(sys.argv[-1]);check='is-active' in args
s['guest'].append({'name':'ssh.check' if check else 'ssh.enable','input':{},'uid':'0'});p.write_text(json.dumps(s))
if check and s.get('failSsh'):raise SystemExit(1)
if check:print('active')
""")
        fake.chmod(0o755)
        self.ssh={'control_node_id':209,'identity_file':'/fixture/key','public_key':'ssh-ed25519 PUBLIC','target':{'name':'target','host':'192.0.2.13','user':'orbit','port':22,'host_keys':['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
        workflow = ROOT / 'workflows/workflow-operation-task-vm-image-build.yaml'
        source = yaml.safe_load(workflow.read_text())
        self.assertEqual(source['jobs'][1]['labels'], {'node': '${{ string(inputs.node_id) }}'})
        self.assertEqual([job['name'] for job in source['jobs']], ['admission', 'build', 'cleanup'])
        for job in source['jobs']:
            job.pop('target')
            for step in job['steps']:
                if step.get('task',{}).get('behaviorType')=='@swamp/ssh':
                    self.assertEqual(step.pop('labels'),{'role': 'swamp'})
                    step['task']['globalArgs']['sshBinary']=str(fake)
        (self.repo / 'workflows' / workflow.name).write_text(yaml.safe_dump(source, sort_keys=False))
        digest = hashlib.sha256(artifact_text.encode()).hexdigest()
        self.state = {'calls': [], 'actions': [], 'guest': [], 'artifactDelivered': False,
            'operation': {'id': 7, 'kind': 'task_vm.image_build', 'node_id': 13, 'status': 'queued',
                'cancel_requested': False, 'result': None, 'input': {
                    'current': True, 'build_id': 7, 'attempt': 1, 'host_node_id': 13,
                    'agent_sha256': 'a' * 64, 'identity': 'sha256:' + 'b' * 64, 'generation': 1,
                    'project': 'orbit-tasks', 'name': 'tvm-image-7', 'cidr': '10.251.77.0/24',
                    'pool': 'orbit-tasks', 'network': 'orbittask0', 'cpus': 2, 'memory': '4GiB', 'disk': '45GiB',
                    'source': 'ubuntu-26.04-vm', 'candidate_alias': 'candidate-7', 'base_alias': 'orbit-task-base',
                    'gateway_public_key': 'public-key', 'certificate': 'public-root','ssh_execution':self.ssh,
                    'recipe': {'identity': 'sha256:' + 'b' * 64, 'agent_sha256': 'a' * 64, 'pi_sha256': digest},
                    'pi_artifact': {'sha256': digest},
                    'prune': {'project': 'orbit-tasks', 'operation': {'kind': 'prune', 'pool': 'orbit-tasks',
                        'obsolete_aliases': [], 'retired_aliases': [], 'protected_fingerprints': []}},
                }}}
        self.state['operation']['input']['recipe'] = json.dumps(self.state['operation']['input']['recipe'])
        self.save()
        self.cli('extension','pull','@swamp/ssh@2026.10.23.1')

    def save(self):
        (self.repo / 'image-build-fixture.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'image-build-fixture.json').read_text())

    def run_workflow(self, success=True):
        result = self.cli('workflow', 'run', 'operation-task-vm-image-build', '--input', 'operation_id=7',
            '--input', 'node_id=13', '--input', 'kind=task_vm.image_build','--input','project=orbit-tasks','--input','name=tvm-image-7','--input','ssh_execution='+json.dumps(self.ssh), success=success)
        return json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']

    def test_full_dag_fetches_pinned_private_artifact_and_promotes_only_after_smoke_and_seed(self):
        self.run_workflow()
        saved = self.read()
        self.assertTrue(saved['artifactDelivered'])
        self.assertTrue(any(call['name']=='ssh.enable' for call in saved['guest']))
        self.assertTrue(any(call['name']=='ssh.check' for call in saved['guest']))
        self.assertEqual(saved['operation']['status'], 'succeeded')
        self.assertTrue(all(call['uid'] == '1000' for call in saved['guest'] if call['name'] == 'image.owner'))
        self.assertTrue(any(call['name'] == 'image.owner' for call in saved['guest']))
        self.assertTrue(all('/direct-operations/' in path for path in saved['calls']))
        self.assertEqual([call['input']['operation']['kind'] for call in saved['actions'][-3:]],
            ['finish_seed', 'promote', 'prune'])

    def test_uncertain_status_after_systemd_reexec_observes_same_phase_without_restart(self):
        self.state['transientInspect'] = True
        self.save()
        self.run_workflow()
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'succeeded')
        self.assertEqual(saved['inspectCount'], 3)
        packages = [call for call in saved['guest'] if call['input'].get('key', {}).get('phase') == 'packages']
        self.assertEqual(sum(call['name'] == 'image.start-phase' for call in packages), 1)
        self.assertFalse(any(call['name'] == 'image.restart-phase' for call in saved['guest']))

    def test_smoke_failure_never_promotes(self):
        self.state['failSmoke'] = True
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertEqual(saved['instances'], {})
        self.assertFalse(any(call['input'].get('operation', {}).get('kind') == 'promote'
            and call['input']['operation'].get('alias') == 'orbit-task-base' for call in saved['actions']))

    def test_lost_final_reply_resumes_same_root_without_repeating_actions(self):
        self.state['loseFinal'] = True
        self.save()
        run = self.run_workflow(success=False)
        actions = self.read()['actions']
        self.cli('workflow', 'resume', 'operation-task-vm-image-build', '--run', run)
        self.assertEqual(self.read()['actions'], actions)
        self.assertEqual(self.read()['operation']['status'], 'succeeded')

    def test_corrupt_download_never_reaches_guest_upload_or_promotion(self):
        self.state['corruptArtifact'] = True
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertEqual(saved['instances'], {})
        self.assertFalse(saved['artifactDelivered'])
        self.assertFalse(any(call['input'].get('operation', {}).get('kind') == 'promote'
            and call['input']['operation'].get('alias') == 'orbit-task-base' for call in saved['actions']))

    def test_cancellation_during_recipe_deletes_builder_before_projecting_cancelled(self):
        self.state['cancelDuringPhase'] = True
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'cancelled')
        self.assertEqual(saved['instances'], {})
        self.assertTrue(any(argv[5] == 'delete' for argv in saved['cleanup']))

    def test_superseded_recipe_still_cleans_its_builder(self):
        self.state['supersedeDuringPhase'] = True
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertEqual(saved['operation']['result']['error_code'], 'task_vm.image_recipe_superseded')
        self.assertEqual(saved['instances'], {})

    def test_cancellation_before_admission_still_cleans_admitted_orphans(self):
        self.state['operation']['cancel_requested'] = True
        self.state['instances'] = {'tvm-image-7': 'Running', 'tvm-image-7-smoke': 'Stopped'}
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'cancelled')
        self.assertEqual(saved['instances'], {})
        self.assertEqual(saved['actions'], [])

    def test_cleanup_failure_overrides_cancellation(self):
        self.state['operation']['cancel_requested'] = True
        self.state['instances'] = {'tvm-image-7': 'Stopped'}
        self.state['failCleanup'] = True
        self.save()
        self.run_workflow(success=False)
        saved = self.read()
        self.assertEqual(saved['operation']['status'], 'failed')
        self.assertEqual(saved['operation']['result']['error_code'], 'task_vm.image_cleanup_failed')
        self.assertIn('tvm-image-7', saved['instances'])

    def test_inactive_sshd_blocks_image_promotion(self):
        self.state['failSsh']=True
        self.save()
        self.run_workflow(success=False)
        saved=self.read()
        self.assertNotIn('finish_seed',[call['input']['operation']['kind'] for call in saved['actions']])
        self.assertFalse(any(call['input']['operation'].get('alias')=='orbit-task-base' and call['input']['operation']['kind']=='promote' for call in saved['actions']))


if __name__ == '__main__':
    unittest.main()
