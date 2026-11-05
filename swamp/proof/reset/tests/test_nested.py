"""`orbit-nested`: the sync before each call, the apply step, `exec`, and the repair after a failure."""
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'tools'))
import orbit_nested as nested  # noqa: E402
import phases

NODES = {'gateway': {'instance': 'gw'}, 'app-dev': {'instance': 'dev'}, 'app-prod': {'instance': 'prod'}}


def quiet(moved=False, composer=()):
    return {role: {'rsync_seconds': 0.1, 'moved': moved, 'composer': list(composer), 'composer_seconds': 0.0} for role in ('gateway', 'app-dev')}


class OrbitNestedTests(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        home = Path(directory.name)
        self.config = home / 'nested.json'
        self.config.write_text(json.dumps({'candidate': '/work', 'group': 7, 'generation': 'g', 'construction': {'nodes': NODES}}))
        for name, path in (('NESTED_CONFIG', self.config), ('NESTED_PENDING', home / 'pending'), ('NESTED_LOCK', home / 'lock')):
            patcher = patch.object(phases, name, path)
            patcher.start()
            self.addCleanup(patcher.stop)

    def call(self, argv, timings, guest=None):
        guest = guest or (lambda topology, facts, action: {})
        with patch.object(phases, 'sync_source', return_value=timings) as sync, \
                patch.object(phases, 'guest_facts', return_value={}), patch.object(phases, 'run_guest', side_effect=guest) as run, \
                patch.object(nested.os, 'execv') as execv, patch.object(nested.os, 'execvp') as execvp:
            nested.main(argv)
        return sync, run, execv, execvp

    def test_a_call_with_nothing_changed_syncs_and_runs_the_cli_without_the_apply_step(self):
        sync, run, execv, _ = self.call(['gateway:status', '--json'], quiet())
        self.assertEqual((Path('/work'), {'gateway': 'gw', 'app-dev': 'dev'}), (sync.call_args.args[0], sync.call_args.args[1]))
        self.assertFalse(sync.call_args.kwargs['reinstall'])
        run.assert_not_called()
        execv.assert_called_once_with('/work/apps/cli/orbit', ['/work/apps/cli/orbit', 'gateway:status', '--json'])
        self.assertFalse(phases.NESTED_PENDING.exists())

    def test_files_moved_into_the_gateway_run_the_apply_step_once(self):
        _, run, execv, _ = self.call(['gateway:status'], quiet(moved=True, composer=['apps/gateway']))
        self.assertEqual(['candidate'], [call.args[2] for call in run.call_args_list])
        execv.assert_called_once()

    def test_files_moved_only_into_app_dev_leave_the_gateway_alone(self):
        timings = quiet()
        timings['app-dev']['moved'] = True
        _, run, execv, _ = self.call(['gateway:status'], timings)
        run.assert_not_called()
        execv.assert_called_once()

    def test_exec_syncs_then_runs_the_command_in_the_node_as_orbit(self):
        _, _, execv, execvp = self.call(['exec', 'gateway', '--', 'php', 'artisan', 'about'], quiet())
        execv.assert_not_called()
        argv = execvp.call_args.args[1]
        self.assertEqual('incus', execvp.call_args.args[0])
        self.assertEqual(['incus', 'exec', 'gw', '--user', '1000', '--env', 'HOME=/home/orbit', '--cwd', '/home/orbit/orbit/apps/gateway', '--',
                          'php', 'artisan', 'about'], argv)

    def test_exec_reaches_a_node_the_sync_does_not_fill(self):
        _, _, _, execvp = self.call(['exec', 'app-prod', '--', 'php', '-v'], quiet())
        self.assertEqual(['incus', 'exec', 'prod'], execvp.call_args.args[1][:3])
        self.assertEqual('/home/orbit', execvp.call_args.args[1][execvp.call_args.args[1].index('--cwd') + 1])

    def test_a_bad_exec_is_refused_before_anything_is_synced(self):
        for argv in (['exec'], ['exec', 'gateway'], ['exec', 'gateway', 'php'], ['exec', 'nowhere', '--', 'ls'], ['exec', 'gateway', '--']):
            with patch.object(phases, 'sync_source') as sync, self.assertRaises(SystemExit) as stopped:
                nested.main(argv)
            self.assertIn('usage', str(stopped.exception), argv)
            sync.assert_not_called()

    def test_a_failed_apply_stops_the_call_and_the_next_one_repeats_everything(self):
        def fail(topology, facts, action):
            raise RuntimeError('migrate failed')
        with self.assertRaises(SystemExit) as stopped:
            self.call(['gateway:status'], quiet(moved=True), guest=fail)
        self.assertIn('migrate failed', str(stopped.exception))
        self.assertTrue(phases.NESTED_PENDING.exists())
        sync, run, execv, _ = self.call(['gateway:status'], quiet())
        self.assertTrue(sync.call_args.kwargs['reinstall'])
        run.assert_called_once()
        execv.assert_called_once()
        self.assertFalse(phases.NESTED_PENDING.exists())

    def test_the_cli_is_not_run_when_the_sync_fails(self):
        with patch.object(phases, 'sync_source', side_effect=RuntimeError('rsync exited 23')), patch.object(nested.os, 'execv') as execv:
            with self.assertRaises(SystemExit):
                nested.main(['gateway:status'])
        execv.assert_not_called()


class SyncSourceTests(unittest.TestCase):
    def test_the_sync_reports_what_moved_per_guest_and_installs_where_a_lock_changed(self):
        with patch.object(phases, 'sync_checkout', side_effect=[(['apps/gateway'], True), ([], False)]), \
                patch.object(phases, 'install_locked') as install:
            timings = phases.sync_source(Path('/src'), {'gateway': 'gw', 'app-dev': 'dev'})
        self.assertEqual({'gateway': ['apps/gateway'], 'app-dev': []}, {role: timing['composer'] for role, timing in timings.items()})
        self.assertEqual({'gateway': True, 'app-dev': False}, {role: timing['moved'] for role, timing in timings.items()})
        self.assertEqual({'gw': ['apps/gateway'], 'dev': []}, {call.args[0]: call.args[1] for call in install.call_args_list})

    def test_a_repair_installs_in_every_directory_that_has_a_lock_file(self):
        with patch.object(phases, 'sync_checkout', return_value=([], False)), patch.object(phases, 'install_locked') as install, \
                patch.object(phases, 'lock_directories', return_value=['apps/cli', 'apps/gateway']):
            timings = phases.sync_source(Path('/src'), {'gateway': 'gw', 'app-dev': 'dev'}, reinstall=True)
        self.assertEqual(['apps/cli', 'apps/gateway'], timings['gateway']['composer'])
        self.assertEqual(2, install.call_count)

    def test_deleted_files_and_new_directories_count_as_moved(self):
        for listing in (b'*deleting   apps/gateway/Old.php\n', b'cd+++++++++ apps/gateway/new/\n', b'<f+++++++++ a.php\n'):
            with patch.object(phases.subprocess, 'run', return_value=phases.subprocess.CompletedProcess([], 0, listing, b'')):
                self.assertTrue(phases.sync_checkout(Path('/src'), 'gw', Path('/w'))[1], listing)
        with patch.object(phases.subprocess, 'run', return_value=phases.subprocess.CompletedProcess([], 0, b'.d..t...... apps\n.f..t...... a.php\n', b'')):
            self.assertEqual(([], False), phases.sync_checkout(Path('/src'), 'gw', Path('/w')))


if __name__ == '__main__':
    unittest.main()
