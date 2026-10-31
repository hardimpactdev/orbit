"""Focused native installer policy checks; all file changes stay in owned scratch."""
import hashlib
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

PROGRAM = Path(__file__).with_name('cli_install_program.ts').read_text().split('String.raw`', 1)[1].rsplit('`;', 1)[0]


class CliInstallPolicy(unittest.TestCase):
    def setUp(self):
        self.scope = {'__name__': 'fixture'}
        exec(compile(PROGRAM, 'cli_install_program', 'exec'), self.scope)
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.scope.update(BIN=str(self.root), LINK=str(self.root / 'orbit'))
        self.release = {'version': '0.12.0', 'url': 'https://example.invalid/cli', 'sha256': hashlib.sha256(b'\x7fELFfixture').hexdigest()}

    def test_current_newer_and_source_builds_keep_their_cli(self):
        needs = self.scope['needs']
        self.assertFalse(needs('present', '0.12.0', self.release))
        self.assertFalse(needs('present', '0.13.0', self.release))
        self.assertFalse(needs('present', 'a' * 40, self.release))
        self.assertFalse(needs('present', '0.12.0', None))
        self.assertTrue(needs('present', '0.11.0', self.release))
        self.assertTrue(needs('missing', None, self.release))
        self.assertTrue(needs('stale', '0.12.0', self.release))

    def test_foreign_symlink_is_refused_before_any_binary_runs(self):
        (self.root / 'orbit').symlink_to('/unrelated')
        self.scope['run_cli'] = lambda *args: self.fail('foreign binary executed')
        with self.assertRaisesRegex(self.scope['Refusal'], 'cli.foreign_binary'):
            self.scope['inspect']('orbit')

    def download(self):
        return patch.object(self.scope['urllib'].request, 'build_opener', return_value=type('Download', (), {'open': lambda *args, **kwargs: io.BytesIO(b'\x7fELFfixture')})())

    def test_bad_digest_leaves_current_cli_and_removes_candidate(self):
        (self.root / 'orbit').write_bytes(b'old')
        self.release['sha256'] = '0' * 64
        self.scope['run_cli'] = lambda *args: self.fail('unchecked binary executed')
        with self.download(), self.assertRaisesRegex(self.scope['Refusal'], 'cli.checksum_mismatch'):
            self.scope['publish'](self.release, 'orbit')
        self.assertEqual((self.root / 'orbit').read_bytes(), b'old')
        self.assertEqual(sorted(p.name for p in self.root.iterdir()), ['orbit'])

    def test_verified_candidate_switches_atomically_and_keeps_plain_previous(self):
        (self.root / 'orbit').write_bytes(b'old')
        self.scope['run_cli'] = lambda *args: 'Orbit 0.12.0'
        with self.download(), patch.object(self.scope['os'], 'chown'):
            self.scope['publish'](self.release, 'orbit')
        self.assertEqual((self.root / 'orbit').readlink().as_posix(), 'orbit-0.12.0')
        self.assertEqual((self.root / 'orbit').read_bytes(), b'\x7fELFfixture')
        self.assertEqual((self.root / 'orbit.orbit-previous').read_bytes(), b'old')
        self.assertEqual((self.root / 'orbit-0.12.0').stat().st_mode & 0o777, 0o755)

    def test_wrong_version_leaves_current_cli_and_removes_candidate(self):
        (self.root / 'orbit').write_bytes(b'old')
        self.scope['run_cli'] = lambda *args: 'Orbit 0.13.0'
        with self.download(), patch.object(self.scope['os'], 'chown'), self.assertRaisesRegex(self.scope['Refusal'], 'cli.candidate_invalid'):
            self.scope['publish'](self.release, 'orbit')
        self.assertEqual((self.root / 'orbit').read_bytes(), b'old')
        self.assertEqual(sorted(p.name for p in self.root.iterdir()), ['orbit'])


if __name__ == '__main__':
    unittest.main()
