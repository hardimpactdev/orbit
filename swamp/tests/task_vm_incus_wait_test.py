"""Bounded Incus state observation; no host commands or VM effects."""
import importlib.util
import json
import subprocess
import unittest
from pathlib import Path
from unittest.mock import call, patch

SCRIPT = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations/task-vm-lifecycle/scripts/incus_start_wait.py'
spec = importlib.util.spec_from_file_location('incus_start_wait_fixture', SCRIPT)
WAIT = importlib.util.module_from_spec(spec)
spec.loader.exec_module(WAIT)


class IncusStartWait(unittest.TestCase):
    def test_stopped_and_starting_wait_once_per_read_until_running(self):
        rows = [json.dumps([{'name': 'tvm-42', 'type': 'virtual-machine', 'status': state}])
                for state in ('Stopped', 'Starting', 'Running')]
        with patch.object(WAIT.subprocess, 'check_output', side_effect=rows) as read, patch.object(WAIT.time, 'sleep') as sleep:
            result = WAIT.wait_started('tasks', 'tvm-42')
        self.assertEqual(result, [{'name': 'tvm-42', 'type': 'virtual-machine', 'status': 'Running'}])
        self.assertEqual(read.call_count, 3)
        self.assertEqual(sleep.call_args_list, [call(1), call(1)])
        self.assertEqual(read.call_args.args[0], ['/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', 'tasks', 'list', '--format', 'json', '--', 'tvm-42'])

    def test_failed_state_read_propagates_without_sleep_or_another_read(self):
        with patch.object(WAIT.subprocess, 'check_output', side_effect=subprocess.CalledProcessError(1, ['incus', 'list'])) as read, patch.object(WAIT.time, 'sleep') as sleep, self.assertRaises(subprocess.CalledProcessError):
            WAIT.wait_started('tasks', 'tvm-42')
        read.assert_called_once()
        sleep.assert_not_called()

    def test_missing_foreign_wrong_type_and_bad_terminal_state_refuse_without_waiting(self):
        for rows in ([], [{'name': 'tvm-43', 'type': 'virtual-machine', 'status': 'Running'}],
                     [{'name': 'tvm-42', 'type': 'container', 'status': 'Running'}],
                     [{'name': 'tvm-42', 'type': 'virtual-machine', 'status': 'Error'}]):
            with self.subTest(rows=rows), patch.object(WAIT.subprocess, 'check_output', return_value=json.dumps(rows)) as read, patch.object(WAIT.time, 'sleep') as sleep:
                with self.assertRaisesRegex(SystemExit, r'task_vm\.'):
                    WAIT.wait_started('tasks', 'tvm-42')
                read.assert_called_once()
                sleep.assert_not_called()
