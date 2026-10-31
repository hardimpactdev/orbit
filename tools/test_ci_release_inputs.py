"""CLI publication consumes the same check decision as Gateway release selection."""
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest

loader = importlib.machinery.SourceFileLoader('release_inputs', str(Path(__file__).resolve().parents[1] / 'bin/ci-release-inputs'))
spec = importlib.util.spec_from_loader(loader.name, loader)
inputs = importlib.util.module_from_spec(spec)
loader.exec_module(inputs)
SHA = 'a' * 40


def run(conclusion='success', **overrides):
    return dict(name='Gateway release inputs', head_sha=SHA, status='completed',
                conclusion=conclusion, app={'slug': 'github-actions'}) | overrides


class ReleaseInputsTest(unittest.TestCase):
    def test_successful_ci_decision_publishes_even_when_last_commit_is_swamp_only(self):
        # CI classified the whole push, including a preceding Gateway commit.
        self.assertTrue(inputs.selected([{'check_runs': [run()]}], SHA))

    def test_skipped_swamp_only_decision_does_not_publish(self):
        self.assertFalse(inputs.selected([{'check_runs': [run('skipped')]}], SHA))

    def test_every_decision_must_pass_across_pages(self):
        self.assertFalse(inputs.selected([{'check_runs': [run()]}, {'check_runs': [run('skipped')]}], SHA))

    def test_missing_failed_pending_foreign_and_other_commit_checks_refuse(self):
        for rows in ([], [run('failure')], [run(status='in_progress')],
                     [run(app={'slug': 'another-app'})], [run(head_sha='b' * 40)]):
            with self.subTest(rows=rows), self.assertRaises(ValueError):
                inputs.selected([{'check_runs': rows}], SHA)

    def test_multi_commit_push_publishes_from_ci_decision(self):
        root_bin = Path(__file__).resolve().parents[1] / 'bin'
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            def git(*args):
                return subprocess.check_output(['git', '-c', 'user.name=Fixture', '-c',
                                                'user.email=fixture@example.com', *args], cwd=root,
                                               stderr=subprocess.DEVNULL, timeout=60).decode().strip()

            git('init')
            (root / 'README.md').write_text('base')
            git('add', '.')
            git('commit', '-m', 'base')
            base = git('rev-parse', 'HEAD')
            (root / 'apps/gateway').mkdir(parents=True)
            (root / 'apps/gateway/code.php').write_text('<?php // Gateway change')
            git('add', '.')
            git('commit', '-m', 'Gateway change')
            (root / 'swamp').mkdir()
            (root / 'swamp/workflow.yaml').write_text('name: Swamp change')
            git('add', '.')
            git('commit', '-m', 'Swamp-only tip')
            head = git('rev-parse', 'HEAD')
            plan_loader = importlib.machinery.SourceFileLoader('push_plan', str(root_bin / 'ci-build-plan'))
            plan_spec = importlib.util.spec_from_loader(plan_loader.name, plan_loader)
            plan = importlib.util.module_from_spec(plan_spec)
            plan_loader.exec_module(plan)
            self.assertTrue(plan.select(git('diff', '--name-only', base, head).splitlines())['gateway_release'])
            self.assertFalse(plan.select(git('diff', '--name-only', head + '^', head).splitlines())['gateway_release'])
            output = root / 'github-output'
            subprocess.run(['python3', str(root_bin / 'ci-release-inputs'), '--sha', head,
                            '--github-output', str(output)], input=json.dumps([{'check_runs': [run(head_sha=head)]}]),
                           text=True, check=True, timeout=60)
            self.assertEqual('gateway_release=true\n', output.read_text())


if __name__ == '__main__':
    unittest.main()
