"""Isolated contract tests for the topology's native setup helper."""
import importlib.util
import io
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
    def test_installs_exact_committed_upstream_pins_and_checks_installed_digests(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / 'source'
            repo = Path(directory) / 'repo'
            for path in (source, repo):
                (path / 'extensions/models').mkdir(parents=True)
            pins = {'@swamp/ssh': {'version': '2026.10.23.1', 'checksum': 'a' * 64, 'filesChecksum': 'b' * 64}}
            (source / 'extensions/models/upstream_extensions.json').write_text(json.dumps(pins))
            (repo / 'extensions/models/upstream_extensions.json').write_text(json.dumps(pins))
            (repo / 'extensions/models/upstream_extensions.json').write_text('{}')
            with patch.object(module, 'SOURCE', source), patch.object(module, 'REPO', repo), \
                    patch.object(module, 'native', side_effect=lambda *argv: (repo / 'extensions/models/upstream_extensions.json').write_text(json.dumps(pins))) as native:
                module.install_extensions()
                native.assert_called_once_with('extension', 'pull', '@swamp/ssh@2026.10.23.1')
                native.reset_mock()
                module.install_extensions()
                native.assert_not_called()
                native.side_effect = None
                pins['@swamp/ssh']['checksum'] = 'c' * 64
                (repo / 'extensions/models/upstream_extensions.json').write_text(json.dumps(pins))
                with self.assertRaisesRegex(RuntimeError, 'e2e.swamp_extension_pins_mismatch'):
                    module.install_extensions()

    def test_preparation_installs_assets_and_settings_without_starting_services(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source'
            (source / 'config').mkdir(parents=True)
            (source / 'extensions/models').mkdir(parents=True)
            (source / 'extensions/models/upstream_extensions.json').write_text('{}')
            with patch.object(module, 'ROOT', root), patch.object(module, 'SOURCE', source), \
                    patch.object(module, 'REPO', root / 'repository'), \
                    patch.object(module, 'private_file'), patch.object(module, 'install_binary'), \
                    patch.object(module, 'native', return_value={'token': 'fixture-enrollment'}), \
                    patch.object(module, 'load_source'), patch.object(module, 'token'), \
                    patch.object(module, 'configure_gateway') as configure, patch.object(module, 'units') as units, \
                    patch.object(module, 'run') as run, patch.object(module.sys, 'stdin', io.StringIO('fixture-club')):
                module.prepare()
            configure.assert_called_once_with()
            units.assert_called_once_with()
            self.assertFalse(any(call.args[0][0] == 'systemctl' for call in run.call_args_list))

    def test_explicit_start_requires_native_serve_and_worker_readiness(self):
        with patch.object(module, 'run') as run, patch.object(module, 'wait_ready') as ready:
            module.start()
        run.assert_called_once_with(['systemctl', 'enable', '--now', 'orbit-e2e-swamp.service', 'orbit-node-worker.service'])
        ready.assert_called_once_with()

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

    def test_serve_reads_the_club_key_from_its_credential_file_flag(self):
        written = {}
        with patch.object(module.Path, 'write_text', lambda path, value: written.__setitem__(str(path), value)), \
                patch.object(module, 'run'):
            module.units()
        serve = written['/etc/systemd/system/orbit-e2e-swamp.service']
        self.assertIn('LoadCredential=club:', serve)
        self.assertIn(' serve --club-api-key-file %d/club ', serve)
        self.assertNotIn('SWAMP_API_KEY', serve)

    def test_refresh_pulls_committed_pins_loads_source_and_restarts_into_readiness(self):
        calls = []
        with patch.object(module, 'install_extensions', lambda: calls.append('pins')), \
                patch.object(module, 'load_source', lambda: calls.append('load')), \
                patch.object(module, 'run', lambda argv, **_: calls.append(argv)), \
                patch.object(module, 'wait_ready', lambda: calls.append('ready')), \
                patch.object(module.sys, 'argv', ['converge-swamp.py', 'refresh']), \
                patch.object(module.os, 'geteuid', return_value=0), patch('builtins.print'):
            module.main()
        self.assertEqual(calls, ['pins', 'load',
                                 ['systemctl', 'restart', 'orbit-e2e-swamp.service', 'orbit-node-worker.service'], 'ready'])

    def test_served_source_must_match_the_staged_worktree_and_postdate_its_load(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source'
            repo = root / 'repository'
            for path in (source, repo):
                (path / 'workflows').mkdir(parents=True)
                (path / 'workflows/workflow-a.yaml').write_text('id: 1111\nname: a\njobs:\n  - id: nested\n')
            files = {'workflows/workflow-a.yaml': source / 'workflows/workflow-a.yaml'}
            marker = {'digest': module.source_digest(files), 'boot': module.boot_id(), 'monotonic_us': 100}
            (root / 'loaded.json').write_text(json.dumps(marker))
            served = {'results': [{'id': '1111', 'name': 'a'}]}
            with patch.object(module, 'ROOT', root), patch.object(module, 'SOURCE', source), \
                    patch.object(module, 'REPO', repo), patch.object(module, 'native', return_value=served) as native, \
                    patch.object(module, 'run', return_value='200\n') as run:
                self.assertTrue(module.served_matches_disk())
                self.assertEqual(native.call_args.args[:4], ('workflow', 'search', '--server', module.URL))
                run.return_value = '100\n'
                self.assertFalse(module.served_matches_disk())
                run.return_value = '200\n'
                native.return_value = {'results': []}
                self.assertFalse(module.served_matches_disk())
                native.return_value = served
                (source / 'workflows/workflow-a.yaml').write_text('id: 1111\nname: a\ndescription: unloaded\n')
                self.assertFalse(module.served_matches_disk())

    def test_source_refresh_retains_runtime_state_and_local_vaults(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source'
            repo = root / 'repository'
            for name in ('extensions', 'models', 'workflows', 'config'):
                (source / name).mkdir(parents=True)
            (source / 'extensions/new.ts').write_text('new source')
            (source / 'extensions/models').mkdir()
            (source / 'extensions/models/upstream_extensions.json').write_text('committed pins')
            (source / 'config/gateway-workflow-grants.json').write_text(json.dumps({
                'principal': 'user:orbit-gateway', 'vaults': [
                    {'vault': 'orbit-control-inputs', 'actions': ['read'], 'key_pattern': '^a$'}],
                'workflows': []}))
            (repo / 'vaults/local_encryption').mkdir(parents=True)
            (repo / 'vaults/local_encryption/uuid.yaml').write_text('name: orbit-control-inputs\n')
            (repo / 'models').mkdir()
            (repo / 'models/generated.yaml').write_text('native model')
            (repo / 'extensions/models').mkdir(parents=True)
            native_lock = repo / 'extensions/models/upstream_extensions.json'
            native_lock.write_text('native pulls and checksums')
            (repo / '.swamp/data').mkdir(parents=True)
            (repo / '.swamp/data/history').write_text('retained')
            with patch.object(module, 'SOURCE', source), patch.object(module, 'REPO', repo), \
                    patch.object(module, 'ROOT', root), patch.object(module, 'run'), patch.object(module, 'native') as native:
                module.load_source()
                (source / 'extensions/new.ts').unlink()
                module.load_source()
                self.assertEqual(json.loads((root / 'loaded.json').read_text())['digest'],
                                 module.source_digest(module.source_files()))
            native.assert_not_called()
            self.assertEqual((repo / '.swamp/data/history').read_text(), 'retained')
            self.assertFalse((repo / 'extensions/new.ts').exists())
            self.assertEqual((repo / 'models/generated.yaml').read_text(), 'native model')
            # One pin source: the committed lock replaces what native pulls wrote.
            self.assertEqual(native_lock.read_text(), 'committed pins')
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
