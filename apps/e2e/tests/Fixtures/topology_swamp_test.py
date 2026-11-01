"""Isolated contract tests for the topology's native setup helper."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / 'resources/guest/converge-swamp.py'
spec = importlib.util.spec_from_file_location('topology_swamp', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class TopologySwampTest(unittest.TestCase):
    def test_grants_keep_exact_principal_workflow_and_vault_key_conditions(self):
        manifest = {'principal': 'user:orbit-gateway',
                    'vaults': [{'vault': 'orbit-control-inputs', 'actions': ['write', 'read'],
                                'key_pattern': '^instance-[1-9][0-9]*$'}],
                    'workflows': [{'workflow': 'operation-instance-create', 'actions': ['run', 'read']}]}
        self.assertEqual(module.grants(manifest), {'grants': [
            {'subject': 'user:orbit-gateway', 'effect': 'allow', 'actions': ['write', 'read'],
             'resource': 'vault:orbit-control-inputs', 'condition': 'key.matches("^instance-[1-9][0-9]*$")'},
            {'subject': 'user:orbit-gateway', 'effect': 'allow', 'actions': ['run', 'read'],
             'resource': 'workflow:operation-instance-create'},
            {'subject': 'user:orbit-proof-reader', 'effect': 'allow', 'actions': ['read'],
             'resource': 'workflow:operation-instance-create'}]})

    def test_source_refresh_retains_runtime_state_and_local_vaults(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source'
            repo = root / 'repository'
            for name in ('extensions', 'models', 'workflows', 'config'):
                (source / name).mkdir(parents=True)
            (source / 'extensions/new.ts').write_text('new source')
            (source / 'config/gateway-workflow-grants.json').write_text(json.dumps({
                'principal': 'user:orbit-gateway', 'vaults': [
                    {'vault': 'orbit-control-inputs', 'actions': ['read'], 'key_pattern': '^a$'}],
                'workflows': []}))
            (repo / 'vaults/local_encryption').mkdir(parents=True)
            (repo / 'vaults/local_encryption/uuid.yaml').write_text('name: orbit-control-inputs\n')
            (repo / '.swamp/data').mkdir(parents=True)
            (repo / '.swamp/data/history').write_text('retained')
            with patch.object(module, 'SOURCE', source), patch.object(module, 'REPO', repo), \
                    patch.object(module, 'ROOT', root), patch.object(module, 'run'), patch.object(module, 'native') as native:
                module.load_source()
            native.assert_not_called()
            self.assertEqual((repo / '.swamp/data/history').read_text(), 'retained')
            self.assertEqual((repo / 'extensions/new.ts').read_text(), 'new source')
            self.assertTrue((repo / 'vaults/local_encryption/uuid.yaml').exists())

    def test_local_credential_never_becomes_a_command_argument(self):
        with patch.object(module, 'run', return_value='{"ready":true}') as run:
            self.assertEqual(module.native('init', str(module.REPO), '--tool', 'none'), {'ready': True})
            argv = run.call_args.args[0]
            self.assertNotIn('--repo-dir', argv)
            self.assertEqual(argv[4:6], ['env', '-i'])
            self.assertTrue(any(value.startswith('SWAMP_API_KEY_FILE=') for value in argv))
            self.assertNotIn('SWAMP_API_KEY=', ' '.join(argv))
            module.native('serve', 'reload', '--server', module.URL)
            self.assertNotIn('--repo-dir', run.call_args.args[0])

    def test_startup_requires_worker_readiness_before_reporting_success(self):
        with patch.object(module, 'verify', side_effect=[RuntimeError('not enrolled'), None]) as verify, \
                patch.object(module.time, 'sleep') as sleep:
            module.wait_ready()
        self.assertEqual(verify.call_count, 2)
        sleep.assert_called_once_with(0.25)

    def test_startup_refuses_when_readiness_budget_expires(self):
        with patch.object(module, 'verify', side_effect=RuntimeError('not enrolled')), \
                patch.object(module.time, 'monotonic', side_effect=[0, 121]), \
                self.assertRaisesRegex(RuntimeError, 'e2e.swamp_startup_timeout'):
            module.wait_ready()


if __name__ == '__main__':
    unittest.main()
