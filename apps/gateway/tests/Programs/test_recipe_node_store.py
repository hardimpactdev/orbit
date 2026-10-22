"""Recipe publication and deploy prerequisites share immutable real Node directories."""
import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import tarfile
import tempfile
import types
import unittest
from unittest.mock import patch

from harness import result
from test_recipe_versions import recipe, image


class RecipeNodeStoreTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.home = Path(directory.name)
        self.account = types.SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(),
                                             pw_dir=str(self.home), pw_name='orbit')
        self.version = '24.22.1'
        self.root_name = 'node-v24.22.1-linux-x64'
        self.tools = {'node': {'source': 'artifact', 'version': self.version}}
        self.archive = self.home / 'node.tar.gz'
        with tarfile.open(self.archive, 'w:gz') as archive:
            for name, text in {'bin/node': '#!/bin/sh\necho v24.22.1\n', 'lib/node_modules/npm/package.json': '{"name":"npm"}'}.items():
                data = text.encode()
                entry = tarfile.TarInfo(self.root_name + '/' + name)
                entry.size = len(data)
                entry.mode = 0o755 if name == 'bin/node' else 0o644
                archive.addfile(entry, io.BytesIO(data))
            link = tarfile.TarInfo(self.root_name + '/bin/npm')
            link.type = tarfile.SYMTYPE
            link.linkname = '../lib/node_modules/npm/package.json'
            archive.addfile(link)
        self.identifier = hashlib.sha256(self.archive.read_bytes()).hexdigest()

    def install(self, scope, module=recipe):
        safe, publish, run = module.safe_directory, module.publish, module.run
        with tempfile.TemporaryDirectory(dir=self.home) as directory:
            def safe_dir(path, uid=0, gid=0):
                return self.home if str(path) == '/opt/orbit' else safe(path, uid, gid)
            def write(path, *args, **kwargs):
                if not str(path).startswith('/usr/local/bin/'):
                    publish(path, *args, **kwargs)
            def command(argv, **kwargs):
                if '-c' in argv:
                    if os.geteuid() == 0:
                        self.assertEqual(argv[:4], ['sudo', '-u', self.account.pw_name, '-H'])
                    self.assertEqual(argv[4:6] if argv[0] == 'sudo' else argv[:2], ['env', 'VP_HOME=' + str(scope)])
                    return run(argv[4:] if argv[0] == 'sudo' else argv, **kwargs)
                self.assertEqual(argv[-3:], ['env', 'default', self.version])
                (scope / 'config.json').write_text(json.dumps({'defaultNodeVersion': self.version}))
            with patch.object(module, 'vite_scope', return_value=scope), \
                 patch.object(module, 'safe_directory', side_effect=safe_dir), \
                 patch.object(module, 'publish', side_effect=write), \
                 patch.object(module, 'run', side_effect=command):
                module.install_javascript(self.tools, {'node': self.archive}, Path(directory), self.account)

    def scope(self, layout):
        scope = self.home / layout
        scope.mkdir(parents=True)
        scope.chmod(0o755)
        return scope

    def seed_manager(self, scope):
        payload = {'store': str(scope), 'node_version': self.version,
                   'package_manager': 'npm', 'package_manager_version': '11.12.0'}
        package = scope / 'package_manager/npm/11.12.0/npm'
        (package / 'bin').mkdir(parents=True)
        (package / 'bin/npm').write_text('#!/bin/sh\nexit 0\n')
        (package / 'bin/npm').chmod(0o755)
        (package / 'package.json').write_text('{"name":"npm","version":"11.12.0"}')
        return payload

    def test_recipe_runtime_passes_both_deploy_programs_in_production_and_dev_stores(self):
        for layout, module in [('production', recipe), ('.vite-plus', recipe), ('.local/share/vite-plus', image)]:
            with self.subTest(layout=layout):
                scope = self.scope(layout)
                self.install(scope, module)
                node = scope / 'js_runtime/node' / self.version
                self.assertTrue(node.is_dir())
                self.assertFalse(node.is_symlink())
                payload = self.seed_manager(scope)
                self.assertEqual(result('tools.inspect-vp-store', {'environments': [payload]}), ('inspected', {'installed': [True]}))
                self.assertEqual(result('tools.install-vp-environment', payload), ('installed', {'verified': {}}))

    def test_converge_preserves_a_real_store_or_recipe_publication_and_its_receipt(self):
        for publisher in ('store', 'recipe'):
            with self.subTest(publisher=publisher):
                scope = self.scope(publisher)
                node = scope / 'js_runtime/node' / self.version
                if publisher == 'store':
                    work = self.home / 'seed'
                    work.mkdir()
                    source = recipe.extract(self.archive, work / 'node') / self.root_name
                    node.parent.mkdir(parents=True)
                    shutil.copytree(source, node, symlinks=True)
                    (scope / '.orbit-runtimes.json').write_text('{"js_runtime/node/24.22.1":"store-receipt"}')
                else:
                    self.install(scope)
                before = {str(p.relative_to(node)): (p.lstat().st_ino, p.lstat().st_mtime_ns) for p in [node, *node.rglob('*')]}
                self.install(scope)
                after = {str(p.relative_to(node)): (p.lstat().st_ino, p.lstat().st_mtime_ns) for p in [node, *node.rglob('*')]}
                self.assertEqual(after, before)
                if publisher == 'store':
                    self.assertEqual((scope / '.orbit-runtimes.json').read_text(), '{"js_runtime/node/24.22.1":"store-receipt"}')

    def test_converge_migrates_only_the_exact_recipe_link_and_preserves_its_old_target(self):
        scope = self.scope('migration')
        old = scope / 'recipe' / ('node-' + self.identifier)
        old.mkdir(parents=True)
        source = recipe.extract(self.archive, old / 'unpack') / self.root_name
        source.rename(old / self.root_name)
        node = scope / 'js_runtime/node' / self.version
        node.parent.mkdir(parents=True)
        node.symlink_to(old / self.root_name)
        (scope / 'config.json').write_text(json.dumps({'defaultNodeVersion': self.version}))
        with patch.object(recipe, 'vite_scope', return_value=scope), patch.object(recipe, 'packages', return_value={}), \
             patch.object(recipe, 'binary_version') as probe:
            self.assertEqual(recipe.inspect(self.tools), {'node': None})
            probe.assert_not_called()
        self.install(scope)
        self.assertFalse(node.is_symlink())
        self.assertTrue((old / self.root_name / 'bin/node').is_file())
        payload = self.seed_manager(scope)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [payload]}), ('inspected', {'installed': [True]}))

    def test_unrelated_links_and_differing_real_content_are_refused_without_deletion(self):
        for kind in ('link', 'modified', 'writable'):
            with self.subTest(kind=kind):
                scope = self.scope(kind)
                self.install(scope)
                node = scope / 'js_runtime/node' / self.version
                if kind == 'link':
                    saved = scope / 'unrelated'
                    node.rename(saved)
                    node.symlink_to(saved)
                elif kind == 'modified':
                    (node / 'bin/node').write_text('modified')
                else:
                    node.chmod(0o775)
                before = node.lstat().st_ino
                with self.assertRaises(recipe.RecipeFailure):
                    self.install(scope)
                self.assertEqual(node.lstat().st_ino, before)
                if kind == 'modified':
                    self.assertEqual((node / 'bin/node').read_text(), 'modified')

    def test_recipe_adoption_protects_the_active_runtime_from_release_cleanup(self):
        scope = self.scope('collection')
        self.install(scope)
        node = scope / 'js_runtime/node' / self.version
        # Model a deploy-owned publication awaiting recipe adoption.
        (scope / '.orbit-recipe-node.json').unlink()
        ledger = {'js_runtime/node/24.22.1': 'verified-store-checksum'}
        receipt = scope / '.orbit-runtimes.json'
        receipt.write_text(json.dumps(ledger))
        with patch.object(recipe, 'vite_scope', return_value=scope), patch.object(recipe, 'packages', return_value={}):
            self.assertEqual(recipe.inspect(self.tools), {'node': None})
        inode = node.stat().st_ino
        self.install(scope)
        self.assertEqual(result('tools.prune-vp-store', {'store': str(scope), 'references': []}), ('pruned', {'removed': []}))
        self.assertEqual(node.stat().st_ino, inode)
        self.assertEqual(json.loads(receipt.read_text()), ledger)
        # The recipe reference moves rather than retaining every prior default forever.
        (scope / '.orbit-recipe-node.json').write_text('{"version":"26.12.1"}')
        self.assertEqual(result('tools.prune-vp-store', {'store': str(scope), 'references': []}),
                         ('pruned', {'removed': ['js_runtime/node/24.22.1']}))

    def test_converge_accepts_an_owned_deploy_lock_without_replacing_its_inode(self):
        scope = self.scope('existing-lock')
        lock = scope / '.orbit-store.lock'
        lock.touch(mode=0o664)
        lock.chmod(0o664)
        inode = lock.stat().st_ino
        self.install(scope)
        self.assertEqual(lock.stat().st_ino, inode)
        self.assertEqual(lock.stat().st_mode & 0o777, 0o644)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [self.seed_manager(scope)]}),
                         ('inspected', {'installed': [True]}))

    def test_converge_migrates_all_recipe_versions_and_collection_keeps_adopted_content(self):
        scope = self.scope('all-versions')
        for version in ('24.22.0', self.version):
            old = scope / 'recipe' / ('node-' + (self.identifier if version == self.version else 'a' * 64))
            old.mkdir(parents=True)
            source = recipe.extract(self.archive, old / 'unpack') / self.root_name
            target = old / self.root_name.replace(self.version, version)
            source.rename(target)
            (target / 'bin/node').chmod(0o775)
            node = scope / 'js_runtime/node' / version
            node.parent.mkdir(parents=True, exist_ok=True)
            node.symlink_to(target)
        # The root recipe delegates publication to the account, including all historical versions.
        with patch.object(recipe.os, 'geteuid', return_value=0):
            self.install(scope)
        payload = self.seed_manager(scope)
        older = {**payload, 'node_version': '24.22.0'}
        for version in ('24.22.0', self.version):
            self.assertFalse((scope / 'js_runtime/node' / version).is_symlink())
            self.assertEqual((scope / 'js_runtime/node' / version / 'bin/node').stat().st_mode & 0o777, 0o755)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [payload, older]}),
                         ('inspected', {'installed': [True, True]}))
        self.assertEqual(result('tools.install-vp-environment', older), ('installed', {'verified': {}}))
        self.assertEqual(result('tools.prune-vp-store', {'store': str(scope), 'references': []}),
                         ('pruned', {'removed': []}))
        self.assertTrue((scope / 'js_runtime/node/24.22.0/bin/node').is_file())
        self.assertEqual(json.loads((scope / '.orbit-recipe-node.json').read_text()), {'version': self.version})
        self.assertNotIn('js_runtime/node/24.22.0', json.loads((scope / '.orbit-runtimes.json').read_text()))
        # A legacy link for an older version also forces adoption with an already-current default/reference.
        older_node = scope / 'js_runtime/node/24.22.0'
        shutil.rmtree(older_node)
        older_node.symlink_to(scope / 'recipe' / ('node-' + 'a' * 64) / 'node-v24.22.0-linux-x64')
        with patch.object(recipe, 'vite_scope', return_value=scope), patch.object(recipe, 'packages', return_value={}):
            self.assertEqual(recipe.inspect(self.tools), {'node': None})
        self.install(scope)
        self.assertFalse(older_node.is_symlink())

    def test_owner_publisher_normalizes_writable_store_components_and_preserves_real_runtime(self):
        scope = self.scope('beast-modes')
        self.install(scope)
        node = scope / 'js_runtime/node' / self.version
        inode = node.stat().st_ino
        for component in (scope, scope / 'js_runtime', node.parent):
            component.chmod(0o775)
        payload = self.seed_manager(scope)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [payload]})[0], 'invalid')
        self.install(scope)
        self.assertEqual(node.stat().st_ino, inode)
        for component in (scope, scope / 'js_runtime', node.parent):
            self.assertEqual(component.stat().st_mode & 0o777, 0o755)
        self.assertEqual(result('tools.inspect-vp-store', {'environments': [payload]}),
                         ('inspected', {'installed': [True]}))
