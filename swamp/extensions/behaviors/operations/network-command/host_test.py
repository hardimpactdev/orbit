"""Host actions use scratch paths and fake tools; no host network effects."""
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

SCRIPTS = Path(__file__).parent / 'scripts'
sys.path.insert(0, str(SCRIPTS))
import apply_peer
import common
import dns_persist
import hub_peer
import peer_config
import prepare_key

KEY = 'A' * 43 + '='
OTHER = 'B' * 43 + '='


class NetworkHostActions(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        for module in [common, hub_peer, peer_config, dns_persist, prepare_key, apply_peer]:
            old = module.WG
            module.WG = self.root
            self.addCleanup(setattr, module, 'WG', old)
        (self.root / 'orbit.key').write_text('private-host-key')

    def test_hub_upsert_preserves_interface_private_bytes_and_other_peers(self):
        text = '[Interface]\nPrivateKey = host-private\nAddress = 10.44.0.1/24\n\n[Peer]\nPublicKey = '+OTHER+'\nAllowedIPs = 10.44.0.8/32\n'
        data = {'existing_key': None, 'public_key': KEY, 'address': '10.44.0.9/24'}
        after = hub_peer.update(text, data)
        self.assertTrue(after.startswith(text.rstrip()))
        self.assertIn('AllowedIPs = 10.44.0.9/32', after)
        self.assertEqual(hub_peer.update(after, data), after)
        with self.assertRaises(ValueError):
            hub_peer.update(text, {**data, 'address': '10.44.0.8/24'})

    def test_hub_apply_uses_syncconf_without_service_restart_and_cleans_private_scratch(self):
        (self.root / 'orbit.conf').write_text('[Interface]\nPrivateKey = host-private\n')
        calls = []
        def command(argv, **_kwargs):
            calls.append(argv)
            if 'strip' in argv:
                self.assertRegex(Path(argv[-1]).name, r'^[a-zA-Z0-9_=+.-]{1,15}\.conf$')
            return SimpleNamespace(stdout='[Interface]\nPrivateKey = host-private' if 'strip' in argv else '', returncode=0)
        with patch.object(subprocess, 'run', command):
            result = hub_peer.publish({'existing_key': None, 'public_key': KEY, 'address': '10.44.0.9/24'})
        self.assertEqual(result, {'hub_published': True})
        self.assertEqual([v[1] for v in calls], ['is-active', 'strip', 'syncconf'])
        self.assertEqual(sorted(p.name for p in self.root.iterdir()), ['orbit.conf', 'orbit.key'])
        self.assertNotIn('host-private', json.dumps(result))

    def test_private_key_is_not_rotated_or_exported(self):
        with patch.object(prepare_key, 'key', return_value=KEY):
            self.assertEqual(prepare_key.prepare({'existing_key': KEY}), {'public_key': KEY})
            with self.assertRaises(ValueError):
                prepare_key.prepare({'existing_key': OTHER})
        self.assertEqual((self.root / 'orbit.key').read_text(), 'private-host-key')

    def test_dns_publication_preserves_unrelated_config_and_never_restarts(self):
        config = '[Interface]\nPrivateKey = host-private\n\n[Peer]\nPublicKey = '+OTHER+'\n'
        (self.root / 'orbit.conf').write_text(config)
        calls = []
        def command(argv, **_kwargs):
            calls.append(argv)
            if 'strip' in argv:
                self.assertRegex(Path(argv[-1]).name, r'^[a-zA-Z0-9_=+.-]{1,15}\.conf$')
            return SimpleNamespace(stdout=KEY if 'show' in argv else '', returncode=0)
        data = {'public_key': KEY, 'server': '10.44.0.1', 'domains': ['orbit', 'app.test']}
        with patch.object(subprocess, 'run', command):
            dns_persist.persist(data)
        after = (self.root / 'orbit.conf').read_text()
        self.assertIn('PrivateKey = host-private', after)
        self.assertIn('PublicKey = '+OTHER, after)
        self.assertEqual((self.root / 'orbit.dns-link').read_text(), 'orbit\n10.44.0.1\norbit\napp.test\n')
        self.assertIn(r'PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \~orbit \~app.test', after)
        self.assertFalse(any('systemctl' in call[0] for call in calls))

    def test_active_peer_syncs_without_restart_and_rejects_a_different_live_address(self):
        (self.root / 'orbit.conf').write_text('[Interface]\nAddress = 10.44.0.9/24\nPrivateKey = host-private\n')
        calls = []
        def command(argv, **_kwargs):
            calls.append(argv)
            out = json.dumps([{'addr_info': [{'local': '10.44.0.9', 'prefixlen': 24}]}]) if '-j' in argv else ''
            return SimpleNamespace(stdout=out, returncode=0)
        with patch.object(subprocess, 'run', command):
            self.assertEqual(apply_peer.apply({}), {'applied': True})
        self.assertTrue(any('syncconf' in argv for argv in calls))
        self.assertFalse(any('start' in argv or 'restart' in argv for argv in calls))

    def test_refused_live_address_drift_does_not_overwrite_persistent_configuration(self):
        path = self.root / 'orbit.conf'
        path.write_text('[Interface]\nAddress = 10.44.0.8/24\nPrivateKey = host-private\n')
        before = path.read_bytes()
        def command(argv, **_kwargs):
            return SimpleNamespace(stdout='[]' if '-j' in argv else '', returncode=0)
        with patch.object(peer_config, 'key', return_value=KEY), patch.object(subprocess, 'run', command), self.assertRaises(ValueError):
            peer_config.configure({'public_key':KEY,'peer':{'address':'10.44.0.9/24'}})
        self.assertEqual(path.read_bytes(), before)


if __name__ == '__main__':
    unittest.main()
