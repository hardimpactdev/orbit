"""Shared local native workflow fixture; never uses an inherited serve connection."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]


class NativeTestCase(unittest.TestCase):
    native_timeout = 60

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-native-', dir=os.environ.get('TMPDIR'))
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        (self.repo / 'workflows').mkdir()
        self.native_env = {}
        self.secrets = ()

    def copy_behaviors(self, *families):
        for family in families:
            shutil.copytree(ROOT / 'extensions/behaviors' / family,
                            self.repo / 'extensions/behaviors' / family)

    def cli(self, *args, success=True, input=None):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                    'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
            env.pop(key, None)
        env.update(self.native_env)
        result = subprocess.run([os.environ.get('SWAMP_BIN', 'swamp'), *args, '--json'],
                                cwd=self.repo, env=env, input=input, text=True,
                                capture_output=True, timeout=self.native_timeout)
        self.assert_private_absent(result.stdout, result.stderr)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result

    def assert_private_absent(self, *outputs):
        """Scan all scratch files, including native history, logs and source bundles."""
        needles = tuple(value.encode() for value in self.secrets)
        if not needles:
            return
        for output in outputs:
            self.assertFalse(any(needle in output.encode() for needle in needles),
                             'Private bytes in native stdout/stderr')
        overlap = max(map(len, needles)) - 1
        for path in self.repo.rglob('*'):
            if not path.is_file() or path.is_symlink():
                continue
            with path.open('rb') as stream:
                tail = b''
                while block := stream.read(1024 * 1024):
                    data = tail + block
                    self.assertFalse(any(needle in data for needle in needles),
                                     'Private bytes in scratch file: ' + str(path.relative_to(self.repo)))
                    tail = data[-overlap:] if overlap else b''
