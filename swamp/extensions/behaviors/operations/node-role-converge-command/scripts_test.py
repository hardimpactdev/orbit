"""Exercise preserved observer file policy on disposable unprivileged files."""
import hashlib
import importlib.util
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


def load(name, directory="scripts"):
    base=Path(__file__).parent if directory=="runtime_scripts" else Path(__file__).parent.parent/'fleet-recipe'
    spec=importlib.util.spec_from_file_location(name,base/directory/(name+'.py'))
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    return module

class ObserverPolicy(unittest.TestCase):
    def test_existing_secret_is_verified_without_mutation(self):
        module=load('observer_identity')
        with tempfile.TemporaryDirectory() as root:
            secret=Path(root)/'secret';secret.write_bytes(b'synthetic-existing-secret')
            with patch.object(module,'SECRET',secret):
                self.assertEqual(module.verify(hashlib.sha256(secret.read_bytes()).hexdigest()),{'ready':True,'changed':False})
                with self.assertRaises(ValueError):module.verify('a'*64)
            self.assertEqual(secret.read_bytes(),b'synthetic-existing-secret')

    def test_file_replay_preserves_inode_and_foreign_marker_is_refused(self):
        module=load('file')
        with tempfile.TemporaryDirectory() as root:
            path=Path(root)/'configuration'
            data={'path':str(path),'content':'# Managed by Orbit: agent\npublic','owner':f'{os.getuid()}:{os.getgid()}','mode':'0644','marker':'# Managed by Orbit: agent'}
            self.assertTrue(module.apply(data)['changed']);inode=path.stat().st_ino
            self.assertFalse(module.apply(data)['changed']);self.assertEqual(path.stat().st_ino,inode)
            path.write_text('foreign')
            with self.assertRaises(ValueError):module.apply(data)
            self.assertEqual(path.read_text(),'foreign')

    def test_checkout_closes_world_permissions_without_following_environment_links(self):
        module=load('observer_checkout')
        with tempfile.TemporaryDirectory() as root:
            checkout=Path(root)/'checkout';checkout.mkdir();environment=checkout/'.env';environment.write_text('synthetic');environment.chmod(0o644)
            self.assertTrue(module.apply({'checkout':str(checkout)})['changed']);self.assertEqual(environment.stat().st_mode&0o777,0o640)
            outside=Path(root)/'outside';outside.write_text('unchanged');outside.chmod(0o644)
            environment.unlink();environment.symlink_to(outside)
            self.assertFalse(module.apply({'checkout':str(checkout)})['changed']);self.assertEqual(outside.stat().st_mode&0o777,0o644)


class WebSocketLinkPolicy(unittest.TestCase):
    def test_restore_accepts_the_canonical_relative_prior_link_but_refuses_a_foreign_target(self):
        module=load('websocket_link', 'runtime_scripts')
        with tempfile.TemporaryDirectory() as root:
            install=Path(root)/'reverb'
            releases=Path(str(install)+'.releases');releases.mkdir()
            previous=releases/('release.'+'a'*32);previous.mkdir()
            candidate=releases/('release.'+'b'*32);candidate.mkdir()
            install.symlink_to(previous.relative_to(root))
            data={'install_path':str(install),'release':str(candidate),'previous':str(previous),'restore':True}
            self.assertTrue(module.publish(data)['ready'])
            self.assertEqual(install.resolve(),previous)
            foreign=Path(root)/'foreign';foreign.mkdir()
            install.unlink();install.symlink_to(foreign)
            with self.assertRaises(ValueError):module.publish(data)
            self.assertEqual(install.resolve(),foreign)
