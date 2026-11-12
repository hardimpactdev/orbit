"""Exercise the shipped read-only planner on actual filesystem links."""

import json
import os
import runpy
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

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

    def test_refuses_file_targets_with_directory_suffixes_at_every_hop_in_source_and_copy(self):
        (self.root / 'storage/file').write_text('data')
        targets = {}
        for kind, prefix in [('absolute', str(self.root)), ('relative', '..')]:
            for label, suffix in [('slash', '/'), ('dot', '/.')]:
                name = f'public/{kind}-{label}'
                targets[name] = f'{prefix}/storage/file{suffix}'
                targets[f'{name}-indirect'] = str(self.root / name)
        for name, target in targets.items():
            (self.root / name).symlink_to(target)
        expected = [{'path': name, 'target': target, 'reason': 'broken'} for name, target in sorted(targets.items())]
        self.assertEqual(self.plan(None, '--rewrite'), {'rewrites': [], 'refused': expected})
        for name, target in targets.items():
            self.assertEqual(os.readlink(self.root / name), target)
        copied = self.root.parent / 'workspace'
        shutil.copytree(self.root, copied, symlinks=True)
        shutil.rmtree(self.root)
        self.assertEqual(self.plan(copied, '--old-checkout', str(self.root), '--rewrite'), {'rewrites': [], 'refused': expected})
        for name, target in targets.items():
            self.assertEqual(os.readlink(copied / name), target)

    def test_allows_directory_targets_with_directory_suffixes(self):
        for kind, prefix in [('absolute', str(self.root)), ('relative', '..')]:
            for label, suffix in [('slash', '/'), ('dot', '/.')]:
                (self.root / f'public/{kind}-{label}').symlink_to(f'{prefix}/storage{suffix}')
        self.assertEqual(self.plan(None, '--rewrite'), {'rewrites': [
            {'path': f'public/absolute-{label}', 'target': '../storage'} for label in ['dot', 'slash']
        ], 'refused': []})
        for label, suffix in [('slash', '/'), ('dot', '/.')]:
            self.assertEqual(os.readlink(self.root / f'public/relative-{label}'), f'../storage{suffix}')

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

    def git(self, *args):
        return subprocess.check_output(
            ['git', '-C', str(self.root), *args],
            env={**os.environ, 'GIT_AUTHOR_NAME': 'Fixture', 'GIT_AUTHOR_EMAIL': 'fixture@example.test',
                 'GIT_COMMITTER_NAME': 'Fixture', 'GIT_COMMITTER_EMAIL': 'fixture@example.test'})

    def initialize_git(self):
        self.git('init', '--quiet')
        (self.root / 'storage/app/.gitignore').write_text('*\n!.gitignore\n!public/\n')
        (self.root / '.gitignore').write_text('ignored/\n')
        (self.root / 'public/.gitkeep').touch()
        self.git('add', '.')
        self.git('commit', '--quiet', '-m', 'fixture')

    def test_archive_carries_empty_untracked_and_nested_ignored_directories_with_exact_modes(self):
        self.initialize_git()
        target = self.root / 'storage/app/public'
        target.chmod(0o3775)
        (self.root / 'public/storage').symlink_to(target)
        nested = self.root / 'ignored/empty/nested'
        nested.mkdir(parents=True)
        nested.chmod(0o2711)
        byte_directory = os.fsencode(self.root) + b'/empty-\xff'
        os.mkdir(byte_directory, 0o755)
        ignored = self.root / 'ignored/cutover-release-fixture/migrations'
        ignored.mkdir(parents=True)
        tracked = self.root / 'database/migrations'
        tracked.mkdir(parents=True)
        for index in range(3):
            target_file = tracked / f'file-{index}.php'
            target_file.write_text('tracked migration')
            (ignored / target_file.name).symlink_to(target_file)
        self.git('add', 'database')
        self.git('commit', '--quiet', '-m', 'migration targets')
        for index in range(12):
            (self.root / f'runtime-empty/slot-{index}').mkdir(parents=True)
        self.assertEqual(self.plan(None, '--predict-copy')['refused'], [])
        prefix = str(self.root.parent / 'overlay')
        self.plan(None, '--archive-prefix', prefix)
        copied = self.root.parent / 'workspace'
        subprocess.run(['git', 'clone', '--quiet', str(self.root), str(copied)], check=True)
        subprocess.run(['tar', '-xf', prefix + '.tar', '--no-same-owner', '-C', str(copied)], check=True)
        self.assertEqual(self.plan(copied, '--old-checkout', str(self.root), '--rewrite')['refused'], [])
        subprocess.run(['chmod', '-R', 'g+rwX,o-rwx', str(copied)], check=True)
        subprocess.run([sys.executable, str(PLANNER), str(copied), '--restore-directories', prefix + '.directories'], check=True)
        self.assertEqual(stat.S_IMODE((copied / 'storage/app/public').stat().st_mode), 0o3775)
        self.assertEqual(stat.S_IMODE((copied / 'ignored/empty/nested').stat().st_mode), 0o2711)
        self.assertTrue(os.path.isdir(os.fsencode(copied) + b'/empty-\xff'))
        self.assertEqual((copied / 'public/storage').resolve(strict=True), copied / 'storage/app/public')
        for index in range(12):
            self.assertTrue((copied / f'runtime-empty/slot-{index}').is_dir())
        for index in range(3):
            link = copied / 'ignored/cutover-release-fixture/migrations' / f'file-{index}.php'
            self.assertEqual(link.resolve(strict=True), copied / 'database/migrations' / link.name)
            self.assertFalse(link.readlink().is_absolute())
            self.assertEqual((ignored / link.name).readlink(), tracked / link.name)
        self.assertEqual((self.root / 'public/storage').readlink(), target)
        self.assertEqual(stat.S_IMODE(target.stat().st_mode), 0o3775)

    def test_prediction_keeps_existing_validation_for_links_into_generated_git_metadata(self):
        self.initialize_git()
        (self.root / 'revision').symlink_to('.git/HEAD')
        self.assertEqual(self.plan(None, '--predict-copy')['refused'], [])
        prefix = str(self.root.parent / 'overlay')
        self.plan(None, '--archive-prefix', prefix)
        copied = self.root.parent / 'workspace'
        subprocess.run(['git', 'clone', '--quiet', str(self.root), str(copied)], check=True)
        subprocess.run(['tar', '-xf', prefix + '.tar', '--no-same-owner', '-C', str(copied)], check=True)
        self.assertEqual(self.plan(copied)['refused'], [])
        self.assertEqual((copied / 'revision').resolve(strict=True), copied / '.git/HEAD')

    def test_added_unreadable_directory_can_be_inspected_before_exact_mode_restoration(self):
        self.initialize_git()
        sealed = self.root / 'sealed'
        sealed.mkdir()
        # The production source scan is root. Snapshot its directory enumeration before restricting access.
        walked = list(os.walk(self.root))
        sealed.chmod(0o111)
        self.addCleanup(sealed.chmod, 0o755)
        module = runpy.run_path(str(PLANNER))
        with patch('os.walk', return_value=iter(walked)):
            layout = module['CopyLayout'](self.root)
        prefix = str(self.root.parent / 'overlay')
        layout.archive(prefix)
        copied = self.root.parent / 'workspace'
        subprocess.run(['git', 'clone', '--quiet', str(self.root), str(copied)], check=True)
        subprocess.run(['tar', '-xf', prefix + '.tar', '--no-same-owner', '-C', str(copied)], check=True)
        self.assertEqual(self.plan(copied)['refused'], [])
        module['restore_directories'](copied, prefix + '.directories')
        self.assertEqual(stat.S_IMODE((copied / 'sealed').stat().st_mode), 0o111)
        (copied / 'sealed').chmod(0o755)

    def test_prediction_refuses_source_valid_links_to_omitted_submodule_contents_and_missing_targets(self):
        self.initialize_git()
        commit = self.git('rev-parse', 'HEAD').decode().strip()
        self.git('update-index', '--add', '--cacheinfo', '160000', commit, 'submodule')
        self.git('commit', '--quiet', '-m', 'gitlink')
        (self.root / 'submodule/empty/nested').mkdir(parents=True)
        (self.root / 'submodule/file').write_text('not copied')
        (self.root / '.git/empty-marker').mkdir()
        (self.root / 'public/submodule').symlink_to('../submodule/file')
        (self.root / 'public/missing').symlink_to('../missing')
        (self.root / 'ignored/migrations').mkdir(parents=True)
        (self.root / 'ignored/migrations/missing').symlink_to(self.root / 'database/missing')
        self.assertEqual([item['path'] for item in self.plan()['refused']], ['ignored/migrations/missing', 'public/missing'])
        result = self.plan(None, '--predict-copy')
        self.assertEqual([item['path'] for item in result['refused']], ['ignored/migrations/missing', 'public/missing', 'public/submodule'])
        self.assertTrue(all(item['reason'] == 'broken' for item in result['refused']))
        prefix = str(self.root.parent / 'overlay')
        self.plan(None, '--archive-prefix', prefix)
        with tarfile.open(prefix + '.tar') as archive:
            names = archive.getnames()
        self.assertFalse(any(name == '.git' or name.startswith('.git/') for name in names))
        self.assertFalse(any(name.startswith('submodule/') for name in names))
        self.assertEqual((self.root / 'submodule/file').read_text(), 'not copied')


if __name__ == '__main__':
    unittest.main()
