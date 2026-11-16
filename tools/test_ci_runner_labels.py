"""All CI Nodes form one pool: a workflow names only the labels every runner carries, never a host (ADR 0260)."""
import json
import re
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
# A GitHub-hosted image, such as ubuntu-26.04 or macos-15. It names no Orbit host.
HOSTED = re.compile(r'(ubuntu|macos|windows)-[\w.]+')


def shared_labels():
    """The one label set that tools/ci-runners/setup.sh gives every runner."""
    found = re.findall(r'^LABELS=(\S+)$', (ROOT / 'tools/ci-runners/setup.sh').read_text(), re.MULTILINE)
    assert len(found) == 1, found
    return set(found[0].split(','))


def label_sets(job):
    """Every label list the job's runs-on can resolve to."""
    runs_on = job.get('runs-on')
    if runs_on is None:
        return []
    if isinstance(runs_on, list):
        return [runs_on]
    if '${{' not in runs_on:
        return [[runs_on]]
    matrix = re.fullmatch(r'\$\{\{ matrix\.(\w+) \}\}', runs_on)
    if matrix:
        return [[entry[matrix.group(1)]] for entry in job['strategy']['matrix']['include']]
    lists = [json.loads(literal) for literal in re.findall(r"'(\[.*?\])'", runs_on)]
    assert lists, f'unknown runs-on expression: {runs_on}'
    return lists


class RunnerLabelTest(unittest.TestCase):
    def test_setup_gives_every_runner_the_same_labels(self):
        self.assertEqual({'self-hosted', 'Linux', 'X64', 'hardimpact', 'orbit-shard'}, shared_labels())
        self.assertNotRegex((ROOT / 'tools/ci-runners/setup.sh').read_text(), r'labels=\$labels,')

    def test_no_workflow_names_a_host_label(self):
        allowed = shared_labels()
        workflows = sorted((ROOT / '.github/workflows').glob('*.y*ml'))
        self.assertTrue(workflows)
        for path in workflows:
            for name, job in (yaml.safe_load(path.read_text()).get('jobs') or {}).items():
                for labels in label_sets(job):
                    for label in labels:
                        with self.subTest(workflow=path.name, job=name, label=label):
                            self.assertTrue(label in allowed or HOSTED.fullmatch(label),
                                            f'{label} is not a shared runner label')

    def test_actionlint_knows_no_host_label(self):
        config = yaml.safe_load((ROOT / '.github/actionlint.yaml').read_text())
        allowed = shared_labels()
        for label in config['self-hosted-runner']['labels']:
            with self.subTest(label=label):
                self.assertTrue(label in allowed or HOSTED.fullmatch(label), f'{label} is not a shared runner label')

    def test_a_host_label_fails_the_check(self):
        job = {'runs-on': ['self-hosted', 'orbit-shard', 'host-a']}
        self.assertNotIn('host-a', shared_labels())
        self.assertIn('host-a', label_sets(job)[0])
        expression = {'runs-on': """${{ fromJSON(vars.X || '["self-hosted","orbit-shard","host-b"]') }}"""}
        self.assertEqual([['self-hosted', 'orbit-shard', 'host-b']], label_sets(expression))


if __name__ == '__main__':
    unittest.main()
