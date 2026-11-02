"""node.converge: a guarded step can be skipped and then has no entry in `steps`, and CEL fails on a missing key. So
every expression that reads such a step first checks that it is there."""
import re
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]


class NodeConvergeGuardKeys(unittest.TestCase):
    def test_reads_of_a_guarded_step_check_that_it_ran(self):
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-converge-direct.yaml').read_text())
        steps = [step for job in workflow['jobs'] for step in job['steps']]
        guarded = {step['name'] for step in steps if 'guard' in step}
        self.assertIn('Install Caddy', guarded)
        for step in steps:
            text = yaml.safe_dump({key: value for key, value in step.items() if key != 'name'})
            for name in set(re.findall(r'steps\["([^"]+)"\]', text)) & guarded:
                self.assertIn(f'"{name}" in steps', text, f'{step["name"]} reads {name} without checking that it ran')


if __name__ == '__main__':
    unittest.main()
