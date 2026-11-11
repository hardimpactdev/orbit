"""The shape gate finds the tools that the task image holds and never downloads them."""
import importlib.machinery
import importlib.util
import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from threading import Event
from unittest import mock

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'


def load_gate():
    loader = importlib.machinery.SourceFileLoader('swamp_shape_gate', str(ROOT / 'bin/swamp-shape-gate'))
    spec = importlib.util.spec_from_loader(loader.name, loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class ShapeGateToolsTest(unittest.TestCase):
    def setUp(self):
        self.gate = load_gate()
        self.folder = tempfile.TemporaryDirectory()
        self.addCleanup(self.folder.cleanup)
        self.system = Path(self.folder.name) / 'system'
        self.system.mkdir()

    def build(self, tools, name=None):
        venv = tools / (name or self.gate.venv_name())
        (venv / 'bin').mkdir(parents=True)
        (venv / '.complete').write_text('')
        return venv

    def test_uses_the_system_tools_only_when_built_for_these_pins(self):
        with mock.patch.object(self.gate, 'SYSTEM_TOOLS', self.system):
            self.assertNotEqual(self.gate.default_tools(), self.system)
            self.build(self.system, 'venv-0000000000000000')
            self.assertNotEqual(self.gate.default_tools(), self.system, 'a venv of other pins is not warm')
            self.build(self.system)
            self.assertEqual(self.gate.default_tools(), self.system)

    def test_a_built_venv_needs_no_write_access_and_no_install(self):
        venv = self.build(self.system)
        self.system.chmod(stat.S_IRUSR | stat.S_IXUSR)
        self.addCleanup(self.system.chmod, stat.S_IRWXU)
        with mock.patch.object(subprocess, 'run', side_effect=AssertionError('the gate downloaded')):
            self.assertEqual(self.gate.tool_path(self.system), venv / 'bin')

    @unittest.skipUnless(shutil.which('ansible-playbook'), 'ansible-playbook is not installed')
    def test_the_playbook_names_the_tools_as_the_gate_does(self):
        # Run the playbook's own naming task on copies of the pin files, so the Jinja is the one that ships.
        recipe = yaml.safe_load((ANSIBLE / 'tasks/task-vm-recipe.yml').read_text())
        naming = next(task for task in recipe if task['name'] == 'Name the shape gate tools')
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            for name in ('shape-gate-requirements.txt', 'requirements.txt', 'requirements.yml'):
                shutil.copy(ANSIBLE / name, folder / name)
            report = folder / 'names'
            play = [{'hosts': 'localhost', 'gather_facts': False, 'tasks': [naming, {
                'ansible.builtin.copy': {'dest': str(report), 'content': '{{ shape_venv }}\n{{ shape_collections }}\n'}}]}]
            (folder / 'names.yml').write_text(yaml.safe_dump(play))
            environment = {**os.environ, 'ANSIBLE_HOME': str(folder / 'home'), 'ANSIBLE_LOCAL_TEMP': str(folder / 'tmp')}
            subprocess.run(['ansible-playbook', '-i', 'localhost,', '-c', 'local', str(folder / 'names.yml')],
                           check=True, capture_output=True, env=environment)
            venv, collections = report.read_text().split()
        self.assertEqual(venv, f'/opt/orbit-shape-gate/{self.gate.venv_name()}')
        self.assertEqual(collections, f'/opt/orbit-shape-gate/{self.gate.collections_path(self.gate.SYSTEM_TOOLS).name}')

    def test_the_playbook_builds_the_tools_where_the_gate_looks(self):
        tasks = yaml.safe_load((ANSIBLE / 'tasks/task-vm-shape-gate.yml').read_text())
        pip = next(task for task in tasks if task['name'] == 'Build the shape gate venv')['ansible.builtin.pip']
        self.assertEqual(pip['virtualenv'], '{{ shape_venv }}')
        self.assertEqual(pip['requirements'], '/opt/orbit-shape-gate/shape-gate-requirements.txt')
        self.assertEqual(str(self.gate.SYSTEM_TOOLS), '/opt/orbit-shape-gate')
        self.assertNotRegex((ANSIBLE / 'tasks/task-vm-shape-gate.yml').read_text(), r'\w==\d', 'the playbook names no pin')

    def test_deno_ansible_fixtures_receive_the_installed_pinned_collections(self):
        collections = self.system / 'collections'
        steps = self.gate.plan('HEAD', self.system, self.system, collections)
        deno_tests = [step for step in steps if step.argv[:2] == ['deno', 'test'] and step.group == 'shape']
        self.assertTrue(deno_tests)
        for step in deno_tests:
            self.assertEqual(step.env.get('ANSIBLE_COLLECTIONS_PATH'), str(collections), step.name)

    def test_every_deno_test_file_runs_once_with_explicit_instance_host_metadata(self):
        steps = self.gate.plan('HEAD', self.system, self.system, self.system / 'collections')
        files = sorted(str(path.relative_to(ROOT / 'swamp')) for path in (ROOT / 'swamp/tests').glob('*_test.ts'))
        commands = [step for step in steps if step.group == 'shape' and step.argv[:3] == ['deno', 'test', '-A'] and step.argv[-1].startswith('tests/')]
        planned = [file for step in commands for file in step.argv[3:]]
        elsewhere = [step.argv[-1] for step in steps if step.group == 'validate' and step.argv[-1].startswith('tests/')]
        self.assertTrue(set(elsewhere) <= set(self.gate.DENO_TESTS_ELSEWHERE))
        self.assertTrue(set(self.gate.DENO_TESTS_ELSEWHERE) <= set(files), 'each file left out exists')
        self.assertEqual(sorted(planned), [file for file in files if file not in self.gate.DENO_TESTS_ELSEWHERE])
        self.assertIn('tests/ansible_instance_host_create_test.ts', planned)
        self.assertTrue(all(step.parallel for step in commands))
        instance = [step for step in commands if any('ansible_instance_host' in arg for arg in step.argv)]
        self.assertEqual(len(instance), 14)
        self.assertTrue(all(len(step.argv[3:]) == 1 for step in instance))
        self.assertTrue(all(step.fixture_seconds == 720 for step in instance))
        self.assertTrue(all('--parallel' not in step.argv for step in instance))

    def test_each_instance_command_receives_one_deadline_at_launch(self):
        steps = self.gate.plan('HEAD', self.system, self.system, self.system / 'collections')
        commands = [step for step in steps if step.fixture_seconds is not None]
        self.assertGreater(len(commands), 14)
        for step in commands:
            with mock.patch.object(self.gate.time, 'time', return_value=1_000_000.125), \
                    mock.patch.object(self.gate.subprocess, 'run') as run:
                run.return_value.returncode = 0
                self.gate.run_step(step, {'ORBIT_INSTANCE_HOST_DEADLINE_MS': 'expired'}, False)
                self.assertEqual(run.call_args.kwargs['env']['ORBIT_INSTANCE_HOST_DEADLINE_MS'], '1000720125')

    def test_a_completed_command_is_reported_while_its_neighbor_is_still_running(self):
        blocked, release, reported = Event(), Event(), Event()
        steps = [self.gate.Step('shape', name, ['unused'], parallel=True) for name in ('slow', 'fast')]

        def run(step, *_args):
            if step.name == 'slow':
                blocked.set()
                self.assertTrue(release.wait(5), 'test did not release the slow command')
            return (step, 'passed', 0.0, 'exit 0'), step.name + ' output\n'

        def printed(message, **_kwargs):
            if message == '==> fast: passed':
                reported.set()

        with mock.patch.object(self.gate, 'run_step', side_effect=run), \
                mock.patch('builtins.print', side_effect=printed), ThreadPoolExecutor(max_workers=1) as executor:
            result = executor.submit(self.gate.execute, steps, {}, False, 2)
            try:
                self.assertTrue(blocked.wait(1), 'slow command did not start')
                self.assertTrue(reported.wait(1), 'completed command output is held behind the slow command')
            finally:
                release.set()
            self.assertEqual([item[0].name for item in result.result(timeout=2)], ['slow', 'fast'])


if __name__ == '__main__':
    unittest.main()
