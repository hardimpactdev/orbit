"""PR documentation policy runs in Required, with visible reasons treated as data."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

import yaml
from test_ci_build_plan import plan
from test_ci_delivery import delivery, needs_for

ROOT = Path(__file__).resolve().parents[1]
CI = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())


class DocsImpactPolicyTest(unittest.TestCase):
    def test_pr_body_edits_recheck_the_merge_tree_and_always_select_docs(self):
        self.assertEqual(set(CI[True]['pull_request']['types']), {'opened', 'synchronize', 'reopened', 'edited'})
        jobs = CI['jobs']
        selection = next(step for step in jobs['changes']['steps'] if step.get('id') == 'plan')
        self.assertIn('if [ "$GITHUB_EVENT_NAME" = pull_request ]; then args+=(--pull-request); fi', selection['run'])
        self.assertIn('"${args[@]}"', selection['run'])
        self.assertIn('docs', jobs['required']['needs'])
        self.assertEqual(jobs['docs']['timeout-minutes'], 2)
        checkout = jobs['docs']['steps'][0]['with']
        self.assertEqual(checkout['ref'], '${{ github.sha }}')
        self.assertEqual(checkout['fetch-depth'], 0)

    def test_body_is_data_and_the_actual_shell_step_propagates_gate_failure(self):
        steps = CI['jobs']['docs']['steps']
        step = next(step for step in steps if 'bin/docs-impact --gate' in step.get('run', ''))
        self.assertEqual(step['if'], "github.event_name == 'pull_request'")
        self.assertEqual(step['env']['BASE'], '${{ github.event.pull_request.base.sha }}')
        self.assertLess(next(i for i, s in enumerate(steps) if 'bin/build-api' in s.get('run', '')), steps.index(step))
        for body, code in ((None, 0), ('Docs-unaffected: docs/a.md: $(touch injected); `touch injected`', 1)):
            with self.subTest(body=body), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'bin').mkdir()
                (root / 'event.json').write_text(json.dumps({'pull_request': {'body': body}}))
                command = root / 'bin/docs-impact'
                command.write_text('#!/usr/bin/env python3\nimport json,sys\nfrom pathlib import Path\n'
                                   'Path("args.json").write_text(json.dumps(sys.argv[1:]))\n'
                                   f'sys.exit({code})\n')
                command.chmod(0o755)
                result = subprocess.run(['bash', '-e', '-c', step['run']], cwd=root,
                                        env=os.environ | {'GITHUB_EVENT_PATH': str(root / 'event.json'),
                                                          'RUNNER_TEMP': directory, 'BASE': 'event-base'},
                                        capture_output=True, text=True, timeout=10)
                self.assertEqual(result.returncode, code, result.stderr)
                self.assertEqual((root / 'docs-impact-pr-body.txt').read_text(), body or '')
                self.assertEqual(json.loads((root / 'args.json').read_text()),
                                 ['--gate', '--base', 'event-base', '--pr-body-file', str(root / 'docs-impact-pr-body.txt')])
                self.assertFalse((root / 'injected').exists())

    def test_uncovered_pr_still_blocks_required_when_docs_fails_or_is_skipped(self):
        self.assertTrue(plan.select(['tools/no-covered-path.txt'], pull_request=True)['jobs']['docs'])
        for status in ('failure', 'skipped', 'cancelled'):
            needs = needs_for()
            needs['docs']['result'] = status
            with self.subTest(status=status), patch.dict(os.environ, PROOF_REQUIRED='false',
                    PROOF_BLOCKING='false', SLOW_CHECKS_BLOCKING='false'), self.assertRaisesRegex(SystemExit, 'docs'):
                delivery.validate(needs)


if __name__ == '__main__':
    unittest.main()
