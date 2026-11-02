"""Real native proof-reaper DAG with the official SSH package on published inputs; isolated SSH binary, no fleet effects."""
import json
import shlex
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

NOW = 1_800_000_000
HEARTBEAT = ['stat', '-c', '%Y', '/home/orbit/swamp-proof-activity']
# Each running VM's address answers `stat` like the real guest: exit, stdout.
ANSWERS = {'10.44.0.10': (0, NOW - 60), '10.44.0.20': (0, NOW - 4000), '10.44.0.30': (1, ''), '10.44.0.40': (255, '')}
KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT'


class ProofReaperNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/proof-reaper')
        module = self.repo / 'extensions/behaviors/operations/proof-reaper/reaper.ts'
        module.write_text(module.read_text().replace('...proofReaper()', f'...proofReaper(() => {NOW})'))
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        fixture = self.repo / 'fixture'
        fixture.mkdir()
        self.key = fixture / 'key'
        self.key.write_text('synthetic fixture identity, never used by SSH')
        self.key.chmod(0o600)
        self.calls = fixture / 'ssh-calls.jsonl'
        fake = fixture / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import json,sys
args=sys.argv[1:]
with open(CALLS,'a') as f:
    f.write(json.dumps(args)+'\\n')
for address,(code,out) in ANSWERS.items():
    if any(a.endswith('@'+address) for a in args):
        print(out)
        raise SystemExit(code)
raise SystemExit(255)
'''.replace('CALLS', repr(str(self.calls))).replace('ANSWERS', repr(ANSWERS)))
        fake.chmod(0o755)
        production = yaml.safe_load((ROOT / 'workflows/workflow-proof-reaper.yaml').read_text())
        self.assertEqual(production['trigger']['schedule'], '0 7-59/10 * * * *')
        self.assertEqual((production['concurrency'], production['queueTimeout']), (1, 300))
        self.assertEqual(production.pop('labels'), {'role': 'swamp'})
        for job in production['jobs']:
            for step in job['steps']:
                # Only fixture placement and the official package's own binary seam differ.
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
                if step['task']['behaviorType'] == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fake)
        (self.repo / 'workflows/workflow-proof-reaper.yaml').write_text(yaml.safe_dump(production, sort_keys=False))

    def inputs(self, groups):
        """The Gateway's published shape: groups, and one pinned SSH target per running VM."""
        targets = [{'group_id': g['id'], 'node_id': g['id'] + 300, 'host': f'10.44.0.{g["id"]}', 'user': 'orbit', 'port': 22,
                    'host_keys': [KEY], 'identity_file': str(self.key)} for g in groups if g['running']]
        return {'revision': 3, 'groups': groups, 'targets': targets}

    def run_reaper(self, groups):
        path = self.repo / 'inputs.json'
        path.write_text(json.dumps(self.inputs(groups)))
        result = json.loads(self.cli('workflow', 'run', 'proof-reaper', '--input-file', str(path)).stdout)
        self.assertEqual(result['status'], 'succeeded')
        history = json.loads(self.cli('workflow', 'history', 'get', result['id']).stdout)
        decide = [s for job in history.get('jobs', result['jobs']) for s in job['steps'] if s['name'] == 'decide']
        return decide[0]['outputs']

    def test_idle_superseded_and_silent_groups_are_chosen_and_nothing_else(self):
        old = NOW - 7200
        decision = self.run_reaper([
            {'id': 10, 'title': 'swamp-proof beast:minimal minimal@8', 'started_at': NOW - 600, 'running': True},   # fresh, but superseded by 12
            {'id': 12, 'title': 'swamp-proof beast:minimal minimal@8', 'started_at': None, 'running': False},
            {'id': 20, 'title': 'swamp-proof shark:minimal minimal@8', 'started_at': NOW - 600, 'running': True},   # heartbeat 4000 s old
            {'id': 30, 'title': 'topology minimal@7 old', 'started_at': old, 'running': True},                    # no heartbeat after 2 h
            {'id': 40, 'title': 'topology minimal@7 old', 'started_at': old, 'running': True},                    # SSH fails: no decision
        ])
        self.assertEqual(decision['revision'], 3)
        self.assertEqual([c['group_id'] for c in decision['cancel']], [10, 20, 30])
        stats = [json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertTrue(all(shlex.split(args[-1]) == HEARTBEAT for args in stats))
        self.assertTrue(all(any(a.startswith('StrictHostKeyChecking=yes') for a in args) for args in stats))
        self.assertEqual(sorted({a for args in stats for a in args if a.startswith('orbit@')}),
                         ['orbit@10.44.0.10', 'orbit@10.44.0.20', 'orbit@10.44.0.30', 'orbit@10.44.0.40'])

    def test_no_running_vm_still_chooses_superseded_groups(self):
        decision = self.run_reaper([{'id': 1, 'title': 'swamp-proof a:minimal x', 'started_at': None, 'running': False},
                                    {'id': 2, 'title': 'swamp-proof a:minimal x', 'started_at': None, 'running': False}])
        self.assertEqual(decision['cancel'], [{'group_id': 1, 'reason': 'superseded by group 2'}])


if __name__ == '__main__':
    unittest.main()
