"""Native CLI teardown: exact ledger matches, refusal before deletion, failure cleanup."""
import json
import unittest
from pathlib import Path

import yaml
from vault_sync_commands_native_test import ROOT, VaultSyncFixture


class VaultTeardownNative(VaultSyncFixture):
    def setUp(self):
        super().setUp()
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-vault-teardown.yaml').read_text())
        for job in workflow['jobs']:
            for step in job['steps']:
                self.assertEqual(step.pop('labels'), {'role': 'swamp'})
        (self.repo / 'workflows/workflow-operation-vault-teardown.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.inputs['kind'] = 'vault.teardown'
        self.intent['wanted'] = []
        self.private.pop('wanted')
        key = 'vault-teardown-00000000-0000-4000-8000-000000000001'
        self.inputs['execution_secrets']['configuration']['key'] = key
        self.cli('vault', 'put', 'orbit-control-inputs', key, input=json.dumps(self.private))
        self.native_env['FIXTURE_ORGANIZATION'] = self.intent['organization_id']

    def cipher(self, identity='published', source='database-user:7', **extra):
        return {'id': identity, 'source': source, 'organization_id': 'org', 'collection_ids': ['collection'], 'revision': 'old', 'deleted': False, **extra}

    def execute(self, ciphers, success=True, **extra):
        self.state.write_text(json.dumps({'ciphers': ciphers, 'writes': [], 'deletes': [], **extra}))
        self.input_file.write_text(json.dumps(self.inputs))
        result = self.cli('workflow', 'run', 'operation-vault-teardown', '--input-file', str(self.input_file), success=success)
        identity = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]['id']
        history = self.cli('workflow', 'history', 'get', identity)
        run = json.JSONDecoder().raw_decode(history.stdout.lstrip())[0]
        steps = {step['name']: step for job in run['jobs'] for step in job['steps']}
        self.assertEqual(run['status'], 'succeeded' if success else 'failed')
        self.assertEqual(steps['Remove CLI session']['status'], 'succeeded')
        self.assertFalse(Path(steps['Create CLI session']['outputs']['stdout'].strip()).exists())
        self.assert_private_absent(result.stdout, result.stderr, history.stdout)
        return steps, json.loads(self.state.read_text())

    def test_exact_ledger_matches_leave_unrecorded_and_changed_sources_alone(self):
        steps, state = self.execute([self.cipher(), self.cipher('copy'), self.cipher('foreign', None), self.cipher('changed', 'database-user:8'), self.cipher('other-org', organization_id='other'), self.cipher('other-collection', collection_ids=['other'])])
        self.assertEqual(state['deletes'], ['published'])
        self.assertEqual(steps['Confirm teardown']['status'], 'succeeded')

    def test_shared_item_refuses_before_any_delete(self):
        steps, state = self.execute([self.cipher(collection_ids=['collection', 'other'])], success=False)
        self.assertEqual(steps['Select published items']['error'], 'vault.item_shared')
        self.assertEqual(state['deletes'], [])

    def test_delete_failure_keeps_native_completed_statuses_and_always_removes_session(self):
        self.intent['ledger'].append({**self.intent['ledger'][0], 'source': 'database-user:8', 'cipher_id': 'second'})
        steps, state = self.execute([self.cipher(), self.cipher('second', 'database-user:8')], success=False, fail_delete='second')
        self.assertEqual(state['deletes'], ['published'])
        self.assertEqual(steps['Delete the items-0']['status'], 'succeeded')
        self.assertEqual(steps['Delete the items-1']['status'], 'failed')

    def test_missing_and_empty_ledgers_are_successful_and_contact_no_delete(self):
        steps, state = self.execute([])
        self.assertEqual(state['deletes'], [])
        self.assertEqual(steps['Delete the items-0']['status'], 'skipped')
        self.intent['ledger'] = []
        _, state = self.execute([self.cipher()])
        self.assertEqual(state['deletes'], [])


if __name__ == '__main__':
    unittest.main()
