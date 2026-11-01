"""Real SQLite files under existing unprivileged OS accounts."""
import contextlib
import hashlib
import json
import os
from pathlib import Path
import pwd
import sqlite3
import stat
import subprocess
import tempfile
import unittest
import uuid

HERE = Path(__file__).resolve().parent
PROGRAM = HERE / 'scripts/sqlite_actions.py'


class SqliteProgramTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='sqlite-port-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.worker = pwd.getpwnam('daemon')
        self.app = pwd.getpwnam('nobody')
        os.chmod(self.root, 0o755)
        self.source = self.root / 'source'
        self.target = self.root / 'target'
        for directory, user in [(self.source, self.worker), (self.target, self.app)]:
            directory.mkdir(mode=0o700)
            os.chown(directory, user.pw_uid, user.pw_gid)
        self.database = self.source / 'db.sqlite'
        with contextlib.closing(sqlite3.connect(self.database, isolation_level=None)) as database:
            database.execute('CREATE TABLE proof (value TEXT)')
            database.execute('INSERT INTO proof VALUES (?)', ('private-sqlite-sentinel',))
        os.chmod(self.database, 0o600)
        os.chown(self.database, self.worker.pw_uid, self.worker.pw_gid)
        self.identity = uuid.uuid4().hex * 2
        state = '/var/lib/orbit/app-instance-sqlite-seeds/'
        self.source_input = {'mode': 'prepare', 'base': str(self.source), 'source_path': str(self.database),
            'source_user': self.worker.pw_name, 'transport_user': self.worker.pw_name, 'operation': self.identity,
            'state_dir': state + 'source-1-target-2-' + self.identity,
            'snapshot_path': '/tmp/orbit-sqlite-' + self.identity + '.sqlite'}
        self.target_input = {'mode': 'prepare', 'base': str(self.target), 'target_user': self.app.pw_name,
            'transport_user': self.worker.pw_name, 'operation': self.identity,
            'state_dir': state + 'target-2-' + self.identity, 'environment': 'development',
            'relative_path': 'db.sqlite', 'expected_size': 0, 'expected_digest': ''}
        self.addCleanup(self.clean_seed)

    def call(self, action, data, user=None, seconds=120):
        def drop():
            if user is not None:
                os.initgroups(user.pw_name, user.pw_gid)
                os.setgid(user.pw_gid)
                os.setuid(user.pw_uid)
        result = subprocess.run(['/usr/bin/python3', '-B', str(PROGRAM), action, str(seconds)],
            input=json.dumps(data), text=True, capture_output=True, preexec_fn=drop, timeout=seconds + 5)
        self.assertNotIn('private-sqlite-sentinel', result.stdout + result.stderr)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def clean_seed(self):
        with contextlib.suppress(Exception):
            self.call('seedTarget', dict(self.target_input, mode='cleanup'))
        with contextlib.suppress(Exception):
            self.call('seedSource', dict(self.source_input, mode='cleanup'))

    def write_incoming(self, selected, data):
        flags = os.O_WRONLY | os.O_TRUNC | os.O_NOFOLLOW
        if not selected.exists(): flags |= os.O_CREAT | os.O_EXCL
        with os.fdopen(os.open(selected, flags, 0o600), 'wb') as stream:
            stream.write(data)

    def seed(self):
        exported = self.call('seedSource', self.source_input)
        self.assertTrue(exported['ok'], exported)
        exported = exported['data']
        self.target_input.update(expected_size=exported['bytes'], expected_digest=exported['sha256'])
        prepared = self.call('seedTarget', self.target_input)
        self.assertTrue(prepared['ok'], prepared)
        return exported, prepared['data']

    def test_seed_snapshots_installs_as_target_owner_and_resumes_without_replacing_file(self):
        exported, prepared = self.seed()
        self.assertEqual(prepared['state'], 'ready')
        incoming = Path(prepared['incoming'])
        self.write_incoming(incoming, Path(self.source_input['snapshot_path']).read_bytes())
        before_inode = incoming.stat().st_ino
        installed = self.call('seedTarget', dict(self.target_input, mode='install'))
        self.assertEqual(installed, {'ok': True, 'data': {'state': 'changed'}})
        destination = self.target / 'db.sqlite'
        info = destination.stat()
        self.assertEqual((info.st_uid, stat.S_IMODE(info.st_mode)), (self.app.pw_uid, 0o600))
        self.assertFalse(incoming.exists())
        self.assertEqual(hashlib.sha256(destination.read_bytes()).hexdigest(), exported['sha256'])
        self.assertEqual(self.call('seedTarget', self.target_input), {'ok': True, 'data': {'state': 'complete'}})
        self.assertEqual(destination.stat().st_ino, info.st_ino)
        self.assertNotEqual(before_inode, info.st_ino)

    def test_seed_refuses_a_foreign_existing_target_and_preserves_its_bytes(self):
        destination = self.target / 'db.sqlite'
        destination.write_bytes(b'foreign')
        os.chown(destination, self.app.pw_uid, self.app.pw_gid)
        os.chmod(destination, 0o600)
        exported = self.call('seedSource', self.source_input)['data']
        self.target_input.update(expected_size=exported['bytes'], expected_digest=exported['sha256'])
        self.assertFalse(self.call('seedTarget', self.target_input)['ok'])
        self.assertEqual(destination.read_bytes(), b'foreign')

    def test_source_snapshot_reuses_matching_inode_and_refuses_a_symlink(self):
        exported = self.call('seedSource', self.source_input)
        snapshot = Path(self.source_input['snapshot_path'])
        inode = snapshot.stat().st_ino
        self.assertEqual(self.call('seedSource', self.source_input), exported)
        self.assertEqual(snapshot.stat().st_ino, inode)
        snapshot.unlink()
        snapshot.symlink_to(self.database)
        try:
            self.assertFalse(self.call('seedSource', self.source_input)['ok'])
        finally:
            snapshot.unlink()

    def test_target_install_refuses_a_replaced_incoming_inode_and_digest_mismatch(self):
        for replace_inode in [True, False]:
            with self.subTest(replace_inode=replace_inode):
                exported, prepared = self.seed()
                incoming = Path(prepared['incoming'])
                if replace_inode:
                    original = os.open(incoming, os.O_RDONLY)
                    self.addCleanup(os.close, original)
                    incoming.unlink()
                    self.write_incoming(incoming, Path(self.source_input['snapshot_path']).read_bytes())
                    os.chown(incoming, self.worker.pw_uid, self.worker.pw_gid)
                    os.chmod(incoming, 0o600)
                else:
                    self.write_incoming(incoming, b'not a sqlite database')
                self.assertFalse(self.call('seedTarget', dict(self.target_input, mode='install'))['ok'])
                self.assertFalse((self.target / 'db.sqlite').exists())
                incoming.unlink(missing_ok=True)
                self.call('seedTarget', dict(self.target_input, mode='cleanup'))

    def test_clone_local_copies_wal_and_preserves_mode(self):
        destination_root = self.root / 'clone'
        destination_root.mkdir(mode=0o700)
        os.chown(destination_root, self.worker.pw_uid, self.worker.pw_gid)
        os.chmod(self.database, 0o640)
        connection = sqlite3.connect(self.database)
        connection.execute('PRAGMA journal_mode=WAL')
        connection.execute("INSERT INTO proof VALUES ('wal value')")
        connection.commit()
        for suffix in ['-wal', '-shm']:
            os.chown(str(self.database) + suffix, self.worker.pw_uid, self.worker.pw_gid)
        try:
            result = self.call('copy', {'operation': 'local', 'checkout': str(self.source), 'source': str(self.database),
                'target_checkout': str(destination_root), 'target': str(destination_root / 'db.sqlite')}, self.worker)
            self.assertTrue(result['ok'], result)
            self.assertIn(result['data']['copy'], ['snapshot', 'reflink'])
            self.assertEqual(stat.S_IMODE((destination_root / 'db.sqlite').stat().st_mode), 0o640)
            with contextlib.closing(sqlite3.connect(destination_root / 'db.sqlite')) as database:
                self.assertEqual(database.execute('SELECT count(*) FROM proof').fetchone()[0], 2)
        finally:
            connection.close()

    def test_clone_export_cleanup_and_path_escape_refusal(self):
        directory = '/tmp/orbit-sqlite-clone-' + uuid.uuid4().hex
        try:
            exported = self.call('copy', {'operation': 'export', 'checkout': str(self.source),
                'source': str(self.database), 'directory': directory}, self.worker)
            self.assertTrue(exported['ok'], exported)
            self.assertEqual(stat.S_IMODE(Path(directory).stat().st_mode), 0o700)
            self.assertEqual(stat.S_IMODE((Path(directory) / 'snapshot.sqlite').stat().st_mode), 0o600)
            self.assertTrue(self.call('copy', {'operation': 'cleanup', 'directory': directory}, self.worker)['ok'])
            self.assertFalse(Path(directory).exists())
            result = self.call('copy', {'operation': 'export', 'checkout': str(self.source),
                'source': str(self.source) + '/../foreign', 'directory': directory}, self.worker)
            self.assertFalse(result['ok'])
        finally:
            self.call('copy', {'operation': 'cleanup', 'directory': directory}, self.worker)

    def test_target_cleanup_checks_operation_and_never_removes_replaced_files(self):
        _, prepared = self.seed()
        incoming = Path(prepared['incoming'])
        incoming.unlink()
        incoming.write_bytes(b'foreign')
        os.chown(incoming, self.worker.pw_uid, self.worker.pw_gid)
        os.chmod(incoming, 0o600)
        self.assertFalse(self.call('seedTarget', dict(self.target_input, mode='cleanup'))['ok'])
        self.assertEqual(incoming.read_bytes(), b'foreign')
        incoming.unlink()

    def test_unconfirmed_link_and_failed_script_cleanup_retain_installed_receipt(self):
        from unittest.mock import patch
        sys_path = str(HERE / 'scripts')
        import sys
        sys.path.insert(0, sys_path)
        import sqlite_seed_common as seed
        exported, prepared = self.seed()
        incoming = Path(prepared['incoming'])
        self.write_incoming(incoming, Path(self.source_input['snapshot_path']).read_bytes())
        real_sync = seed.sync_dir
        def fail_sync(selected):
            if Path(selected) == self.target:
                raise OSError('injected after link')
            return real_sync(selected)
        with patch.object(seed, 'sync_dir', fail_sync):
            with self.assertRaises(seed.Unconfirmed):
                seed.target_seed(dict(self.target_input, mode='install'))
        destination = self.target / 'db.sqlite'
        inode = destination.stat().st_ino
        self.assertEqual(seed.target_seed(dict(self.target_input, mode='cleanup'),preserve_installed=True), {'state':'cleaned'})
        self.assertEqual(seed.target_seed(self.target_input), {'state':'complete'})
        self.assertEqual(destination.stat().st_ino, inode)
        self.assertEqual(seed.target_seed(dict(self.target_input, mode='cleanup'),preserve_installed=True), {'state':'cleaned'})
        self.assertEqual(seed.target_seed(self.target_input), {'state':'complete'})
        self.assertEqual(seed.target_seed(dict(self.target_input, mode='cleanup')), {'state':'cleaned'})
        self.assertFalse(Path(self.target_input['state_dir']).exists())
        self.assertEqual(destination.stat().st_ino, inode)


if __name__ == '__main__':
    unittest.main()
