"""Actual native image DAG and official SSH from 209, with disposable SSH/SCP process boundaries. No VM or fleet mutation."""
import hashlib
import json
from pathlib import Path
import shutil
import sys
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

PIN = 'a' * 64
IDENTITY = 'sha256:' + 'b' * 64
FINGERPRINT = 'c' * 64
BOOT = '878a384f-8b8a-4066-bd17-dcad8eac3e86'

# The fake host answers each argv the way the Incus host and its guests would. Stdin is recorded as parsed JSON.
FAKE_SSH = r'''
import json, os, shlex, sys
from pathlib import Path
argv = shlex.split(sys.argv[-1])
body = sys.stdin.read()
state_path = Path(STATE)
state = json.loads(state_path.read_text())
call = {'argv': argv}
if body:
    call['stdin'] = json.loads(body)
state['calls'].append(call)
state_path.write_text(json.dumps(state))
def reply(data):
    print(json.dumps({'data': data, 'ok': True}, sort_keys=True))
if 'command' in argv and argv[argv.index('command') - 1].split('/')[-1].startswith('orbit-agent'):
    name = argv[argv.index('command') + 1]
    request = call['stdin']['input']
    if name == os.environ.get('FIXTURE_REFUSE'):
        print(json.dumps({'error': {'code': 'agent.version_mismatch', 'message': 'refused'}, 'ok': False}, sort_keys=True))
    elif name == 'incus.image' and request['operation']['kind'] == 'publish':
        reply({'ready': True, 'fingerprint': FINGERPRINT})
    elif name == 'incus.volume':
        reply({'name': 'orbit-topology-seed-' + FINGERPRINT[:12]})
    elif name == 'agent.install':
        reply({'sha256': request['sha256'], 'replaced': True})
    elif name == 'image.inspect-phase':
        reply({'boot_id': BOOT, 'unit': 'u', 'state': 'missing' if request['boot_id'] is None else 'complete'})
    elif name == 'image.start-phase':
        reply({'boot_id': request['boot_id'], 'unit': 'u', 'state': os.environ.get('FIXTURE_START', 'running')})
    else:
        reply({'ready': True})
elif '--property=MainPID' in argv:
    print('0' if os.environ.get('FIXTURE_START') == 'complete' else '4242')
elif 'is-active' in argv:
    print('active')
'''


class ImageBuildNative(NativeTestCase):
    native_timeout = 900

    def setUp(self):
        super().setUp()
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        module = self.repo / 'extensions/behaviors/operations/task-vms'
        module.mkdir(parents=True)
        for name in ('image_build_inputs.ts', 'images.ts'):
            shutil.copy(ROOT / 'extensions/behaviors/operations/task-vms' / name, module)
        self.state = self.repo / 'image-host.json'
        self.state.write_text(json.dumps({'calls': []}))
        fake = self.repo / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE_SSH.replace('STATE', repr(str(self.state)))
                        .replace('FINGERPRINT', repr(FINGERPRINT)).replace('BOOT', repr(BOOT)))
        fake.chmod(0o755)
        self.copies = self.repo / 'copies.jsonl'
        scp = self.repo / 'scp'
        # The fetched build lands where the copy step names it, so the digest check on 209 reads real bytes.
        scp.write_text('#!' + sys.executable + '\nimport os,sys,json\nfrom pathlib import Path\n'
                       'Path(' + repr(str(self.copies)) + ').open("a").write(json.dumps(sys.argv[1:])+"\\n")\n'
                       'dst=sys.argv[-1]\nif ":" not in dst:Path(dst).write_bytes(os.environ["FIXTURE_BUILD"].encode())\n')
        scp.chmod(0o755)
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-task-vm-image-build.yaml').read_text())
        self.source = source
        self.assertEqual([job['name'] for job in source['jobs']], ['admission', 'build', 'cleanup'])
        for job in source['jobs']:
            self.assertNotIn('labels', job)
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'}, step['name'])
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs']['sshBinary'] = str(fake)
                    task['globalArgs']['scpBinary'] = str(scp)
        (self.repo / 'workflows/workflow-operation-task-vm-image-build.yaml').write_text(yaml.safe_dump(source, sort_keys=False))
        self.build = 'pinned pi build'
        self.native_env.update(FIXTURE_BUILD=self.build)
        ssh = {'control_node_id': 209, 'identity_file': '/fixture/key', 'public_key': 'ssh-ed25519 PUBLIC',
               'target': {'name': 'target', 'host': '192.0.2.13', 'user': 'orbit', 'port': 22,
                          'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
        self.intent = {
            'build_id': 7, 'attempt': 1, 'host_node_id': 13, 'agent_sha256': PIN, 'identity': IDENTITY,
            'generation': 1, 'project': 'orbit-tasks', 'name': 'tvm-image-7', 'cidr': '10.251.77.0/24',
            'pool': 'orbit-tasks', 'network': 'orbittask0', 'cpus': 2, 'memory': '4GiB', 'disk': '45GiB',
            'source': 'ubuntu-26.04-vm', 'candidate_alias': 'candidate-7', 'base_alias': 'orbit-task-base',
            'gateway_public_key': 'public-key', 'certificate': 'public-root', 'ssh_execution': ssh,
            'pi_source': {**ssh, 'target': {**ssh['target'], 'host': '192.0.2.14'}},
            'recipe': json.dumps({'identity': IDENTITY, 'agent_sha256': PIN, 'pi_sha256': None}),
            'pi_artifact': None,
            'prune': {'project': 'orbit-tasks', 'operation': {'kind': 'prune', 'pool': 'orbit-tasks',
                      'obsolete_aliases': [], 'retired_aliases': [], 'protected_fingerprints': []}},
        }

    def with_pi(self):
        digest = hashlib.sha256(self.build.encode()).hexdigest()
        self.intent['recipe'] = json.dumps({'identity': IDENTITY, 'agent_sha256': PIN, 'pi_sha256': digest})
        self.intent['pi_artifact'] = {'path': '/srv/orbit/sandbox-artifacts/pi', 'sha256': digest}
        return digest

    def run_build(self, success=True):
        self.cli('workflow', 'validate', 'operation-task-vm-image-build')
        run = json.loads(self.cli('workflow', 'run', 'operation-task-vm-image-build', '--input', json.dumps({
            'operation_id': 7, 'node_id': 13, 'kind': 'task_vm.image_build',
            'intent': self.intent, 'execution_secrets': {}}), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        calls = json.loads(self.state.read_text())['calls']
        return run, steps, calls

    def agent_calls(self, calls):
        return [(call['argv'][call['argv'].index('command') + 1], call) for call in calls if 'command' in call['argv']]

    def test_build_runs_every_stage_from_209_over_pinned_ssh(self):
        run, steps, calls = self.run_build()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['complete']['status'], 'succeeded')
        self.assertEqual(steps['publish-reply']['outputs']['fingerprint'], FINGERPRINT)
        names = [name for name, _call in self.agent_calls(calls)]
        self.assertEqual(names, [
            'incus.vm', 'incus.vm', 'incus.vm', 'agent.install', 'guest.cloud-init', 'image.inspect-phase',
            *['image.start-phase', 'image.inspect-phase'] * 6,
            'image.owner', 'incus.image', 'image.boot', 'image.recipe-marker', 'image.trust-ca', 'image.clean',
            'incus.image', 'incus.image', 'incus.vm', 'incus.vm', 'agent.install', 'guest.cloud-init',
            'image.check', 'image.verify', 'incus.build-topology-seed', 'incus.vm', 'incus.volume',
            'incus.image', 'incus.image', 'incus.vm', 'incus.vm'])
        for name, call in self.agent_calls(calls):
            # Every call carries the admitted pin and a literal outer bound, and the agent never runs through a shell.
            self.assertEqual(call['argv'][0], 'timeout', name)
            self.assertEqual(call['stdin']['agent_sha256'], PIN, name)
            self.assertNotIn('sh', [part.split('/')[-1] for part in call['argv']])
        host = [call for name, call in self.agent_calls(calls) if name.startswith('incus.')]
        self.assertEqual(host[0]['argv'], ['timeout', '930', '/usr/bin/sudo', '-n', '--', '/usr/local/bin/orbit-agent', 'command', 'incus.vm'])
        self.assertEqual(host[0]['stdin']['input'], {'vm': {'project': 'orbit-tasks', 'name': 'tvm-image-7', 'cidr': '10.251.77.0/24'},
                                                     'operation': {'kind': 'remove_instance'}})
        owner = next(call for name, call in self.agent_calls(calls) if name == 'image.owner')
        self.assertEqual(owner['argv'][:20], ['timeout', '150', '/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', 'orbit-tasks',
                                              'exec', '--mode=non-interactive', '--user', '1000', '--group', '1000',
                                              '--env', 'SUDO_USER=orbit', '--', 'tvm-image-7', '/usr/local/bin/orbit-agent', 'command'])
        install = [call for name, call in self.agent_calls(calls) if name == 'agent.install']
        candidate = '/var/tmp/orbit-agent-candidate-' + PIN
        self.assertEqual([call['argv'][17:19] for call in install], [['tvm-image-7', candidate], ['tvm-image-7-smoke', candidate]])
        self.assertEqual(install[0]['stdin']['input'], {'candidate': candidate, 'sha256': PIN})
        pushes = [call['argv'] for call in calls if 'push' in call['argv']]
        self.assertEqual(pushes, [
            ['timeout', '210', '/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', 'orbit-tasks', 'file', 'push',
             '--mode', '0700', '--uid', '0', '--gid', '0', '--', '/usr/local/bin/orbit-agent', 'tvm-image-7' + candidate],
            ['timeout', '210', '/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', 'orbit-tasks', 'file', 'push',
             '--mode', '0700', '--uid', '0', '--gid', '0', '--', '/usr/local/bin/orbit-agent', 'tvm-image-7-smoke' + candidate]])
        waits = [call['argv'] for call in calls if 'wait' in call['argv']]
        self.assertEqual(waits[0][5:], ['/usr/bin/incus', '--project', 'orbit-tasks', 'wait', '--timeout', '600', '--', 'tvm-image-7', 'agent'])
        # Each phase waits once on its retained unit's main process, then reads its outcome once.
        tails = [call['argv'] for call in calls if '/usr/bin/tail' in call['argv']]
        self.assertEqual(len(tails), 6)
        self.assertEqual(tails[0][:2] + tails[0][-6:], ['timeout', '1530', '/usr/bin/timeout', '1500', '/usr/bin/tail', '--pid=4242', '-f', '/dev/null'])
        starts = [call['stdin']['input'] for name, call in self.agent_calls(calls) if name == 'image.start-phase']
        self.assertEqual([start['key']['phase'] for start in starts], ['apt-source', 'packages', 'javascript', 'rust', 'agent', 'pi'])
        self.assertTrue(all(start['boot_id'] == BOOT and start['key']['execution'] == '7-1' for start in starts))
        units = [call['argv'][-1] for call in calls if '--property=MainPID' in call['argv']]
        self.assertEqual(len(set(units)), 6)
        self.assertTrue(all(unit.startswith('orbit-recipe-image-') and unit.endswith('.service') for unit in units))
        published = [call['stdin']['input']['operation'] for name, call in self.agent_calls(calls) if name == 'incus.image']
        self.assertEqual([op['kind'] for op in published], ['prepare_metadata', 'publish', 'promote', 'promote', 'prune'])
        self.assertEqual(published[2], {'kind': 'promote', 'alias': 'candidate-7', 'fingerprint': FINGERPRINT})
        self.assertEqual(published[3], {'kind': 'promote', 'alias': 'orbit-task-base', 'fingerprint': FINGERPRINT})
        for name in ('pi-fetch', 'pi-check', 'pi-copy', 'pi-directory', 'pi-push', 'cleanup-pi-host', 'cleanup-pi-local'):
            self.assertEqual(steps[name]['status'], 'skipped', name)
        self.assertFalse(self.copies.exists())
        self.assertEqual(steps['cleanup-smoke-reply']['status'], 'succeeded')

    def test_pi_build_reaches_its_pinned_guest_cache_through_the_host(self):
        digest = self.with_pi()
        run, steps, calls = self.run_build()
        self.assertEqual(run['status'], 'succeeded')
        stage = '/tmp/orbit-image-pi-' + run['id'] + '.bin'
        copies = [json.loads(line) for line in self.copies.read_text().splitlines()]
        self.assertEqual(len(copies), 2)
        self.assertTrue(copies[0][-2].endswith(':/srv/orbit/sandbox-artifacts/pi') and copies[0][-1] == stage)
        self.assertTrue(copies[1][-2] == stage and copies[1][-1].endswith(':' + stage))
        self.assertIn('192.0.2.14', copies[0][-2])
        push = next(call['argv'] for call in calls if 'push' in call['argv'] and '0600' in call['argv'])
        self.assertEqual(push[-2:], [stage, 'tvm-image-7/var/cache/orbit/pi/' + digest + '.bin'])
        directory = next(call['argv'] for call in calls if '/usr/bin/install' in call['argv'])
        self.assertEqual(directory[-9:], ['/usr/bin/install', '-d', '-m', '0700', '-o', 'root', '-g', 'root', '/var/cache/orbit/pi'])
        self.assertEqual(steps['cleanup-pi-host']['status'], 'succeeded')
        self.assertIn(['timeout', '30', '/usr/bin/rm', '-f', '--', stage], [call['argv'] for call in calls])
        self.assertFalse(Path(stage).exists())
        # No base64 chunk upload: the pi phase verifies the pinned file itself.
        self.assertNotIn('pi.receive-artifact', [name for name, _call in self.agent_calls(calls)])

    def test_a_pi_build_with_another_digest_never_leaves_209(self):
        self.with_pi()
        self.native_env['FIXTURE_BUILD'] = 'tampered build'
        run, steps, calls = self.run_build(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['pi-check']['status'], 'failed')
        self.assertEqual(len(self.copies.read_text().splitlines()), 1)
        phases = [call['stdin']['input']['key']['phase'] for name, call in self.agent_calls(calls) if name == 'image.start-phase']
        self.assertNotIn('pi', phases)
        self.assertEqual(steps['cleanup-pi-local']['status'], 'succeeded')

    def test_an_already_complete_phase_is_read_without_a_wait(self):
        self.native_env['FIXTURE_START'] = 'complete'
        run, steps, calls = self.run_build()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['packages-wait']['status'], 'skipped')
        self.assertEqual(steps['packages-inspect-reply']['status'], 'succeeded')
        self.assertFalse(any('/usr/bin/tail' in call['argv'] for call in calls))

    def test_an_agent_refusal_stops_the_build_and_cleanup_removes_both_guests(self):
        self.native_env['FIXTURE_REFUSE'] = 'image.clean'
        run, steps, calls = self.run_build(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['clean-guest']['status'], 'succeeded')
        self.assertEqual(steps['clean-guest-reply']['status'], 'failed')
        self.assertIn('agent.version_mismatch', json.dumps(steps['clean-guest-reply']))
        self.assertEqual(steps['publish']['status'], 'skipped')
        self.assertEqual(steps['complete']['status'], 'skipped')
        names = [name for name, _call in self.agent_calls(calls)]
        self.assertEqual(names[-3:], ['image.clean', 'incus.vm', 'incus.vm'])
        removed = [call['stdin']['input']['vm']['name'] for name, call in self.agent_calls(calls)[-2:]]
        self.assertEqual(removed, ['tvm-image-7', 'tvm-image-7-smoke'])
        self.assertEqual(steps['cleanup-smoke-reply']['status'], 'succeeded')

    def test_an_invalid_intent_touches_no_host(self):
        self.intent['recipe'] = json.dumps({'identity': IDENTITY, 'agent_sha256': 'e' * 64, 'pi_sha256': None})
        run, steps, calls = self.run_build(success=False)
        self.assertEqual(steps['plan']['status'], 'failed')
        self.assertEqual(calls, [])
        self.assertEqual(steps['cleanup-builder']['status'], 'skipped')


if __name__ == '__main__':
    unittest.main()
