"""Real scratch filesystem tests; no system trust store or live guest changes."""
import contextlib
import importlib.util
import io
import json
import os
import ssl
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPT = Path(__file__).with_name('image_trust.py')
spec = importlib.util.spec_from_file_location('image_trust', SCRIPT)
trust = importlib.util.module_from_spec(spec)
spec.loader.exec_module(trust)


class ImageTrust(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.certificates = tempfile.TemporaryDirectory(prefix='image-ca-fixture-')
        root = Path(cls.certificates.name)
        subprocess.run(
            ['/usr/bin/openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
             '-keyout', str(root / 'key.pem'), '-out', str(root / 'ca.pem'),
             '-days', '1', '-subj', '/CN=Disposable image CA',
             '-addext', 'basicConstraints=critical,CA:TRUE'],
            check=True, capture_output=True, timeout=15,
        )
        cls.certificate = (root / 'ca.pem').read_text()
        cls.private_key = (root / 'key.pem').read_text()

    @classmethod
    def tearDownClass(cls):
        cls.certificates.cleanup()

    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='image-ca-test-')
        self.root = Path(self.scratch.name)
        self.directory = self.root / 'usr/local/share/ca-certificates'
        self.directory.mkdir(parents=True)
        scratch_root = self.root
        class ScratchPath(type(Path())):
            @property
            def parents(self):
                return tuple(parent for parent in super().parents if parent.is_relative_to(scratch_root))
        self.anchor = ScratchPath(self.directory / 'orbit-root.crt')
        self.patches = [patch.object(trust, 'ANCHOR', self.anchor),
                        patch.object(trust, 'ROOT_UID', os.getuid()),
                        patch.object(trust, 'ROOT_GID', os.getgid())]
        for item in self.patches:
            item.start()
        self.update = patch.object(trust, 'update_trust').start()

    def tearDown(self):
        patch.stopall()
        self.scratch.cleanup()

    def invoke(self, value=None):
        return trust.trust_image_ca(value if value is not None else {'certificate': self.certificate})

    def test_installs_one_valid_public_certificate_and_repeats_trust_refresh(self):
        self.assertEqual(self.invoke(), {'ready': True})
        self.assertEqual(self.anchor.read_text(), self.certificate)
        self.assertEqual(self.anchor.stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.anchor.stat().st_uid, os.getuid())
        ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT).load_verify_locations(cafile=str(self.anchor))
        self.assertEqual(self.invoke(), {'ready': True})
        self.assertEqual(self.update.call_count, 2)
        self.assertEqual(list(self.directory.iterdir()), [self.anchor])

    def test_rejects_malformed_multiple_private_or_oversized_certificates_before_effects(self):
        for certificate in ['', 'not a certificate', self.certificate * 2, self.private_key,
                            self.certificate + self.private_key,
                            '-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n',
                            ' ' * 65537, self.certificate + 'é' * 33000]:
            with self.subTest(certificate=certificate[:30]):
                with self.assertRaises((ValueError, ssl.SSLError)):
                    self.invoke({'certificate': certificate})
                self.assertFalse(self.anchor.exists())
        self.update.assert_not_called()

    def test_certificate_limit_counts_utf8_bytes_and_accepts_exact_limit(self):
        bounded = self.certificate + ' ' * (65536 - len(self.certificate.encode('utf-8')))
        self.assertEqual(self.invoke({'certificate': bounded}), {'ready': True})
        with self.assertRaises(ValueError):
            self.invoke({'certificate': bounded + ' '})
        self.assertEqual(self.anchor.read_text(), bounded)
        self.assertEqual(self.update.call_count, 1)

    def test_rejects_extra_or_wrong_fields(self):
        for value in [{'certificate': self.certificate, 'path': '/other'},
                      {'certificate': None}, {'certificate': 5}, {}, []]:
            with self.assertRaises((ValueError, TypeError)):
                self.invoke(value)
        self.update.assert_not_called()
        self.assertFalse(self.anchor.exists())

    def test_rejects_symlinked_ancestors_and_targets_without_touching_destinations(self):
        for path in [self.root / 'usr', self.directory]:
            saved = self.root / 'saved'
            path.rename(saved)
            path.symlink_to(saved, target_is_directory=True)
            with self.assertRaises(ValueError):
                self.invoke()
            path.unlink()
            saved.rename(path)
        other = self.root / 'unrelated'
        other.write_text('keep')
        self.anchor.symlink_to(other)
        with self.assertRaises(ValueError):
            self.invoke()
        self.assertEqual(other.read_text(), 'keep')
        self.update.assert_not_called()

    def test_rejects_unsafe_directory_modes_and_nonregular_target(self):
        self.directory.chmod(0o777)
        with self.assertRaises(ValueError):
            self.invoke()
        self.directory.chmod(0o755)
        self.anchor.mkdir()
        with self.assertRaises(ValueError):
            self.invoke()
        self.update.assert_not_called()

    def test_refuses_foreign_owned_paths(self):
        with patch.object(trust, 'ROOT_UID', os.getuid() + 1), self.assertRaises(ValueError):
            self.invoke()
        self.assertFalse(self.anchor.exists())
        self.update.assert_not_called()

    def test_target_ownership_is_checked_independently_of_parent_ownership(self):
        self.anchor.write_text('prior certificate')
        original = type(self.anchor).lstat
        def metadata(path, *args, **kwargs):
            result = original(path, *args, **kwargs)
            if path == self.anchor:
                fields = list(result)
                fields[4] = os.getuid() + 1
                return os.stat_result(fields)
            return result
        with patch.object(type(self.anchor), 'lstat', metadata), self.assertRaises(ValueError):
            self.invoke()
        self.assertEqual(self.anchor.read_text(), 'prior certificate')
        self.update.assert_not_called()

    def test_complete_file_is_synced_before_atomic_replacement(self):
        self.anchor.write_text('prior certificate')
        original_replace = trust.os.replace
        original_fsync = trust.os.fsync
        synced = []
        def fsync(descriptor):
            synced.append(os.fstat(descriptor).st_ino)
            original_fsync(descriptor)
        def replace(source, target):
            candidate = Path(source)
            self.assertEqual(self.anchor.read_text(), 'prior certificate')
            self.assertEqual(candidate.read_text(), self.certificate)
            self.assertEqual(candidate.stat().st_mode & 0o777, 0o644)
            self.assertIn(candidate.stat().st_ino, synced)
            original_replace(source, target)
        with patch.object(trust.os, 'fsync', fsync), patch.object(trust.os, 'replace', replace):
            self.assertEqual(self.invoke(), {'ready': True})
        self.assertEqual(self.anchor.read_text(), self.certificate)

    def test_atomic_failure_preserves_old_anchor_and_cleans_staged_file(self):
        self.anchor.write_text('prior certificate')
        with patch.object(trust.os, 'replace', side_effect=OSError('private diagnostic')), self.assertRaises(OSError):
            self.invoke()
        self.assertEqual(self.anchor.read_text(), 'prior certificate')
        self.assertEqual(list(self.directory.iterdir()), [self.anchor])
        self.update.assert_not_called()

    def test_update_failure_never_reports_ready_and_retains_published_anchor_for_retry(self):
        self.update.side_effect = OSError('private diagnostic')
        with self.assertRaises(OSError):
            self.invoke()
        self.assertEqual(self.anchor.read_text(), self.certificate)
        self.update.side_effect = None
        self.assertEqual(self.invoke(), {'ready': True})

    def test_fixed_update_command_discards_diagnostics_and_uses_no_input(self):
        with patch.object(trust.subprocess, 'run') as run:
            # Exercise the actual command, not the publication fixture's command stub.
            ImageTrust.real_update()
        run.assert_called_once_with(
            ['/usr/sbin/update-ca-certificates'], stdin=subprocess.DEVNULL,
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=True, timeout=45,
        )

    def test_entrypoint_rejects_invalid_input_with_fixed_output_and_nonzero_exit(self):
        for value in ['invalid json', json.dumps({'certificate': self.private_key})]:
            result = subprocess.run(
                ['/usr/bin/python3', '-I', str(SCRIPT)], input=value,
                text=True, capture_output=True, timeout=5, check=False,
            )
            self.assertEqual(result.returncode, 1)
            self.assertEqual(result.stderr, '')
            self.assertEqual(json.loads(result.stdout), {'error_code': 'task_vm.image_build_failed'})

    def test_missing_parent_never_creates_host_directories(self):
        self.directory.rmdir()
        with self.assertRaises(FileNotFoundError):
            self.invoke()
        self.assertFalse(self.directory.exists())
        self.update.assert_not_called()

    def test_entrypoint_sanitizes_errors(self):
        with patch.object(trust.sys, 'stdin', io.StringIO(json.dumps({'certificate': self.private_key}))), \
                contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(trust.main(), 1)
        self.assertEqual(json.loads(output.getvalue()), {'error_code': 'task_vm.image_build_failed'})
        self.assertNotIn(self.private_key, output.getvalue())


ImageTrust.real_update = staticmethod(trust.update_trust)
if __name__ == '__main__':
    unittest.main()
