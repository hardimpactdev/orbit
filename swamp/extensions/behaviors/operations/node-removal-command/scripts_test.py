"""Real removal helpers against disposable files and captured system command seams."""
import importlib.util
import os
import tempfile
import types
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).parent


def module(name):
    spec = importlib.util.spec_from_file_location('removal_' + name, ROOT / 'scripts' / (name + '.py'))
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


class RemovalScripts(unittest.TestCase):
    def test_revoke_only_actual_managed_key_fields_preserves_comment_and_options(self):
        m = module('revoke_keys')
        with tempfile.TemporaryDirectory() as root:
            home = Path(root)
            (home / '.ssh').mkdir()
            target = home / '.ssh/authorized_keys'
            retained = b'ssh-ed25519 unrelated comment ssh-ed25519 managed\ncommand="echo ssh-ed25519 managed" ssh-ed25519 other keep\n'
            target.write_bytes(retained + b'no-port-forwarding,command="echo allowed" ssh-ed25519 managed remove\nssh-ed25519 managed plain\n')
            account = types.SimpleNamespace(pw_dir=root, pw_uid=os.getuid(), pw_gid=os.getgid())
            with patch.object(m.pwd, 'getpwnam', return_value=account):
                self.assertTrue(m.revoke({'user': 'fixture', 'keys': ['ssh-ed25519 managed comment']})['revoked'])
            self.assertEqual(target.read_bytes(), retained)
            self.assertEqual(target.stat().st_mode & 0o777, 0o600)
            self.assertEqual(list(target.parent.glob('.orbit-revoke-*')), [])

    def test_role_container_removal_also_removes_anonymous_volumes(self):
        m = module('remove_container')
        name = 'orbit-metrics-grafana'
        for action, expected in (
            ('stop', ['/usr/bin/docker', 'container', 'stop', '--', name]),
            ('rm', ['/usr/bin/docker', 'container', 'rm', '--volumes', '--', name]),
        ):
            calls = []

            def run(argv, **_kwargs):
                calls.append(argv)
                return types.SimpleNamespace(returncode=0, stdout=name + '\n')

            with patch.object(m.subprocess, 'run', side_effect=run):
                m.remove({'name': name, 'action': action})
            self.assertEqual(calls[-1], expected)

    def test_no_footprint_delete_until_all_units_inactive(self):
        m = module('remove_footprint')
        with tempfile.TemporaryDirectory() as root:
            artifact = Path(root) / 'worker-unit'
            artifact.write_text('preserve')
            with patch.dict(m.FILES, {'worker': [str(artifact)]}), patch.object(m.subprocess, 'run', return_value=types.SimpleNamespace(returncode=0)), self.assertRaisesRegex(RuntimeError, 'service_active'):
                m.remove({'node_id': 701, 'group': 'worker'})
            self.assertTrue(artifact.exists())
            with patch.dict(m.FILES, {'worker': [str(artifact)]}), patch.object(m.subprocess, 'run', return_value=types.SimpleNamespace(returncode=3)):
                self.assertTrue(m.remove({'node_id': 701, 'group': 'worker'})['removed'])
            self.assertFalse(artifact.exists())

    def test_control_node_exporter_cleanup_is_allowed(self):
        m = module('remove_footprint')
        with tempfile.TemporaryDirectory() as root:
            artifact = Path(root) / 'exporter-unit'
            artifact.write_text('managed')
            with patch.dict(m.FILES, {'exporter': [str(artifact)]}), patch.object(m.subprocess, 'run', return_value=types.SimpleNamespace(returncode=3)):
                self.assertTrue(m.remove({'node_id': 209, 'group': 'exporter'})['removed'])
            self.assertFalse(artifact.exists())

    def test_control_node_worker_is_never_removed(self):
        with self.assertRaisesRegex(ValueError, 'control_removal_forbidden'):
            module('remove_footprint').remove({'node_id': 209, 'group': 'worker'})

    def test_hub_filter_preserves_private_interface_and_unrelated_peers(self):
        m = module('hub_peer')
        key = 'A' * 43 + '='
        prior = '[Interface]\nPrivateKey = synthetic-never-exported\nAddress=10.44.0.1/24\n\n[Peer]\nPublicKey = ' + key + '\nAllowedIPs=10.44.0.19/32\n\n[Peer]\nPublicKey = retained\nAllowedIPs=10.44.0.20/32\n'
        after = m.without_peer(prior, key)
        self.assertIn('PrivateKey = synthetic-never-exported', after)
        self.assertIn('PublicKey = retained', after)
        self.assertNotIn(key, after)
        self.assertEqual(m.without_peer(after, key), after)

    def test_ci_retains_reserved_resources_but_refuses_occupied_project(self):
        m = module('ci_project')
        def call(argv, **_kwargs):
            return types.SimpleNamespace(stdout='[{"name":"orbit-ci"}]' if 'project' in argv else '[{"name":"runner"}]')
        with patch.object(m.subprocess, 'run', side_effect=call) as runner:
            with self.assertRaisesRegex(ValueError, 'host_occupied'):
                m.remove({})
            self.assertFalse(any('delete' in call.args[0] for call in runner.call_args_list))

    def test_firewall_only_tolerates_exact_missing_rule_failure(self):
        m = module('firewall')
        data = {'argv': ['ufw', '--force', 'delete', 'allow', '22']}
        for message, allowed in [('Could not delete non-existent rule', True), ('Permission denied', False)]:
            with patch.object(m.subprocess, 'run', return_value=types.SimpleNamespace(returncode=1, stdout=message, stderr='')):
                if allowed:
                    self.assertTrue(m.apply(data)['applied'])
                else:
                    with self.assertRaises(RuntimeError):
                        m.apply(data)


if __name__ == '__main__':
    unittest.main()
