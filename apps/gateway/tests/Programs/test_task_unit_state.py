"""Task monitors distinguish queued starts from terminal failures without using live units."""
import json
import os
from pathlib import Path
import runpy
import subprocess
import tempfile
import types
import unittest
from unittest.mock import MagicMock, patch

RESOURCES = Path(__file__).resolve().parents[2] / 'resources'
OPERATION = 'a' * 32
BINDING = 'b' * 64
BOOT = Path('/proc/sys/kernel/random/boot_id').read_text().strip()


def program(name):
    source = (RESOURCES / name).read_text()
    return source.replace('# ORBIT_TASK_UNIT_STATE', (RESOURCES / 'tasks/unit-state.py').read_text())


class TaskUnitStateTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.home = Path(self.temporary.name)
        self.bin = self.home / 'bin'
        self.bin.mkdir()
        self.environment = dict(os.environ, HOME=str(self.home), PATH=str(self.bin) + ':' + os.environ['PATH'])
        self.state = dict(LoadState='loaded', ActiveState='failed', SubState='failed', Job='',
                          ControlGroup='', Description='Orbit workspace operation ' + BINDING,
                          ExecMainCode='0', ExecMainStatus='0', Result='resources')
        self.receipts = self.home / '.local/state/orbit/task-workspace-operations'
        self.receipts.mkdir(parents=True)
        (self.receipts / OPERATION).write_text(json.dumps(dict(
            operation=OPERATION, binding=BINDING, boot=BOOT, state='running')))
        self.shim('systemctl', """#!/usr/bin/env python3
import json, os, sys
from pathlib import Path
home = Path(os.environ['HOME'])
if sys.argv[1] == 'is-active':
    sys.exit(3)
elif sys.argv[1] == 'list-units':
    print(json.dumps([{'unit': 'orbit-workspace-operation-' + 'a' * 32 + '.service'}]))
else:
    state = json.loads((home / 'unit.json').read_text())
    if (home / 'queued-once').exists():
        (home / 'queued-once').unlink()
        state.update(ActiveState='inactive', SubState='dead', Job='45809123', ExecMainCode='0')
    requested = next((arg.split('=', 1)[1].split(',') for arg in sys.argv if arg.startswith('--property=')), [])
    for key in requested:
        print(key + '=' + state.get(key, ''))
""")
        self.shim('sudo', r"""#!/usr/bin/env python3
import os, sys
from pathlib import Path
home = Path(os.environ['HOME'])
if sys.argv[2:4] == ['systemctl', 'show']:
    print('Version=259')
    sys.exit(0)
if sys.argv[2] == 'systemd-run':
    with (home / 'finish').open() as finish:
        finish.read()
    sys.exit(0)
with (home / 'stops').open('a') as output:
    output.write(' '.join(sys.argv[1:]) + '\n')
if (home / 'finish').exists():
    with (home / 'finish').open('w') as finish:
        finish.write('done\n')
""")

    def shim(self, name, source):
        path = self.bin / name
        path.write_text(source)
        path.chmod(0o700)

    def invoke(self, mode):
        (self.home / 'unit.json').write_text(json.dumps(self.state))
        result = subprocess.run(['python3', '-c', program('tasks/workspace-operation.py'),
                                 mode, OPERATION, BINDING, '0', *(['["' + OPERATION + '"]', '[]'] if mode == 'seal' else [])],
                                env=self.environment, capture_output=True, text=True, timeout=10, check=True)
        return json.loads(result.stdout)

    def test_run_waits_for_pending_job_and_records_completion(self):
        for main_code, expected in (('0', 128), ('1', 0)):
            with self.subTest(main_code=main_code):
                (self.receipts / OPERATION).unlink()
                self.state.update(ExecMainCode=main_code)
                (self.home / 'unit.json').write_text(json.dumps(self.state))
                (self.home / 'queued-once').touch()
                (self.home / 'finish').unlink(missing_ok=True)
                os.mkfifo(self.home / 'finish')

                result = subprocess.run(['python3', '-c', program('tasks/workspace-operation.py'),
                                         'run', OPERATION, BINDING, '0', 'true'], env=self.environment,
                                        capture_output=True, text=True, timeout=10, check=False)

                self.assertEqual(result.returncode, expected, result.stderr)
                receipt = json.loads((self.receipts / OPERATION).read_text())
                self.assertEqual((receipt['state'], receipt['exit_code']), ('finished', expected))
                self.assertFalse((self.home / 'queued-once').exists())

    def test_seal_accepts_pre_exec_failure_without_an_exit_status(self):
        self.assertEqual(self.invoke('seal')['state'], 'sealed')

    def test_probe_settles_pre_exec_resource_failure_as_128(self):
        result = self.invoke('probe')
        self.assertEqual((result['state'], result.get('exit_code')), ('finished', 128))
        self.assertIn('systemctl stop', (self.home / 'stops').read_text())

    def test_pending_job_holds_probe_and_seal(self):
        self.state.update(ActiveState='inactive', SubState='dead', Job='45809123', ExecMainCode='0')
        for mode in ('probe', 'seal'):
            with self.subTest(mode=mode):
                self.assertEqual(self.invoke(mode)['state'], 'running')
        self.assertFalse((self.home / 'stops').exists())

    def test_finished_exit_and_timeout_keep_their_exit_codes(self):
        for main_code, status, expected in (('1', '0', 0), ('1', '3', 3), ('2', '15', 143)):
            with self.subTest(main_code=main_code):
                (self.receipts / OPERATION).write_text(json.dumps(dict(
                    operation=OPERATION, binding=BINDING, boot=BOOT, state='running')))
                self.state.update(ExecMainCode=main_code, ExecMainStatus=status)
                result = self.invoke('probe')
                self.assertEqual((result['state'], result.get('exit_code')), ('finished', expected))

    def test_missing_unit_keeps_running_receipt_held(self):
        self.state.update(LoadState='not-found', ActiveState='inactive', SubState='dead')
        self.assertEqual(self.invoke('probe')['state'], 'running')
        self.assertFalse((self.home / 'stops').exists())

    def test_check_supervisor_does_not_retire_a_pending_job(self):
        check = types.ModuleType('check')
        check.__file__ = str(self.home / 'check')
        exec(compile(program('tasks/check'), check.__file__, 'exec'), check.__dict__)
        self.state.update(ActiveState='inactive', SubState='dead', Job='45809123',
                          Description='Orbit task check ' + OPERATION)
        (self.home / 'unit.json').write_text(json.dumps(self.state))
        with patch.dict(os.environ, self.environment), patch.object(check, 'start_record', return_value=(MagicMock(), 'unused', {})) as record, \
             patch.object(check, 'write_start_record'):
            self.assertFalse(check.managed_run_finished(dict(run_id=OPERATION, boot=BOOT, state='started')))
        record.assert_not_called()
        self.assertFalse((self.home / 'stops').exists())

    def test_topology_reports_pending_job_as_running(self):
        self.state.update(ActiveState='inactive', SubState='dead', Job='45809123', ExecMainCode='0')
        (self.home / 'unit.json').write_text(json.dumps(self.state))
        result = subprocess.run(['bash', '-c', program('task-vms/topology.sh'), 'topology', 'status', 'test1234'],
                                env=self.environment, capture_output=True, text=True, timeout=10, check=True)
        self.assertEqual(json.loads(result.stdout), {'state': 'running'})


class TaskUnitLintTest(unittest.TestCase):
    def setUp(self):
        self.check = runpy.run_path(str(RESOURCES.parents[2] / 'bin/remote-programs-check'))
        self.temporary = tempfile.TemporaryDirectory(dir=Path(__file__).parent)
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name)
        self.template = self.directory / 'topology.sh'
        self.template.write_text("#!/bin/bash\npython3 - <<'PYTHON'\n# ORBIT_TASK_UNIT_STATE\nunknown_template_name\nPYTHON\n")

    def lint(self):
        source, offsets = self.check['assemble']([self.template])
        bodies = list(self.check['python_heredocs'](source, offsets))
        self.assertEqual(len(bodies), 1)
        return self.check['ruff_source'](self.template, *bodies[0], ['--select', 'E9,F821'])

    def test_embedded_python_error_names_the_original_template_line(self):
        findings = self.lint()
        location = self.template.relative_to(RESOURCES.parents[2]).as_posix()
        self.assertEqual(len(findings), 1)
        self.assertTrue(findings[0].startswith(location + ':4:1: F821'), findings)

    def test_embedded_library_error_names_the_original_library_line(self):
        library = self.directory / 'unit-state.py'
        library.write_text('unknown_library_name\n')
        self.template.write_text(self.template.read_text().replace('unknown_template_name', 'pass'))
        with patch.dict(self.check['assemble'].__globals__, {'UNIT_LIBRARY': library}):
            findings = self.lint()
        location = library.relative_to(RESOURCES.parents[2]).as_posix()
        self.assertEqual(len(findings), 1)
        self.assertTrue(findings[0].startswith(location + ':1:1: F821'), findings)


if __name__ == '__main__':
    unittest.main()
