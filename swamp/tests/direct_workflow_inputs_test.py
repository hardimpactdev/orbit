"""Every full-intent Gateway start also carries an execution_secrets object; strict schemas must accept it."""
import unittest
from pathlib import Path

import yaml

WORKFLOWS = Path(__file__).resolve().parents[1] / 'workflows'


class DirectWorkflowInputs(unittest.TestCase):
    def test_every_intent_workflow_accepts_the_execution_secrets_object(self):
        refused = []
        for path in sorted(WORKFLOWS.glob('workflow-operation-*.yaml')):
            inputs = yaml.safe_load(path.read_text()).get('inputs') or {}
            properties = inputs.get('properties') or {}
            if 'intent' in properties and inputs.get('additionalProperties') is False and \
                    (properties.get('execution_secrets') or {}).get('type') != 'object':
                refused.append(path.name)
        self.assertEqual(refused, [])


if __name__ == '__main__':
    unittest.main()
