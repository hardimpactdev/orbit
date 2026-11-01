"""Legacy external start-state polling, without paid servers or real credentials."""
import importlib.util
import io
import os
import sys
import unittest
import urllib.error
from contextlib import redirect_stderr
from pathlib import Path
from unittest.mock import call, patch

DIRECTORY = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations/task-vm-lifecycle/scripts'


def load(name):
    spec = importlib.util.spec_from_file_location(name, DIRECTORY / (name + '.py'))
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


API = load('upcloud_api')
with patch.dict(sys.modules, {'upcloud_api': API}):
    WAIT = load('upcloud')


class UpCloudStartWait(unittest.TestCase):
    def test_maintenance_and_stopped_wait_at_the_legacy_interval_until_started(self):
        states = [{'server': {'uuid': 'owned', 'state': state}} for state in ('maintenance', 'stopped', 'started')]
        with patch.object(WAIT, 'poll_read', side_effect=states) as api, patch.object(WAIT.time, 'sleep') as sleep:
            result = WAIT.wait_started('owned')
        self.assertEqual(result, {'uuid': 'owned', 'state': 'started'})
        self.assertEqual(api.call_args_list, [call('server/owned')] * 3)
        self.assertEqual(sleep.call_args_list, [call(3), call(3)])

    def test_request_failures_propagate_without_sleep_or_another_request(self):
        with patch.object(WAIT, 'poll_read', side_effect=OSError('request failed')) as api, patch.object(WAIT.time, 'sleep') as sleep, self.assertRaises(OSError):
            WAIT.wait_started('owned')
        api.assert_called_once_with('server/owned')
        sleep.assert_not_called()

    def test_bad_terminal_or_foreign_server_fails_readably_without_waiting(self):
        for row in ({'uuid': 'owned', 'state': 'error'}, {'uuid': 'foreign', 'state': 'started'}):
            with self.subTest(row=row), patch.object(WAIT, 'poll_read', return_value={'server': row}) as api, patch.object(WAIT.time, 'sleep') as sleep:
                with self.assertRaisesRegex(SystemExit, r'task_vm\.'):
                    WAIT.wait_started('owned')
                api.assert_called_once()
                sleep.assert_not_called()

    def test_poll_read_propagates_404_while_delete_observation_stays_tolerant(self):
        error = urllib.error.HTTPError('https://api.upcloud.com/1.3/server/owned', 404, 'missing', {}, None)
        with patch.object(API, '_api', side_effect=error) as request:
            self.assertEqual(API.api('GET', 'server/owned'), {})
            with self.assertRaises(urllib.error.HTTPError):
                API.poll_read('server/owned')
        self.assertEqual(request.call_args_list, [call('GET', 'server/owned', None), call('GET', 'server/owned')])

    def test_missing_provider_server_is_terminal_readable_and_never_polled_again(self):
        for status in (404, 403, 500):
            with self.subTest(status=status):
                error = urllib.error.HTTPError('https://api.upcloud.com/1.3/server/owned', status, 'request failed', {}, None)
                self.addCleanup(error.close)
                stderr = io.StringIO()
                with patch.object(API, '_api', side_effect=error) as request, patch.object(WAIT.time, 'sleep') as sleep, patch.object(sys, 'argv', ['upcloud.py', 'wait-started']), patch.dict(os.environ, {'ORBIT_CLOUD_CHOICE': '{"provider_id":"owned"}'}), redirect_stderr(stderr):
                    self.assertEqual(WAIT.main(), 1)
                self.assertEqual(stderr.getvalue().strip(), 'task_vm.provider_server_missing' if status == 404 else 'task_vm.upcloud_failed')
                request.assert_called_once_with('GET', 'server/owned')
                sleep.assert_not_called()
