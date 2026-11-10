"""Exercise the shipped read-only planner on actual filesystem links."""

import json
import os
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

    def plan(self):
        result = subprocess.run([sys.executable, '-I', str(PLANNER), str(self.root)], capture_output=True, text=True, check=True)
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
        targets = {'broken': 'missing', 'cycle': 'cycle', 'public/storage': '/missing/other-checkout/storage'}
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

    def test_does_not_inspect_dependency_or_git_contents_at_any_depth(self):
        for tree in ['vendor', 'node_modules', '.git', 'apps/web/vendor', 'apps/web/node_modules']:
            folder = self.root / tree
            folder.mkdir(parents=True)
            (folder / 'broken').symlink_to('missing')
            (folder / 'absolute').symlink_to(self.root / 'storage')
        self.assertEqual(self.plan(), {'rewrites': [], 'refused': []})


if __name__ == '__main__':
    unittest.main()
