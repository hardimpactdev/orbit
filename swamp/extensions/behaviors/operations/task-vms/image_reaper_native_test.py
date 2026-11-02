"""Real native image reaper DAG with the official SSH package on published inputs; isolated SSH binary, no host effects."""
import datetime
import json
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT'
INCUS = ['/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', 'orbit-tasks']
# Each host address answers its Incus argv from the fixture state, like the real host would.
FAKE_SSH = r'''
import json, shlex, sys
from pathlib import Path
path = Path(STATE)
state = json.loads(path.read_text())
address = next(a.split('@', 1)[1] for a in sys.argv[1:] if a.startswith('orbit@'))
argv = shlex.split(sys.argv[-1])
state['calls'].append([address, argv])
host = state['hosts'][address]
if argv[:2] == ['timeout', '150']:
    argv = argv[2:]
assert argv[:6] == INCUS, argv
argv = argv[6:]
code = 0
if argv == ['list', '--format', 'json']:
    print(json.dumps(list(host['vms'].values())))
elif argv[:3] == ['delete', '--force', '--']:
    host['vms'].pop(argv[3])
elif argv == ['storage', 'volume', 'list', 'orbit-tasks', '--format', 'json']:
    print(json.dumps([{'type': 'virtual-machine', 'name': n} for n in [*host['vms'], *host.get('leftover', [])]]))
else:
    code = 2
path.write_text(json.dumps(state))
raise SystemExit(code)
'''


class ImageReaperNative(NativeTestCase):
    native_timeout = 300

    def setUp(self):
        super().setUp()
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        module = self.repo / 'extensions/behaviors/operations/task-vms'
        module.mkdir(parents=True)
        (module / 'image_reaper.ts').write_text((ROOT / 'extensions/behaviors/operations/task-vms/image_reaper.ts').read_text())
        self.key = self.repo / 'key'
        self.key.write_text('synthetic fixture identity, never used by SSH')
        self.key.chmod(0o600)
        self.state = self.repo / 'incus.json'
        fake = self.repo / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE_SSH.replace('STATE', repr(str(self.state))).replace('INCUS', repr(INCUS)))
        fake.chmod(0o755)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-task-vm-image-reaper.yaml').read_text())
        self.assertEqual(workflow['trigger']['schedule'], '0 */30 * * * *')
        self.assertEqual((workflow['concurrency'], workflow['queueTimeout']), (1, 30))
        for job in workflow['jobs']:
            for step in job['steps']:
                # Only fixture placement and the official package's own binary seam differ.
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                self.assertNotIn('target', step)
                if step['task']['behaviorType'] == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fake)
        (self.repo / 'workflows/workflow-task-vm-image-reaper.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))

    @staticmethod
    def vm(name, hours=3, pool='orbit-tasks', status='Stopped'):
        created = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(hours=hours)
        return {'name': name, 'type': 'virtual-machine', 'status': status, 'created_at': created.isoformat(),
                'expanded_devices': {'root': {'type': 'disk', 'path': '/', 'pool': pool}}}

    def host(self, node_id, protected=(), owned=()):
        """The Gateway's published shape: host settings, protected build ids and the pinned SSH target."""
        return {'node_id': node_id, 'project': 'orbit-tasks', 'pool': 'orbit-tasks', 'operator_owned': list(owned),
                'protected_builds': list(protected), 'name': 'target', 'host': f'10.44.0.{node_id}', 'user': 'orbit',
                'port': 22, 'host_keys': [KEY], 'identity_file': str(self.key)}

    def run_reaper(self, hosts, incus, success=True):
        self.state.write_text(json.dumps({'calls': [], 'hosts': incus}))
        path = self.repo / 'inputs.json'
        path.write_text(json.dumps({'revision': 4, 'hosts': hosts}))
        self.cli('workflow', 'validate', 'task-vm-image-reaper')
        result = json.loads(self.cli('workflow', 'run', 'task-vm-image-reaper', '--input-file', str(path), success=success).stdout)
        state = json.loads(self.state.read_text())
        sweeps = []
        for raw in (self.repo / '.swamp/data').rglob('raw'):
            try:
                value = json.loads(raw.read_text())
            except (ValueError, UnicodeError):
                continue
            if isinstance(value, dict) and 'unlisted' in value:
                sweeps.append(value)
        self.assertEqual(len(sweeps), 1)
        return result, state, sweeps[0]

    def test_old_unreferenced_builders_are_deleted_and_every_other_vm_is_kept(self):
        incus = {'10.44.0.9': {'vms': {v['name']: v for v in [
            self.vm('tvm-image-7', status='Running'),       # deleted
            self.vm('tvm-image-8-attempt-2-smoke'),         # protected: build 8 is active
            self.vm('tvm-image-10', hours=1),               # younger than two hours
            self.vm('tvm-image-11-builder'),                # operator-owned
            self.vm('tvm-image-12', pool='foreign-pool'),   # root on another pool
            self.vm('tvm-175'),                             # an ordinary task VM
        ]}}, '10.44.0.13': {'vms': {'tvm-image-20': self.vm('tvm-image-20')}}}
        hosts = [self.host(9, protected=[8], owned=['orbit-tasks/tvm-image-11-builder']), self.host(13)]
        result, state, sweep = self.run_reaper(hosts, incus)
        self.assertEqual(result['status'], 'succeeded')
        deletes = [(address, argv[-1]) for address, argv in state['calls'] if 'delete' in argv]
        self.assertEqual(deletes, [('10.44.0.9', 'tvm-image-7'), ('10.44.0.13', 'tvm-image-20')])
        self.assertEqual(sorted(state['hosts']['10.44.0.9']['vms']),
                         ['tvm-175', 'tvm-image-10', 'tvm-image-11-builder', 'tvm-image-12', 'tvm-image-8-attempt-2-smoke'])
        self.assertEqual(sweep, {'hosts': [{'node_id': 9, 'deleted': ['tvm-image-7'], 'failed': []},
                                           {'node_id': 13, 'deleted': ['tvm-image-20'], 'failed': []}], 'unlisted': []})

    def test_a_remaining_volume_or_unreachable_host_fails_the_run_and_spares_other_hosts(self):
        incus = {'10.44.0.9': {'vms': {'tvm-image-7': self.vm('tvm-image-7')}, 'leftover': ['tvm-image-7/snapshot0']},
                 '10.44.0.13': {'vms': {'tvm-image-20': self.vm('tvm-image-20')}}}
        hosts = [self.host(9), self.host(13), self.host(14)]  # 10.44.0.14 does not answer
        _, state, sweep = self.run_reaper(hosts, incus, success=False)
        self.assertEqual(sweep, {'hosts': [{'node_id': 9, 'deleted': [], 'failed': ['tvm-image-7']},
                                           {'node_id': 13, 'deleted': ['tvm-image-20'], 'failed': []}], 'unlisted': [14]})
        self.assertEqual(state['hosts']['10.44.0.13']['vms'], {})


if __name__ == '__main__':
    unittest.main()
