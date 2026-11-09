"""Exercise the artifact boundary used by Ansible role prerequisites."""
import hashlib
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('orbit_artifact', ROOT / 'ansible/library/orbit_artifact.py')
artifact = importlib.util.module_from_spec(spec)
spec.loader.exec_module(artifact)


class ArtifactTest(unittest.TestCase):
    def download(self, codes, bodies=None, existing=None, algorithm="sha256"):
        with tempfile.TemporaryDirectory() as folder:
            dest = Path(folder) / 'package.deb'
            if existing is not None:
                dest.write_bytes(existing)
            calls = []
            def run(argv):
                index = len(calls)
                calls.append(argv[-1])
                Path(argv[argv.index('--output') + 1]).write_bytes((bodies or [b'pinned', b'pinned'])[index])
                return codes[index], '', ''
            module = SimpleNamespace(params={
                'urls': ['https://mirror.test/pin.deb', 'https://upstream.test/pin.deb'],
                'dest': str(dest), algorithm: hashlib.new(algorithm, b'pinned').hexdigest(),
                'transport_exit_codes': json.loads((ROOT / 'ansible/transport-exit-codes.json').read_text()),
            }, check_mode=False, run_command=run)
            result = artifact.fetch(module)
            self.assertEqual(list(Path(folder).glob('*.artifact-*')), [])
            return result, calls, dest.read_bytes() if dest.exists() else None

    def test_mirror_success_does_not_fetch_upstream(self):
        result, calls, content = self.download([0])
        self.assertTrue(result['published'])
        self.assertEqual(len(calls), 1)
        self.assertEqual(content, b'pinned')

    def test_missing_mirror_dns_and_timeout_fall_back(self):
        for code in [22, 6, 28]:
            with self.subTest(code=code):
                result, calls, content = self.download([code, 0])
                self.assertTrue(result['published'])
                self.assertEqual(calls, ['https://mirror.test/pin.deb', 'https://upstream.test/pin.deb'])
                self.assertEqual(content, b'pinned')

    def test_write_limit_and_unknown_errors_stop(self):
        for code in [23, 63, 1]:
            with self.subTest(code=code):
                result, calls, content = self.download([code])
                self.assertTrue(result['failed'])
                self.assertFalse(result['published'])
                self.assertEqual(len(calls), 1)
                self.assertIsNone(content)

    def test_corrupt_mirror_never_fetches_upstream_or_publishes(self):
        result, calls, content = self.download([0], [b'corrupt'])
        self.assertTrue(result['failed'])
        self.assertFalse(result['attempts'][0]['checksum_valid'])
        self.assertEqual(len(calls), 1)
        self.assertIsNone(content)

    def test_integrity_failure_preserves_existing_destination(self):
        result, calls, content = self.download([0], [b'corrupt'], existing=b'old')
        self.assertTrue(result['failed'])
        self.assertEqual(len(calls), 1)
        self.assertEqual(content, b'old')

    def test_tool_manager_sha512_fetches_keep_transport_only_fallback_and_integrity(self):
        result, calls, content = self.download([22, 0], algorithm='sha512')
        self.assertTrue(result['published'])
        self.assertEqual(len(calls), 2)
        self.assertEqual(content, b'pinned')
        result, calls, content = self.download([0], [b'corrupt'], existing=b'old', algorithm='sha512')
        self.assertTrue(result['failed'])
        self.assertEqual(len(calls), 1)
        self.assertEqual(content, b'old')
        result, calls, content = self.download([], existing=b'pinned', algorithm='sha512')
        self.assertFalse(result['changed'])
        self.assertEqual(calls, [])

    def test_local_publication_failure_stops_and_cleans_staging(self):
        with patch.object(artifact.os, 'replace', side_effect=OSError('write failed')):
            result, calls, content = self.download([0])
        self.assertTrue(result['failed'])
        self.assertEqual(len(calls), 1)
        self.assertIsNone(content)

    def test_matching_cache_makes_no_download_or_change(self):
        result, calls, content = self.download([], existing=b'pinned')
        self.assertFalse(result['changed'])
        self.assertEqual(calls, [])
        self.assertEqual(content, b'pinned')


if __name__ == '__main__':
    unittest.main()
