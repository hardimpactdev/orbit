"""The history import keeps its original baseline without pinning later PRs to it."""
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

loader = importlib.machinery.SourceFileLoader('ci_build_plan_import', str(Path(__file__).resolve().parents[1] / 'bin/ci-build-plan'))
spec = importlib.util.spec_from_loader(loader.name, loader)
plan = importlib.util.module_from_spec(spec)
loader.exec_module(plan)


class ImportBaseTest(unittest.TestCase):
    def test_import_uses_retained_source_and_later_pr_uses_orbit_base(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)

            def git(*args):
                return subprocess.check_output(['git', '-c', 'user.name=Fixture', '-c',
                                                'user.email=fixture@example.com', *args], cwd=root,
                                               stderr=subprocess.DEVNULL, timeout=60).decode().strip()

            git('init')
            (root / '.swamp.yaml').write_text('repoId: fixture\n')
            git('add', '.')
            git('commit', '-m', 'Original Swamp source')
            source = git('rev-parse', 'HEAD')
            git('checkout', '--orphan', 'orbit')
            git('rm', '-rf', '.')
            (root / 'README.md').write_text('Orbit\n')
            git('add', '.')
            git('commit', '-m', 'Orbit before import')
            orbit = git('rev-parse', 'HEAD')
            git('read-tree', '--prefix=swamp/', source)
            (root / 'swamp').mkdir()
            (root / 'swamp/import-source.json').write_text(json.dumps({'commit': source, 'shape_base': source}))
            git('add', 'swamp/import-source.json')
            imported = git('commit-tree', git('write-tree'), '-p', orbit, '-p', source, '-m', 'Import')
            git('reset', '--hard', imported)
            previous = Path.cwd()
            try:
                os.chdir(root)
                # Both the import PR and its first main push compare against the pre-import Orbit base.
                self.assertEqual(source, plan.swamp_comparison_base(orbit, imported))
                output = root / '.git/selector-output'
                result = json.loads(subprocess.check_output(
                    ['python3', loader.path, '--base', orbit, '--head', imported, '--swamp-base',
                     '--github-output', str(output)],
                    cwd=root, timeout=60))
                self.assertEqual(source, result['swamp_base_ref'])
                outputs = dict(line.split('=', 1) for line in output.read_text().splitlines())
                self.assertEqual(source, outputs['swamp_base_ref'])
                self.assertEqual(result['jobs'], json.loads(outputs['jobs']))
                (root / 'swamp/workflow.yaml').write_text('name: next\n')
                git('add', '.')
                git('commit', '-m', 'A later Swamp change')
                self.assertEqual(imported, plan.swamp_comparison_base(imported, 'HEAD'))
                result = json.loads(subprocess.check_output(
                    ['python3', loader.path, '--base', imported, '--swamp-base'],
                    cwd=root, timeout=60))
                self.assertEqual(imported, result['swamp_base_ref'])
                # Deleting the marker cannot pin an existing Swamp tree to the original import source.
                git('rm', 'swamp/.swamp.yaml')
                git('commit', '-m', 'Remove the Swamp marker')
                without_marker = git('rev-parse', 'HEAD')
                (root / 'swamp/workflow.yaml').write_text('name: changed\n')
                git('add', '.')
                git('commit', '-m', 'A change after marker removal')
                self.assertEqual(without_marker, plan.swamp_comparison_base(without_marker, 'HEAD'))
                result = json.loads(subprocess.check_output(
                    ['python3', loader.path, '--base', without_marker, '--swamp-base'],
                    cwd=root, timeout=60))
                self.assertEqual(without_marker, result['swamp_base_ref'])
                # Ordinary job scopes work with a shallow checkout; only the full-history shape scope resolves history.
                shallow = root / 'shallow'
                git('clone', '--depth=1', root.as_uri(), str(shallow))
                subprocess.run(['git', '-C', str(shallow), 'fetch', '--depth=1', 'origin', orbit],
                               check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
                missing = subprocess.run(['git', '-C', str(shallow), 'cat-file', '-e', source],
                                         stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=60)
                self.assertNotEqual(0, missing.returncode)
                result = json.loads(subprocess.check_output(['python3', loader.path, '--base', orbit],
                                                            cwd=shallow, timeout=60))
                self.assertTrue(result['jobs']['swamp'])
                self.assertNotIn('swamp_base_ref', result)
                (root / 'swamp/import-source.json').write_text(json.dumps({'commit': source, 'shape_base': 'f' * 40}))
                git('add', 'swamp/import-source.json')
                git('commit', '-m', 'Invalid import source')
                with self.assertRaises(subprocess.CalledProcessError):
                    plan.swamp_comparison_base(orbit, 'HEAD')
            finally:
                os.chdir(previous)


if __name__ == '__main__':
    unittest.main()
