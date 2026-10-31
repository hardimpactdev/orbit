"""Official SSH canary workflow on a scratch native runtime; no fleet connections."""
import json
import os
import shlex
import sys
import unittest
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class OfficialSshCanary(NativeTestCase):
    native_timeout = 120

    def test_official_exec_script_stdin_and_cleanup(self):
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        fixture = self.repo / 'fixture'
        fixture.mkdir()
        key = fixture / 'existing-key'
        key.write_text('synthetic fixture identity, never used by SSH')
        key.chmod(0o600)
        calls = fixture / 'calls.jsonl'
        fake = fixture / 'ssh'
        fake.write_text('#!' + sys.executable + '\n' + '''import json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
body=sys.stdin.buffer.read()
with Path(CALLS).open('a') as f:
    f.write(json.dumps({'argv':args,'stdin':body.decode()})+'\\n')
argv=shlex.split(args[-1])
if argv[:3]==['sudo','-n','--']:
    argv=argv[3:]
if argv[:1]==['/usr/bin/cmp'] and Path(FAIL_READ).exists():
    raise SystemExit(1)
if argv==['/usr/bin/hostname']:
    print('vpn1' if 'orbit@10.44.0.1' in args else 'usexit18')
    raise SystemExit(0)
result=subprocess.run(argv,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace('CALLS', repr(str(calls))).replace('FAIL_READ', repr(str(fixture / 'fail-read'))))
        fake.chmod(0o755)
        production = yaml.safe_load((ROOT / 'workflows/workflow-node-proxy-canary.yaml').read_text())
        for step in production['jobs'][0]['steps']:
            self.assertEqual((step['target'], step['labels']), ('orbit-node-209', {'node': '209'}))
            self.assertEqual(step['task']['behaviorType'], '@swamp/ssh')
            self.assertNotIn('sshBinary', step['task']['globalArgs'])

        def fixture_copy(value):
            if isinstance(value, str):
                return value.replace('/run/orbit-official-ssh-canary-', str(fixture / 'canary-'))
            if isinstance(value, list):
                return [fixture_copy(item) for item in value]
            if isinstance(value, dict):
                return {name: fixture_copy(item) for name, item in value.items()}
            return value

        workflow = fixture_copy(production)
        workflow['jobs'][0].pop('target')
        workflow['jobs'][0].pop('labels')
        for step in workflow['jobs'][0]['steps']:
            # Only fixture placement and the official package's own binary seam
            # differ; no production or pulled extension source is modified.
            step.pop('target')
            step.pop('labels')
            globals_ = step['task']['globalArgs']
            globals_['sshBinary'] = str(fake)
            globals_['transport']['identityFile'] = str(key)
        (self.repo / 'workflows/workflow-node-proxy-canary.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('workflow', 'validate', 'node-proxy-canary')
        runs = []
        for node in (1, 18):
            for effect in (False, True):
                result = json.loads(self.cli('workflow', 'run', 'node-proxy-canary', '--input', f'node_id={node}',
                                             '--input', 'effect=' + str(effect).lower()).stdout)
                self.assertEqual(result['status'], 'succeeded')
                steps = result['jobs'][0]['steps']
                self.assertEqual(steps[0]['status'], 'succeeded')
                self.assertTrue(all(step['status'] == ('succeeded' if effect else 'skipped') for step in steps[1:]))
                runs.append({'node_id': node, 'effect': effect, 'run_id': result['id'], 'status': result['status']})
        self.assertEqual(list(fixture.glob('canary-*')), [])
        (fixture / 'fail-read').touch()
        failed = json.loads(self.cli('workflow', 'run', 'node-proxy-canary', '--input', 'node_id=1',
                                     '--input', 'effect=true', success=False).stdout)
        self.assertEqual(failed['status'], 'failed')
        failed_steps = {step['name']: step['status'] for step in failed['jobs'][0]['steps']}
        self.assertEqual(failed_steps['read'], 'failed')
        self.assertEqual(failed_steps['remove-file'], 'succeeded')
        self.assertEqual(failed_steps['remove-directory'], 'succeeded')
        self.assertEqual(list(fixture.glob('canary-*')), [])
        records = [json.loads(line) for line in calls.read_text().splitlines()]
        scripts = [row for row in records if 'orbit-official-ssh-canary' in row['stdin'] and 'printf' in row['stdin']]
        self.assertEqual(len(scripts), 3)
        self.assertTrue(all('orbit-official-ssh-canary' not in shlex.join(row['argv']) for row in scripts))
        self.assertTrue(all('sh -s --' in row['argv'][-1] for row in scripts))
        comparisons = [row for row in records if '/usr/bin/cmp' in row['argv'][-1]]
        self.assertEqual([row['stdin'] for row in comparisons], ['orbit-official-ssh-canary\n'] * 3)
        evidence = os.environ.get('OFFICIAL_SSH_CANARY_EVIDENCE')
        if evidence:
            Path(evidence).write_text(json.dumps({'package': '@swamp/ssh@2026.10.23.1', 'model_version': '2026.10.20.1',
                                               'runs': runs, 'failed_read_run': failed['id'], 'failed_read_cleanup': True, 'cleanup': True, 'fleet_execution_proven': False}, indent=2) + '\n')


if __name__ == '__main__':
    unittest.main()
