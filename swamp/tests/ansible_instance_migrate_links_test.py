"""Exercise the shipped read-only planner on actual filesystem links."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

PLANNER = Path(__file__).resolve().parents[1] / 'ansible/instance_migrate_links.py'


class MigrationLinksTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'checkout'
        (self.root / 'public').mkdir(parents=True)
        (self.root / 'storage/app/public').mkdir(parents=True)

    def plan(self, root=None, *args):
        result = subprocess.run([sys.executable, '-I', str(PLANNER), str(root or self.root), *args], capture_output=True, text=True, check=True)
        return json.loads(result.stdout)

    def test_rewrites_absolute_storage_and_local_package_links_without_changing_source(self):
        target = self.root / 'storage/app/public'
        (self.root / 'public/storage').symlink_to(target)
        (self.root / 'local package').symlink_to(target)
        self.assertEqual(self.plan(), {'rewrites': [
            {'path': 'local package', 'target': 'storage/app/public'},
            {'path': 'public/storage', 'target': '../storage/app/public'},
        ], 'refused': []})
        self.assertEqual((self.root / 'public/storage').readlink(), target)
        self.assertEqual((self.root / 'local package').readlink(), target)

    def test_keeps_relative_targets_exactly(self):
        target = '../storage/./app/public'
        (self.root / 'public/storage').symlink_to(target)
        self.assertEqual(self.plan(), {'rewrites': [], 'refused': []})
        self.assertEqual(os.readlink(self.root / 'public/storage'), target)

    def test_refuses_every_external_path_and_target_including_relative_and_prefix_siblings(self):
        outside = self.root.with_name('checkout-other')
        outside.mkdir()
        targets = {'absolute': str(outside), 'relative': '../checkout-other'}
        for name, target in targets.items():
            (self.root / name).symlink_to(target)
        self.assertEqual(self.plan()['refused'], [
            {'path': name, 'target': target, 'reason': 'outside checkout'} for name, target in targets.items()
        ])
        for name, target in targets.items():
            self.assertEqual(str((self.root / name).readlink()), target)

    def test_refuses_broken_links_and_cycles_including_missing_external_targets(self):
        targets = {'broken': 'missing', 'cycle': 'cycle', 'public/storage': str(self.root / 'missing/storage')}
        for name, target in targets.items():
            (self.root / name).symlink_to(target)
        self.assertEqual(self.plan()['refused'], [
            {'path': name, 'target': target, 'reason': 'broken'} for name, target in targets.items()
        ])

    def test_checks_resolved_destination_and_does_not_follow_directory_links(self):
        outside = self.root.parent / 'outside'
        outside.mkdir()
        (outside / 'uninspected').symlink_to('missing')
        (self.root / 'escape').symlink_to(outside)
        (self.root / 'indirect').symlink_to(self.root / 'escape')
        self.assertEqual([item['path'] for item in self.plan()['refused']], ['escape', 'indirect'])

    def test_does_not_inspect_installed_package_or_git_contents(self):
        for app in ['', 'apps/web']:
            folder = self.root / app
            folder.mkdir(parents=True, exist_ok=True)
            (folder / 'composer.json').write_text('{}')
            (folder / 'package.json').write_text('{}')
            for tree in ['vendor/acme/package', 'node_modules/package', '.git']:
                package = folder / tree
                package.mkdir(parents=True)
                (package / 'broken').symlink_to('missing')
                (package / 'absolute').symlink_to(self.root / 'storage')
        self.assertEqual(self.plan(), {'rewrites': [], 'refused': []})

    def test_checks_composer_and_npm_package_boundary_links_but_not_named_ordinary_directories(self):
        (self.root / 'composer.json').write_text('{}')
        (self.root / 'package.json').write_text('{}')
        paths = ['vendor/acme/package', 'vendor/composer/package', 'node_modules/package', 'node_modules/@acme/package', 'resources/views/vendor/template']
        for name in paths:
            link = self.root / name
            link.parent.mkdir(parents=True, exist_ok=True)
            link.symlink_to(self.root / 'storage')
        result = self.plan()
        self.assertEqual(result['refused'], [])
        self.assertEqual({item['path']: item['target'] for item in result['rewrites']}, {
            'vendor/acme/package': '../../storage', 'node_modules/package': '../storage',
            'vendor/composer/package': '../../storage',
            'node_modules/@acme/package': '../../storage', 'resources/views/vendor/template': '../../../storage',
        })
        (self.root / 'vendor/acme/package').unlink()
        (self.root / 'vendor/acme/package').symlink_to(self.root.parent)
        (self.root / 'node_modules/package').unlink()
        (self.root / 'node_modules/package').symlink_to('missing')
        self.assertEqual({item['path']: item['reason'] for item in self.plan()['refused']}, {
            'vendor/acme/package': 'outside checkout', 'node_modules/package': 'broken',
        })

    def test_uses_the_copied_tree_without_reading_the_old_checkout_and_is_idempotent(self):
        (self.root / 'public/storage').symlink_to(self.root / 'storage/app/public')
        copied = self.root.parent / 'workspace'
        shutil.copytree(self.root, copied, symlinks=True)
        (copied / 'new-data').mkdir()
        (copied / 'public/storage').unlink()
        (copied / 'public/storage').symlink_to(self.root / 'new-data')
        shutil.rmtree(self.root)
        result = self.plan(copied, '--old-checkout', str(self.root), '--rewrite')
        self.assertEqual(result, {'rewrites': [{'path': 'public/storage', 'target': '../new-data'}], 'refused': []})
        self.assertEqual(os.readlink(copied / 'public/storage'), '../new-data')
        self.assertEqual(self.plan(copied, '--old-checkout', str(self.root), '--rewrite'), {'rewrites': [], 'refused': []})

    def test_refuses_missing_copied_data_even_if_the_source_target_still_exists_without_partial_rewrites(self):
        (self.root / 'public/storage').symlink_to(self.root / 'storage/app/public')
        (self.root / 'valid').symlink_to(self.root / 'public')
        copied = self.root.parent / 'workspace'
        shutil.copytree(self.root, copied, symlinks=True)
        shutil.rmtree(copied / 'storage')
        result = self.plan(copied, '--old-checkout', str(self.root), '--rewrite')
        self.assertEqual(result['refused'], [{'path': 'public/storage', 'target': str(self.root / 'storage/app/public'), 'reason': 'broken'}])
        self.assertEqual(os.readlink(copied / 'valid'), str(self.root / 'public'))
        self.assertEqual(os.readlink(self.root / 'public/storage'), str(self.root / 'storage/app/public'))

    def test_preserves_non_utf8_bytes_in_link_names_and_targets(self):
        root = os.fsencode(self.root)
        target = root + b'/data-\xff'
        name = root + b'/public/link-\xfe'
        os.mkdir(target)
        os.symlink(target, name)
        relative = root + b'/public/relative-\xff'
        os.symlink(b'../data-\xff', relative)
        result = self.plan(None, '--rewrite')
        self.assertEqual(result['refused'], [])
        self.assertEqual(os.readlink(name), b'../data-\xff')
        self.assertEqual(os.readlink(relative), b'../data-\xff')

    def test_reports_non_utf8_refusals_as_byte_escapes_without_json_surrogates(self):
        root = os.fsencode(self.root)
        os.symlink(root + b'/missing-\xfe', root + b'/public/bad-\xff')
        self.assertEqual(self.plan()['refused'], [{
            'path': 'public/bad-\\xff', 'target': str(self.root) + '/missing-\\xfe', 'reason': 'broken',
        }])

    def test_resolves_parent_components_after_following_directory_links(self):
        (self.root / 'storage/child').mkdir()
        (self.root / 'storage/file').write_text('data')
        (self.root / 'directory').symlink_to('storage/child')
        (self.root / 'public/storage').symlink_to(self.root / 'directory/../file')
        self.assertEqual(self.plan()['rewrites'], [{'path': 'public/storage', 'target': '../storage/file'}])


if __name__ == '__main__':
    unittest.main()
