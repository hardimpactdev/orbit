"""Real native version collection preserves current completion content."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
CLI = os.environ.get('SWAMP_BIN', 'swamp')


class NativeRetentionTest(unittest.TestCase):
    def test_history_artifacts_do_not_pin_superseded_versions(self):
        with tempfile.TemporaryDirectory(prefix='native-retention-') as temp:
            repo = Path(temp)
            shutil.copyfile(ROOT / '.swamp.yaml', repo / '.swamp.yaml')
            # Seed a backlog with native automatic collection disabled.
            marker = (repo / '.swamp.yaml').read_text().replace('autoGc: true', 'autoGc: false').replace('automatic: true', 'automatic: false')
            (repo / '.swamp.yaml').write_text(marker)
            extension = repo / 'extensions/behaviors/receipt.ts'
            extension.parent.mkdir(parents=True)
            extension.write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/retention-proof",version:"2026.10.30.1",
resources:{receipt:{description:"Current completion content",schema:z.object({status:z.literal("succeeded"),operationId:z.number()}),lifetime:"infinite",garbageCollection:1}},
methods:{save:{description:"Publish a completion",arguments:z.object({operationId:z.number()}),execute:async(a,c)=>({dataHandles:[await c.writeResource("receipt","completion",{status:"succeeded",operationId:a.operationId})]})}}};
''')
            env = {k: v for k, v in os.environ.items() if not k.startswith('SWAMP_SERVER_') and k not in ('SWAMP_SERVE_URL', 'SWAMP_REPO_DIR')}
            env.update(SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')

            def command(*args):
                result = subprocess.run([CLI, *args, '--repo-dir', str(repo), '--json'], cwd=repo, env=env, capture_output=True, text=True, timeout=60)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                return json.loads(result.stdout)

            command('behavior', 'create', '@nckrtl/retention-proof', 'receipt-proof')
            for operation in (1, 2, 3):
                command('behavior', 'run', 'receipt-proof', 'save', '--input', json.dumps({'operationId': operation}))
            current = next((repo / '.swamp/data').glob('**/completion/3/raw'))
            before = current.read_bytes()
            self.assertTrue((current.parent.parent / '1/raw').exists())
            shutil.copyfile(ROOT / '.swamp.yaml', repo / '.swamp.yaml')
            command('data', 'gc', '--dry-run')
            command('data', 'gc', '--force')
            self.assertFalse((current.parent.parent / '1/raw').exists())
            self.assertFalse((current.parent.parent / '2/raw').exists())
            self.assertEqual(current.read_bytes(), before)
            self.assertEqual(command('data', 'query', 'modelName == "receipt-proof" && name == "completion"', '--select', 'attributes')['results'], [{'status': 'succeeded', 'operationId': 3}])
            # Subsequent writes are bounded by the native write-time policy.
            command('behavior', 'run', 'receipt-proof', 'save', '--input', '{"operationId":4}')
            self.assertFalse(current.exists())
            self.assertTrue((current.parent.parent / '4/raw').exists())


if __name__ == '__main__':
    unittest.main()
