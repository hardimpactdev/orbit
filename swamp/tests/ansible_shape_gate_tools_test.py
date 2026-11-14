"""The shape gate finds the tools that the task image holds and never downloads them."""
import importlib.machinery
import importlib.util
import io
import json
import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from concurrent.futures import ThreadPoolExecutor
from contextlib import redirect_stderr, redirect_stdout
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

    def test_a_first_build_keeps_standard_output_free(self):
        # The proof runner reads standard output of its fleet preparation, which builds these tools, as JSON.
        cache = Path(self.folder.name) / 'cache'
        stdout, stderr = io.StringIO(), io.StringIO()
        def build(argv, **_kwargs):
            if argv[1:3] == ['-m', 'venv']:
                Path(argv[3]).mkdir(parents=True)

        with mock.patch.object(subprocess, 'run', side_effect=build) as run, redirect_stdout(stdout), redirect_stderr(stderr):
            self.assertEqual(self.gate.tool_path(cache), cache / self.gate.venv_name() / 'bin')
        self.assertEqual(run.call_count, 2)
        self.assertEqual(stdout.getvalue(), '')
        self.assertIn('Installing the pinned shape tools', stderr.getvalue())

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
        deno_tests = [step for step in steps if step.argv[:2] == ['deno', 'test']
                      and step.group == 'shape' and step.parallel]
        self.assertTrue(deno_tests)
        for step in deno_tests:
            self.assertEqual(step.env.get('ANSIBLE_COLLECTIONS_PATH'), str(collections), step.name)

    def test_every_deno_test_file_runs_once_with_three_early_instance_host_groups(self):
        steps = self.gate.plan('HEAD', self.system, self.system, self.system / 'collections')
        files = sorted(str(path.relative_to(ROOT / 'swamp')) for path in (ROOT / 'swamp/tests').glob('*_test.ts'))
        commands = [step for step in steps if step.group == 'shape' and step.argv[:3] == ['deno', 'test', '-A'] and step.argv[-1].startswith('tests/')]
        planned = [file for step in commands for file in step.argv[3:]]
        controls = [step for step in steps if step.group == 'shape' and step.argv[:2] == ['deno', 'test'] and not step.parallel]
        control_files = [step.argv[-1] for step in controls]
        self.assertEqual(control_files, ['tests/writes_declared_test.ts'])
        for step in controls:
            self.assertEqual(step.argv, ['deno', 'test', '--allow-read', step.argv[-1]])
            self.assertNotIn('ANSIBLE_COLLECTIONS_PATH', step.env)
        elsewhere = [step.argv[-1] for step in steps if step.group == 'validate' and step.argv[-1].startswith('tests/')]
        self.assertTrue(set(elsewhere) <= set(self.gate.DENO_TESTS_ELSEWHERE))
        self.assertTrue(set(self.gate.DENO_TESTS_ELSEWHERE) <= set(files), 'each file left out exists')
        self.assertEqual(sorted(planned + control_files), [file for file in files if file not in self.gate.DENO_TESTS_ELSEWHERE])
        self.assertIn('tests/ansible_instance_host_test.ts', planned)
        self.assertTrue(all(step.parallel for step in commands))
        instance = [step for step in commands if any('ansible_instance_host' in arg for arg in step.argv)]
        self.assertEqual([step.argv[3:] for step in instance], [
            ['tests/ansible_instance_host_updates_test.ts'],
            ['tests/ansible_instance_host_test.ts', 'tests/ansible_instance_host_image_test.ts', 'tests/ansible_instance_host_runtime_test.ts'],
            ['tests/ansible_instance_host_acl_test.ts'],
            ['tests/ansible_instance_host_browser_test.ts'],
        ], 'ACL and browser fixtures own a command budget without changing the other host groups')
        self.assertEqual([step.argv[3:] for step in instance], [list(group) for group in self.gate.INSTANCE_HOST_TEST_GROUPS])
        self.assertEqual(steps[2:6], instance, 'long fixtures start before the other independent tests')
        self.assertTrue(all('--parallel' not in step.argv for step in instance), 'files inside a group run serially')

    def test_each_instance_command_receives_one_deadline_at_launch(self):
        steps = self.gate.plan('HEAD', self.system, self.system, self.system / 'collections')
        commands = [step for step in steps if tuple(step.argv[3:]) in self.gate.INSTANCE_HOST_TEST_GROUPS]
        self.assertEqual(len(commands), 4)
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


# A workspace whose gate names the tools in a test folder and whose `tool_path` builds a venv without pip. Its galaxy and the system's
# dpkg-query and apt-get are stubs that record their arguments and print to standard output, as real programs do.
STUB_GATE = """
import os
from pathlib import Path
SYSTEM_TOOLS = Path(os.environ['STUB_TOOLS'])
def venv_name():
    return 'venv-aaaaaaaaaaaaaaaa'
def collections_path(tools):
    return tools / 'collections-bbbbbbbbbbbbbbbb'
def tool_path(tools):
    venv = tools / venv_name()
    (venv / 'bin').mkdir(parents=True, exist_ok=True)
    galaxy = venv / 'bin/ansible-galaxy'
    galaxy.write_text(Path(os.environ['STUB_BIN'], 'galaxy').read_text())
    galaxy.chmod(0o755)
    print('pip writes to standard output')
    if os.environ.get('STUB_VENV_FAIL'):
        raise OSError('no ensurepip')
    (venv / '.complete').write_text('')
    return venv / 'bin'
"""
STUB = """#!/bin/bash
echo "$0 $*" >> "$STUB_LOG"
"""
NOISE = 'echo "$0 prints to standard output"\n'


class InstanceShapeToolsTest(unittest.TestCase):
    """instance_shape_tools.py keeps the gate's tools in a nested-fleet default, named by the workspace's own gate, and never fails."""

    def setUp(self):
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.root = Path(folder.name)
        self.workspace, self.tools, self.bin = self.root / 'workspace', self.root / 'opt/orbit-shape-gate', self.root / 'bin'
        (self.workspace / 'bin').mkdir(parents=True)
        (self.workspace / 'bin/swamp-shape-gate').write_text(STUB_GATE)
        self.bin.mkdir()
        self.log = self.root / 'log'
        self.log.write_text('')
        self.stub('apt-get', NOISE)
        self.stub('dpkg-query', "printf 'install ok installed'\n")
        self.stub('galaxy', NOISE + 'mkdir -p "${@: -1}/ansible_collections"\n')

    def stub(self, name, body):
        path = self.bin / name
        path.write_text(STUB + body)
        path.chmod(0o755)

    def keep(self, **environment):
        run = subprocess.run(['python3', '-I', '-c', (ANSIBLE / 'instance_shape_tools.py').read_text(), str(self.workspace)],
                             capture_output=True, text=True, check=True,
                             env={**os.environ, 'PATH': f'{self.bin}:{os.environ["PATH"]}', 'STUB_TOOLS': str(self.tools),
                                  'STUB_BIN': str(self.bin), 'STUB_LOG': str(self.log), **environment})
        # Standard output is the one result line; everything the programs print is on standard error.
        self.assertEqual(len(run.stdout.splitlines()), 1, run.stdout)
        return json.loads(run.stdout), self.log.read_text().splitlines()

    def test_missing_tools_are_built_where_the_gate_looks_and_other_pins_go(self):
        (self.tools / 'venv-0000000000000000').mkdir(parents=True)
        (self.tools / 'collections-0000000000000000').mkdir()
        (self.tools / 'lock').write_text('')
        result, calls = self.keep()
        self.assertEqual(result, {'tools': 'built', 'reason': 'missing', 'venv': 'venv-aaaaaaaaaaaaaaaa', 'collections': 'collections-bbbbbbbbbbbbbbbb'})
        self.assertEqual(sorted(path.name for path in self.tools.iterdir()), ['collections-bbbbbbbbbbbbbbbb', 'lock', 'venv-aaaaaaaaaaaaaaaa'])
        self.assertTrue((self.tools / 'collections-bbbbbbbbbbbbbbbb/ansible_collections').is_dir())
        galaxy = [call for call in calls if call.startswith(str(self.tools))]
        self.assertEqual(galaxy, [f'{self.tools}/venv-aaaaaaaaaaaaaaaa/bin/ansible-galaxy collection install -r '
                                  f'{self.workspace}/swamp/ansible/requirements.yml -p {self.tools}/collections-bbbbbbbbbbbbbbbb.partial'])
        self.assertFalse([call for call in calls if 'apt-get' in call], 'python3-venv was installed, so apt is not called')
        # A reader is `agent`: the folders are readable by others.
        self.assertEqual(stat.S_IMODE((self.tools / 'collections-bbbbbbbbbbbbbbbb').stat().st_mode) & 0o005, 0o005)

    def test_complete_tools_are_only_read_and_a_cleanup_left_over_is_done(self):
        self.keep()
        self.log.write_text('')
        result, calls = self.keep(STUB_VENV_FAIL='1')
        self.assertEqual((result['tools'], result['reason']), ('present', 'current'))
        self.assertEqual([call for call in calls if not call.startswith(f'{self.bin}/dpkg-query')], [])
        # A cleanup that failed in an earlier refresh: the tools of other pins go now, so they do not stay in every seed.
        (self.tools / 'venv-0000000000000000').mkdir()
        self.assertEqual(self.keep(STUB_VENV_FAIL='1')[0]['tools'], 'present')
        self.assertEqual(sorted(path.name for path in self.tools.iterdir()), ['collections-bbbbbbbbbbbbbbbb', 'venv-aaaaaaaaaaaaaaaa'])

    def test_complete_collections_are_kept_when_only_the_venv_is_new(self):
        (self.tools / 'collections-bbbbbbbbbbbbbbbb/ansible_collections').mkdir(parents=True)
        result, calls = self.keep()
        self.assertEqual(result['tools'], 'built')
        self.assertFalse([call for call in calls if call.startswith(str(self.tools))], 'Galaxy was called for complete collections')
        self.assertTrue((self.tools / 'venv-aaaaaaaaaaaaaaaa/.complete').is_file())

    def test_a_missing_python3_venv_is_installed_through_apt(self):
        self.stub('dpkg-query', 'exit 1\n')
        result, calls = self.keep()
        self.assertEqual(result['tools'], 'built')
        apt = [call.split(' ', 1)[1] for call in calls if call.startswith(f'{self.bin}/apt-get')]
        self.assertEqual(apt, ['-o DPkg::Lock::Timeout=120 update',
                               '-o DPkg::Lock::Timeout=120 install --yes --no-install-recommends -- python3-venv zip unzip jq'])

    def test_host_programs_missing_beside_ready_tools_are_installed_once_then_only_read(self):
        self.keep()
        # zip and jq, which fixture units run, are missing; python3-venv and unzip are installed.
        self.stub('dpkg-query', 'case "${@: -1}" in zip|jq) exit 1;; esac\nprintf \'install ok installed\'\n')
        self.log.write_text('')
        result, calls = self.keep(STUB_VENV_FAIL='1')
        self.assertEqual((result['tools'], result['reason']), ('built', 'missing'))
        apt = [call.split(' ', 1)[1] for call in calls if call.startswith(f'{self.bin}/apt-get')]
        self.assertEqual(apt, ['-o DPkg::Lock::Timeout=120 update', '-o DPkg::Lock::Timeout=120 install --yes --no-install-recommends -- zip jq'])
        self.assertFalse([call for call in calls if call.startswith(str(self.tools))], 'ready tools are not built again')
        self.stub('dpkg-query', "printf 'install ok installed'\n")
        self.log.write_text('')
        result, calls = self.keep(STUB_VENV_FAIL='1')
        self.assertEqual((result['tools'], result['reason']), ('present', 'current'))
        self.assertFalse([call for call in calls if 'apt-get' in call])

    def test_a_failed_package_read_is_the_package_step(self):
        # dpkg-query prints bytes that are not UTF-8: the read raises, and the step still names itself in the one result line.
        self.stub('dpkg-query', "printf '\\377'\n")
        result, _ = self.keep()
        self.assertEqual(result, {'tools': 'failed', 'reason': 'package', 'venv': 'venv-aaaaaaaaaaaaaaaa',
                                  'collections': 'collections-bbbbbbbbbbbbbbbb'})

    def test_a_failed_host_program_install_is_reported_and_keeps_ready_tools(self):
        self.keep()
        self.stub('dpkg-query', 'case "${@: -1}" in zip) exit 1;; esac\nprintf \'install ok installed\'\n')
        self.stub('apt-get', 'exit 100\n')
        result, _ = self.keep(STUB_VENV_FAIL='1')
        self.assertEqual((result['tools'], result['reason']), ('failed', 'package'))
        self.assertTrue((self.tools / 'venv-aaaaaaaaaaaaaaaa/.complete').is_file())

    def test_each_failed_step_is_named_and_leaves_nothing_complete(self):
        self.stub('dpkg-query', 'exit 1\n')
        self.stub('apt-get', 'exit 100\n')
        self.assertEqual(self.keep()[0]['reason'], 'package')
        self.stub('dpkg-query', "printf 'install ok installed'\n")
        result, _ = self.keep(STUB_VENV_FAIL='1')
        self.assertEqual((result['tools'], result['reason']), ('failed', 'venv'))
        self.stub('galaxy', 'exit 1\n')
        self.assertEqual(self.keep()[0]['reason'], 'collections')
        self.assertFalse((self.tools / 'collections-bbbbbbbbbbbbbbbb').exists(), 'a failed install leaves no complete collections')
        # The gate and the proof runner take a venv with `.complete` as ready: without collections it has none, so a copy's
        # runner builds its own tools in its cache instead of failing on a root-owned folder.
        # (default_tools reads only that marker: test_uses_the_system_tools_only_when_built_for_these_pins.)
        self.assertFalse((self.tools / 'venv-aaaaaaaaaaaaaaaa/.complete').exists())
        (self.workspace / 'bin/swamp-shape-gate').unlink()
        self.assertEqual(self.keep()[0], {'tools': 'failed', 'reason': 'names'})

    def test_the_names_are_the_gates_own(self):
        spec = importlib.util.spec_from_file_location('instance_shape_tools', ANSIBLE / 'instance_shape_tools.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        gate = load_gate()
        _, tools, venv, collections = module.names(ROOT)
        self.assertEqual((tools, venv, collections),
                         (gate.SYSTEM_TOOLS, gate.SYSTEM_TOOLS / gate.venv_name(), gate.collections_path(gate.SYSTEM_TOOLS)))
        self.assertNotRegex((ANSIBLE / 'instance_shape_tools.py').read_text(), r'sha256|\w==\d', 'the script names no pin and no digest rule')


if __name__ == '__main__':
    unittest.main()
