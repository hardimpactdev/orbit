"""The orbit-ci project config: the Swamp spec (role converge) and the agent's ci-runner.host carry one map.

The agent must not load extension source (apps/agent/tests/extension_boundary.rs), so this cross-project check lives
with the CI policy tests, which the independent CI policy job runs on every pull request.
"""
from pathlib import Path
import re
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
AGENT = ROOT / 'apps/agent/src/commands/ci_runner.rs'
SPEC = ROOT / 'swamp/extensions/behaviors/operations/node/ci_host_spec.ts'
RUNTIME_DISK = {'restricted.devices.disk': 'allow', 'restricted.devices.disk.paths': '/opt/orbit-runtime'}


def agent_config(source):
    network = re.search(r'^const NETWORK: &str = "([^"]+)";$', source, re.M)[1]
    block = re.search(r'^const PROJECT_CONFIG: \[\(&str, &str\); (\d+)\] = \[\n(.*?)^\];$', source, re.M | re.S)
    pairs = re.findall(r'^\s*\("([^"]+)", (NETWORK|"[^"]*")\),$', block[2], re.M)
    assert len(pairs) == int(block[1]), 'every PROJECT_CONFIG entry is one ("key", value) line'
    return {key: network if value == 'NETWORK' else value.strip('"') for key, value in pairs}


def spec_config(source):
    network = re.search(r'^export const network = "([^"]+)";$', source, re.M)[1]
    block = re.search(r'^export const projectConfig = \{\n(.*?)^\};$', source, re.M | re.S)[1]
    lines = [line.strip() for line in block.splitlines() if line.strip() and not line.strip().startswith('//')]
    config = {}
    for line in lines:
        entry = re.fullmatch(r'("[^"]+"|[A-Za-z_]+): ("[^"]*"|network),', line)
        assert entry, f'unparsed projectConfig line: {line}'
        config[entry[1].strip('"')] = network if entry[2] == 'network' else entry[2].strip('"')
    return config


class ProjectConfigParityTest(unittest.TestCase):
    def test_the_spec_and_the_agent_set_one_map_that_admits_only_the_runtime_disk(self):
        agent, spec = agent_config(AGENT.read_text()), spec_config(SPEC.read_text())
        self.assertEqual(agent, spec)
        self.assertEqual({key: agent.get(key) for key in RUNTIME_DISK}, RUNTIME_DISK)
        self.assertEqual(agent['restricted'], 'true')

    def test_a_side_that_drops_a_runtime_disk_key_breaks_parity(self):
        agent, spec = AGENT.read_text(), SPEC.read_text()
        for key in RUNTIME_DISK:
            line = re.compile(rf'^.*"{re.escape(key)}".*\n', re.M)
            dropped = re.sub(r'; (\d+)\] = \[', lambda m: f'; {int(m[1]) - 1}] = [', line.sub('', agent, count=1), count=1)
            with self.subTest(side='agent', key=key):
                self.assertNotEqual(agent_config(dropped), spec_config(spec))
            with self.subTest(side='spec', key=key):
                self.assertNotEqual(agent_config(agent), spec_config(line.sub('', spec, count=1)))

    def test_policy_runs_without_blocking_selection_and_both_gates_require_it(self):
        jobs = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']
        policy = jobs['ci-policy']
        self.assertNotIn('needs', policy)
        self.assertNotIn('if', policy)
        self.assertNotIn('ci-policy', jobs['changes'].get('needs', []))
        command = next(step for step in policy['steps'] if step.get('name') == 'Check CI scope and import fixtures')
        self.assertNotIn('if', command)
        self.assertIn("python3 -m unittest discover -s tools -p 'test_ci_*.py'", command['run'])
        self.assertFalse(any('test_ci_' in step.get('run', '') for step in jobs['changes']['steps']))
        for gate in ('required', 'delivery'):
            self.assertIn('ci-policy', jobs[gate]['needs'])



if __name__ == '__main__':
    unittest.main()
