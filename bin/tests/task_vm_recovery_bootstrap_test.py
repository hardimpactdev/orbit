import hashlib
import importlib.machinery
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

MODULE = importlib.machinery.SourceFileLoader('recovery', str(Path(__file__).parents[1] / 'task-vm-recovery-bootstrap')).load_module()


class RecoveryBootstrapTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)
        self.tree = self.root / 'tooling'
        self.tree.mkdir()
        self.live = self.root / 'current'
        self.live.mkdir()
        self.sha = '5b3924dbcedc4f03d5aca3d8553a72e5014acf21'
        files = {'apps/gateway/artisan': b'<?php', 'apps/gateway/app/Console/Commands/RetryTaskVmRoleImagesCommand.php': b'<?php'}
        for name, contents in files.items():
            target = self.tree / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(contents)
        autoload = self.tree / 'apps/gateway/vendor/autoload.php'
        autoload.parent.mkdir(parents=True)
        autoload.write_text('<?php')
        self.archive = self.root / 'source.tar'
        with tarfile.open(self.archive, 'w', format=tarfile.PAX_FORMAT, pax_headers={'comment': self.sha}) as archive:
            for name, contents in files.items():
                member = tarfile.TarInfo(name)
                member.size = len(contents)
                archive.addfile(member, io.BytesIO(contents))
        (self.live / 'REVISION').write_text('f' * 40)
        config = self.live / 'apps/gateway/bootstrap/cache/config.php'
        config.parent.mkdir(parents=True)
        config.write_text('<?php return [];')
        config.chmod(0o600)
        self.home = self.root / 'home'
        self.home.mkdir(mode=0o700)
        self.db = self.home / 'gateway.sqlite'
        self.db.touch(mode=0o600)
        self.storage = self.root / 'storage'
        self.storage.mkdir()
        self.manifest = self.root / 'authorization.json'
        self.trust = patch.object(MODULE, 'root_owned')
        self.trust.start()
        self.account = patch.object(MODULE.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid(), pw_dir=str(self.home)))
        self.account.start()
        self.data = {'source_sha': self.sha, 'archive': str(self.archive), 'archive_sha256': MODULE.digest(self.archive), 'tooling': str(self.tree), 'tree_sha256': MODULE.tree_digest(self.tree), 'group': 1752, 'vm': 103, 'live_sha': 'f' * 40, 'current': str(self.live), 'config_sha256': MODULE.digest(config), 'orbit_home': str(self.home), 'database': str(self.db), 'storage': str(self.storage)}
        self.write()
        self.run = patch.object(MODULE.subprocess, 'run', return_value=SimpleNamespace(returncode=0))
        self.process = self.run.start()

    def tearDown(self):
        self.run.stop()
        self.account.stop()
        self.trust.stop()
        self.temp.cleanup()

    def write(self):
        self.manifest.write_text(json.dumps(self.data))

    def test_positive_delegates_only_fixed_public_retry_with_real_shared_config(self):
        argv, env = MODULE.validate(self.manifest)
        self.assertEqual(argv, ['/usr/bin/php8.5', str(self.tree / 'apps/gateway/artisan'), 'task-vms:retry-role-images', '1752', '103'])
        self.assertEqual(env['APP_CONFIG_CACHE'], str(self.live / 'apps/gateway/bootstrap/cache/config.php'))
        self.assertEqual(env['LARAVEL_STORAGE_PATH'], str(self.storage))
        self.assertNotIn('migrate', argv)
        self.assertNotIn('deploy', argv)
        self.assertEqual(self.process.call_count, 1)
        self.assertEqual(self.process.call_args.args[0][-3:], [str(self.home), str(self.live / 'apps/gateway'), str(self.db)])

    def test_root_or_wrong_owner_refuses_before_any_subprocess(self):
        for uid in [0, os.geteuid() + 1]:
            with self.subTest(uid=uid), patch.object(MODULE.os, 'geteuid', return_value=uid):
                with self.assertRaises(MODULE.Refused):
                    MODULE.validate(self.manifest)
        self.process.assert_not_called()

    def test_source_digest_config_or_live_drift_refuses_before_execution(self):
        for key, value in [('tree_sha256', '0' * 64), ('archive_sha256', '0' * 64), ('config_sha256', '0' * 64), ('source_sha', 'a' * 40), ('live_sha', 'a' * 40), ('vm', 0), ('group', True)]:
            original = self.data[key]
            self.data[key] = value
            self.write()
            with self.subTest(key=key), self.assertRaises(MODULE.Refused):
                MODULE.validate(self.manifest)
            self.data[key] = original
        self.process.assert_not_called()

    def test_modified_source_refuses_even_with_new_installed_tree_digest(self):
        (self.tree / 'apps/gateway/artisan').write_text('modified source')
        self.data['tree_sha256'] = MODULE.tree_digest(self.tree)
        self.write()
        with self.assertRaises(MODULE.Refused):
            MODULE.validate(self.manifest)
        self.process.assert_not_called()

    def test_shared_config_identity_failure_never_executes_retry(self):
        self.process.return_value.returncode = 1
        with self.assertRaises(MODULE.Refused):
            MODULE.validate(self.manifest)

    def test_external_symlink_refused(self):
        (self.tree / 'outside').symlink_to(self.db)
        with self.assertRaises(MODULE.Refused):
            MODULE.tree_digest(self.tree)

    def test_check_mode_does_not_invoke_recovery(self):
        with patch.object(MODULE.sys, 'argv', ['bootstrap', '--authorization', str(self.manifest), '--check']), patch.object(MODULE.os, 'execve') as execute, patch('sys.stdout', new_callable=io.StringIO) as output:
            self.assertEqual(MODULE.main(), 0)
            self.assertEqual(json.loads(output.getvalue())['outcome'], 'validated')
            execute.assert_not_called()

    def test_real_php_refuses_url_driver_and_split_database_overrides(self):
        # Exercise the actual PHP gate, rather than stubbing its boolean response.
        self.run.stop()
        configuration = self.live / 'apps/gateway/bootstrap/cache/config.php'
        base = {'orbit': {'home': str(self.home), 'gateway_checkout': str(self.live / 'apps/gateway')}, 'database': {'default': 'sqlite', 'connections': {'sqlite': {'driver': 'sqlite', 'database': str(self.db)}}}}
        def write_config(values):
            encoded = json.dumps(values).replace("'", "\\'")
            configuration.write_text("<?php return json_decode('" + encoded + "', true);")
            self.data['config_sha256'] = MODULE.digest(configuration)
            self.write()
        try:
            write_config(base)
            argv, _ = MODULE.validate(self.manifest)
            self.assertEqual(argv[-2:], ['1752', '103'])
            for override in [{'url': 'sqlite:////another.sqlite'}, {'driver': 'mysql'}, {'read': {'database': '/another.sqlite'}}, {'write': {'database': '/another.sqlite'}}, {'options': {'12': 'override'}}]:
                changed = json.loads(json.dumps(base))
                changed['database']['connections']['sqlite'].update(override)
                write_config(changed)
                with self.subTest(override=list(override)), self.assertRaises(MODULE.Refused):
                    MODULE.validate(self.manifest)
        finally:
            self.run.start()

    def test_native_release_locks_block_other_owners_and_are_retained_for_exec(self):
        import fcntl
        for name in ('gateway-release.lock', 'gateway-release-step.lock'):
            (self.home / name).touch(mode=0o600)
        with MODULE.execution_locks(self.home):
            for name in ('gateway-release.lock', 'gateway-release-step.lock'):
                with (self.home / name).open() as contender:
                    with self.assertRaises(BlockingIOError):
                        fcntl.flock(contender.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        with MODULE.execution_locks(self.home):
            pass

    def test_trusted_paths_check_every_ancestor(self):
        MODULE.trusted_path(Path('/opt/orbit/recovery/tooling'))
        observed = [call.args[0] for call in MODULE.root_owned.call_args_list]
        for component in ['/', '/opt', '/opt/orbit', '/opt/orbit/recovery', '/opt/orbit/recovery/tooling']:
            self.assertIn(Path(component), observed)

    def test_native_authorization_owner_guard_refuses_user_owned_manifest(self):
        self.trust.stop()
        with self.assertRaises(MODULE.Refused):
            MODULE.root_owned(self.manifest)
        self.trust.start()


if __name__ == '__main__':
    unittest.main()
