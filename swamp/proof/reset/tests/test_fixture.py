"""Shared bootstrap contracts. These tests never configure the test host."""
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(SOURCE / 'tools/swamp-proof-guest'))
import fixture


class SharedFixtureTests(unittest.TestCase):
    def context(self, home):
        return fixture.context(SOURCE, {'network': {'gateway': '10.232.1.10', 'prefix': 24}, 'operator': {'address': '10.232.1.14'}},
                               home=home, state=home / 'control', repo=home / 'repo', nested=True)

    def test_shared_ca_is_rsa4096_and_seed_uses_matching_secret_hash(self):
        with tempfile.TemporaryDirectory() as temporary:
            home = Path(temporary)
            ctx = self.context(home)
            ctx.STATE.mkdir(parents=True)
            commands, seeds = [], []
            def execute(_ctx, argv, **kwargs):
                commands.append(argv)
                if argv[-1] == 'seed':
                    seeds.append(('seed', json.loads(kwargs['input'])))
                    return json.dumps({'nodes': {'gateway': 209, 'operator': 210}, 'swamp_route': {}})
                return ''
            def native(_ctx, action, facts=None):
                seeds.append((action, facts))
                return {'nodes': {'gateway': 209, 'operator': 210}}
            target = {'key': 'app-dev', 'agent_secret_hash': 'a' * 64}
            with patch.object(fixture, 'run', side_effect=execute), patch.object(fixture, 'private'), patch.object(fixture, 'native', side_effect=native), patch.object(fixture, 'serve_gateway'), patch.object(fixture, 'observe'), patch.object(fixture, 'route_leaf'), patch.object(fixture, 'publish_caddy'), patch.object(Path, 'read_text', return_value='ssh-ed25519 fixture-host-key'):
                fixture.gateway(ctx, [target])
            ca = next(argv for argv in commands if '-newkey' in argv)
            self.assertEqual('rsa:4096', ca[ca.index('-newkey') + 1])
            seed = next(facts for action, facts in seeds if action == 'seed')
            import hashlib
            self.assertEqual(hashlib.sha256(b'ssh-ed25519 fixture-host-key').hexdigest(), seed['gateway_agent_secret_hash'])
            self.assertEqual([target], seed['targets'])
            self.assertFalse(any(argv[0] == 'composer' for argv in commands), 'nested setup must not mutate installed candidate dependencies')

    def test_installation_creates_required_group_and_installs_rendered_unit(self):
        ctx = self.context(Path('/unused'))
        commands = []
        fixture.install_agent(ctx, {'configuration': 'real rendered config', 'certificate': 'fixture CA', 'unit': 'real rendered unit'}, 'fixture secret',
                              execute=lambda argv, **kwargs: commands.append((argv, kwargs)))
        self.assertIn('groupadd --system orbit-env', commands[0][0][-1])
        installed = {argv[-1]: kwargs['input'] for argv, kwargs in commands if 'input' in kwargs}
        self.assertEqual('fixture secret', installed['/etc/orbit/agent/secret'])
        self.assertEqual('real rendered unit', installed['/etc/systemd/system/orbit-agent.service'])
        self.assertEqual(['systemctl', 'restart', 'orbit-agent'], commands[-1][0])

    def test_nested_runtime_uses_php_fixture_driver_not_swamp_subcommand(self):
        sys.path.insert(0, str(SOURCE / 'swamp/proof/reset'))
        import guest
        with tempfile.TemporaryDirectory() as temporary:
            ctx = self.context(Path(temporary))
            ctx.STATE.mkdir(parents=True)
            (ctx.STATE / 'fixture.json').write_text(json.dumps({'nodes': {'gateway': 209, 'operator': 210, 'app-dev': 211}}))
            def execute(_ctx, argv, **kwargs):
                if argv[-1] == 'proof-state':
                    return json.dumps({'baseline_commands': {'gateway': [['ufw', 'allow', '22']]}})
                if argv[-1] == 'agent':
                    return json.dumps({'configuration': 'config', 'certificate': 'CA', 'unit': 'unit'})
                raise AssertionError(argv)
            with patch.object(guest, 'context', return_value=ctx), patch.object(fixture, 'run', side_effect=execute), patch.object(fixture, 'native') as native, patch.object(fixture, 'realtime'), patch.object(fixture, 'projection_queue'):
                value = guest.main('gateway-runtime', {'nodes': {}})
                native.assert_not_called()
            self.assertEqual({'gateway', 'app-dev'}, set(value['agents']))
            self.assertEqual([['ufw', 'allow', '22']], value['baseline_commands']['gateway'])

    def test_projection_worker_restores_private_documents_runtime_on_boot(self):
        with tempfile.TemporaryDirectory() as temporary:
            ctx = self.context(Path(temporary))
            files = {}
            with patch.object(Path, 'write_text', autospec=True, side_effect=lambda path, text: files.update({str(path): text})), patch.object(fixture, 'run') as run:
                fixture.projection_queue(ctx)
            tmpfiles = files['/etc/tmpfiles.d/orbit-proof-documents.conf']
            self.assertIn('project-documents/execution.lock 0600 orbit orbit', tmpfiles)
            self.assertIn('--tries=1 --timeout=1700', files['/etc/systemd/system/orbit-proof-operations.service'])
            self.assertIn(unittest.mock.call(ctx, ['systemd-tmpfiles', '--create', '/etc/tmpfiles.d/orbit-proof-documents.conf']), run.call_args_list)

    def test_legacy_entrypoint_delegates_without_nested_mode(self):
        spec = importlib.util.spec_from_file_location('legacy_proof_up', SOURCE / 'tools/swamp-proof-guest/up.py')
        legacy = importlib.util.module_from_spec(spec)
        with patch.object(sys, 'argv', ['up.py', str(SOURCE), 'minimal']):
            spec.loader.exec_module(legacy)
        self.assertFalse(legacy.FIXTURE.nested)
        self.assertEqual(SOURCE / 'swamp', legacy.FIXTURE.REPO)
        with patch.object(fixture, 'gateway', return_value={'nodes': 'shared'}) as shared:
            self.assertEqual({'nodes': 'shared'}, legacy.gateway(['target']))
            shared.assert_called_once_with(legacy.FIXTURE, ['target'])
        with patch.object(fixture, 'swamp', return_value='shared') as shared:
            self.assertEqual('shared', legacy.swamp())
            shared.assert_called_once_with(legacy.FIXTURE)


if __name__ == '__main__':
    unittest.main()
