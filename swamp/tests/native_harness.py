"""Shared local native workflow fixture; never uses an inherited serve connection."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]


class NativeTestCase(unittest.TestCase):
    native_timeout = 60

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-native-', dir=os.environ.get('TMPDIR'))
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        (self.repo / 'workflows').mkdir()
        support = self.repo / 'tests/_support'
        support.mkdir(parents=True)
        shutil.copy(ROOT / 'tests/_support/native_direct.ts', support)
        signer = self.repo / 'models/@nckrtl/certificates'
        signer.mkdir(parents=True)
        model = yaml.safe_load((ROOT / 'models/@nckrtl/certificates/orbit-certificate-signing-http.yaml').read_text())
        model['globalArguments']['run'] = "printf '%s\\n' '{\"certificates\":[]}'"
        (signer / 'fixture-http.yaml').write_text(yaml.safe_dump(model))
        self.real_certificate_signing = False
        self.native_env = {}
        self.secrets = ()

    def copy_behaviors(self, *families):
        for family in families:
            shutil.copytree(ROOT / 'extensions/behaviors' / family,
                            self.repo / 'extensions/behaviors' / family)

    def cli(self, *args, success=True, input=None):
        # Existing family tests inject fixtureSign. Preserve that fixed mock
        # boundary for their new native shell step; the dedicated HTTPS test
        # opts into the actual command and its disposable Gateway server.
        if not self.real_certificate_signing:
            for path in (self.repo / 'workflows').glob('*.yaml'):
                workflow = yaml.safe_load(path.read_text())
                changed = False
                for job in workflow.get('jobs', []):
                    for step in job.get('steps', []):
                        task = step.get('task', {})
                        if task.get('behaviorName') == 'orbit-certificate-signing-http':
                            task.setdefault('inputs', {})['run'] = '${{ model["orbit-certificate-signing-http"].input.globalArguments.run }}'
                            changed = True
                if changed:
                    path.write_text(yaml.safe_dump(workflow, sort_keys=False))
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                    'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT',
                    'SWAMP_EXTENSIONS_DIR'):
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
