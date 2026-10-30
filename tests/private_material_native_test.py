"""Native shared callbacks: success/failure plus whole-scratch credential scan."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import uuid

ROOT = Path(__file__).resolve().parents[1]
SWAMP = os.environ.get('SWAMP_BIN', 'swamp')

class SharedPrivateMaterialNative(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='shared-private-native-')
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT/'.swamp.yaml', self.repo)
        for name in ['automatic-deploy', 'operation-runner', 'operations/_lib']:
            shutil.copytree(ROOT/'extensions/behaviors'/name, self.repo/'extensions/behaviors'/name)
        (self.repo/'extensions/behaviors/private_material').mkdir()
        shutil.copy(ROOT/'tests/fixtures/private-material/mod.ts', self.repo/'extensions/behaviors/private_material/mod.ts')
        (self.repo/'workflows').mkdir()
        shutil.copy(ROOT/'tests/fixtures/private-material/workflow.yaml', self.repo/'workflows/workflow-private-material-proof.yaml')
        self.secret = 'private-sentinel-'+uuid.uuid4().hex
    def test_success_and_secret_bearing_failure_leave_no_native_data_or_logs(self):
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1', ORBIT_PRIVATE_TEST_SENTINEL=self.secret)
        for key in ['SWAMP_SERVER_URL','SWAMP_SERVE_URL','SWAMP_SERVER_TOKEN','SWAMP_SERVER_TOKEN_FILE','SWAMP_REPO_DIR','SWAMP_CA_CERT']:
            env.pop(key, None)
        for args, success in [(['workflow','validate','private-material-proof'],True), *[( ['workflow','run','private-material-proof','--input','failure=false','--input','attack='+attack],True) for attack in ['safe','direct','nested','getter','toJSON']], (['workflow','run','private-material-proof','--input','failure=true','--input','attack=safe'],False)]:
            result = subprocess.run([SWAMP,*args,'--json'],cwd=self.repo,env=env,text=True,capture_output=True,timeout=50)
            self.assertEqual(result.returncode==0,success,result.stdout+result.stderr)
            self.assertNotIn(self.secret,result.stdout+result.stderr)
            # Includes data, run metadata, logs, reports, bundle sources and every other scratch file.
            for path in self.repo.rglob('*'):
                if path.is_file(): self.assertNotIn(self.secret.encode(),path.read_bytes(),str(path))

if __name__=='__main__': unittest.main()
