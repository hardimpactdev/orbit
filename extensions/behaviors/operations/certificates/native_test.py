"""Certificate effects recover through GET receipts in a new real Swamp process."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[4]
SWAMP = os.environ.get('SWAMP_BIN', shutil.which('swamp') or 'swamp')


class CertificateNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-certificates-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / '.swamp.yaml', self.repo)
        behavior = self.repo / 'extensions/behaviors'
        for family in ['automatic-deploy', 'operation-runner', 'operations/certificates']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, behavior / family)
        for family, factory, relative in [('operation-runner', 'operationRunner', '../operations/certificates/native_fixture.ts'), ('operations/certificates', 'certificates', './native_fixture.ts')]:
            module = behavior / family / 'mod.ts'
            module.write_text('import { fixtureHttp } from "' + relative + '";\n' + module.read_text().replace('...' + factory + '()', '...' + factory + '(() => fixtureHttp)'))
        (self.repo / 'workflows').mkdir()
        for name in ['operation-runner', 'operation-dispatch', 'operation-certificates']:
            path = ROOT / ('workflows/workflow-' + name + '.yaml')
            source = '\n'.join(line for line in path.read_text().splitlines()
                               if not line.startswith(('labels:', 'queueTimeout:'))) + '\n'
            (self.repo / 'workflows' / path.name).write_text(source)
        self.state = {'operation': {'id': 19, 'kind': 'certificate.converge', 'node_id': 2, 'status': 'queued', 'cancel_requested': False,
            'input': {'scope': 'route-8-router', 'home': '/home/orbit', 'domain': 'private.test', 'version': '0123456789abcdef', 'root_hash': 'a'*64, 'user': 'orbit'}, 'steps': [], 'step_results': {}}, 'effects': [], 'receipts': {}, 'reads': [], 'completions': []}

    def swamp(self, *args, success=True):
        result = subprocess.run([SWAMP, *args, '--json'], cwd=self.repo, capture_output=True, text=True, timeout=45,
            env={**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'})
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)

    def save(self):
        (self.repo / 'native-certificates.json').write_text(json.dumps(self.state))

    def read(self):
        return json.loads((self.repo / 'native-certificates.json').read_text())

    def test_lost_request_reply_reads_same_public_csr_without_generating_another_key(self):
        self.state['loseKey'] = 'certificate-request'
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        self.assertEqual(self.read()['effects'], ['certificate-request'])
        self.swamp('workflow', 'resume', 'operation-certificates', '--from', 'execute')
        state = self.read()
        self.assertEqual(state['effects'].count('certificate-request'), 1)
        self.assertEqual(state['reads'], ['certificate-request'])
        self.assertEqual(state['operation']['status'], 'succeeded')

    def test_lost_publication_reply_reads_receipt_before_inspection(self):
        self.state['loseKey'] = 'certificate-publish'
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        self.swamp('workflow', 'resume', 'operation-certificates', '--from', 'execute')
        state = self.read()
        self.assertEqual(state['effects'], ['certificate-request', 'certificate-sign', 'certificate-publish', 'certificate-inspect'])
        self.assertEqual(state['reads'], ['certificate-publish'])
        self.assertEqual(state['operation']['status'], 'succeeded')

    def test_lost_completion_reply_reuses_completion_with_no_node_effect(self):
        self.state['loseCompletion'] = True
        self.save()
        self.swamp('workflow', 'run', 'operation-runner', success=False)
        before = self.read()['effects']
        self.swamp('workflow', 'resume', 'operation-certificates', '--from', 'execute')
        self.assertEqual(self.read()['effects'], before)
        self.assertEqual(len(self.read()['completions']), 1)


if __name__ == '__main__':
    unittest.main()
