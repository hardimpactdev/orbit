"""Base commands share extraction, not writable trees or unrelated project dependencies."""
import json
import runpy
import shlex
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace.py'))

# Runs in each command's private tree, and reports exactly what that command can read and write.
PROBE = '''
import json
from pathlib import Path
root = next(path for path in [Path.cwd(), *Path.cwd().parents] if (path / 'app.txt').exists())
if (root / '.git').exists():
    raise SystemExit(0)
files = sorted(str(path.relative_to(root)) for path in root.rglob('marker'))
print(json.dumps({'dependencies': files, 'values': [ (root / path).read_text() for path in files ],
                  'overlays': sorted(path.name for path in (root / 'tests').glob('probe-*.py')),
                  'source': (root / 'packages/sdk/source.txt').read_text(),
                  'shared': (root.parent / 'base').stat().st_ino if (root.parent / 'base').exists() else None,
                  'linked': (root / 'apps/cli/vendor/sdk/source.txt').read_text() if (root / 'apps/cli/vendor').exists() else None}))
for path in files:
    (root / path).write_text('changed by base command')
(root / 'packages/sdk/source.txt').write_text('changed by base command')
raise SystemExit(1)
'''


class TaskInstanceCheckDependenciesTest(SUPPORT['CheckHarness']):
    def put(self, path, value):
        target = self.checkout / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(value)

    def test_project_closures_keep_base_source_and_writes_private_between_commands(self):
        self.put('.gitignore', 'vendor/\nnode_modules/\nignored/\n')
        self.put('packages/sdk/source.txt', 'base sdk')
        self.put('apps/web/tools/generator/source.txt', 'workspace')
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Monorepo base')
        self.check['base'] = self.git('rev-parse', 'HEAD')
        self.put('packages/sdk/source.txt', 'candidate sdk')
        installed = ['vendor/marker', 'apps/cli/vendor/marker', 'packages/sdk/vendor/marker',
                     'apps/gateway/vendor/marker', 'apps/docs/vendor/marker', 'apps/web/node_modules/marker',
                     'apps/web/tools/generator/node_modules/marker']
        for path in installed:
            self.put(path, path)
        # Absolute Composer link, relative workspace link, transitive dependency and a local-package cycle.
        (self.checkout / 'apps/cli/vendor/sdk').symlink_to(self.checkout / 'packages/sdk', target_is_directory=True)
        (self.checkout / 'packages/sdk/vendor/cli').symlink_to('../../../apps/cli', target_is_directory=True)
        (self.checkout / 'apps/web/node_modules/generator').symlink_to('../tools/generator', target_is_directory=True)
        cases = [('apps/cli', ['apps/cli/vendor/marker', 'packages/sdk/vendor/marker', 'vendor/marker']),
                 ('apps/cli', ['apps/cli/vendor/marker', 'packages/sdk/vendor/marker', 'vendor/marker']),
                 ('apps/gateway', ['apps/gateway/vendor/marker', 'vendor/marker']),
                 ('apps/web/tools/generator', ['apps/web/node_modules/marker', 'apps/web/tools/generator/node_modules/marker', 'vendor/marker']),
                 ('.', ['vendor/marker'])]
        commands = []
        for index, (directory, _) in enumerate(cases):
            path = f'tests/probe-{index}.py'
            self.put(path, PROBE)
            commands.append({'type': 'command', 'directory': directory, 'fails_on_base': True, 'paths': [path],
                             'command': 'python3 -c ' + shlex.quote(PROBE)})
        self.check.update({'command': None, 'deliverables': commands})
        self.run_phase('check', self.ansible_intent())
        steps = self.evidence('check')['check']['steps']
        shared = set()
        for index, (_, expected) in enumerate(cases):
            self.assertEqual(steps[f'base-{index}']['outputs']['exitCode'], 1)
            self.assertEqual(steps[f'deliverable-{index}']['outputs']['exitCode'], 0)
            result = json.loads((self.folder / f'output/base-{index}').read_text())
            self.assertEqual(result['dependencies'], expected)
            self.assertEqual(result['values'], expected, 'a prior command changed installed dependencies')
            self.assertEqual(result['source'], 'base sdk', 'local package source must come from the base')
            self.assertEqual(result['overlays'], [f'probe-{index}.py'])
            self.assertEqual(result['linked'], 'base sdk' if index < 2 else None)
            shared.add(result['shared'])
        self.assertNotIn(None, shared)
        self.assertEqual(len(shared), 1, 'commands must share one extracted base')
        for path in installed:
            self.assertEqual((self.checkout / path).read_text(), path)
        self.assertEqual((self.checkout / 'packages/sdk/source.txt').read_text(), 'candidate sdk')
        self.assertFalse(self.scratch.exists())

    def test_ignored_linked_installations_are_present_and_writable_without_false_base_failures(self):
        with (self.checkout / '.gitignore').open('a') as stream:
            stream.write('.installed/\n')
        self.put('.installed/sdk/index.php', 'installed sdk')
        self.put('.installed/other/index.php', 'transitive sdk')
        self.put('.installed/tool', 'installed tool')
        (self.checkout / 'vendor/sdk').symlink_to(self.checkout / '.installed/sdk', target_is_directory=True)
        (self.checkout / 'vendor/tool').symlink_to(self.checkout / '.installed/tool')
        (self.checkout / '.installed/sdk/next').symlink_to('../other', target_is_directory=True)
        probe = '''
from pathlib import Path
paths = {'vendor/sdk/index.php': 'installed sdk', 'vendor/sdk/next/index.php': 'transitive sdk', 'vendor/tool': 'installed tool'}
for path, value in paths.items():
    assert Path(path).read_text() == value, path
    if not Path('.git').exists():
        Path(path).write_text('private write')
'''
        self.put('tests/installed.py', probe)
        command = {'type': 'command', 'directory': '.', 'fails_on_base': True, 'paths': ['tests/installed.py'],
                   'command': 'python3 tests/installed.py'}
        self.check.update({'command': None, 'deliverables': [command, command]})
        self.run_phase('check', self.ansible_intent())
        steps = self.evidence('check')['check']['steps']
        for index in range(2):
            self.assertEqual(steps[f'base-{index}']['outputs']['exitCode'], 0, 'missing dependencies are not bug reproduction')
            self.assertEqual(steps[f'deliverable-{index}']['outputs']['exitCode'], 0, 'a base command changed the candidate')
        self.assertEqual((self.checkout / '.installed/sdk/index.php').read_text(), 'installed sdk')
        self.assertEqual((self.checkout / '.installed/other/index.php').read_text(), 'transitive sdk')
        self.assertEqual((self.checkout / '.installed/tool').read_text(), 'installed tool')

    def test_external_dependency_link_refuses_and_cleans_up_without_running_commands(self):
        external = self.root / 'external'
        external.mkdir()
        (external / 'marker').write_text('untouched')
        (self.checkout / 'vendor/external').symlink_to(external, target_is_directory=True)
        self.assertIn('tasks.workspace_phase_failed', self.run_phase('check', self.ansible_intent(), ok=False))
        self.assertEqual((external / 'marker').read_text(), 'untouched')
        self.assertFalse(self.scratch.exists())


if __name__ == '__main__':
    unittest.main()
