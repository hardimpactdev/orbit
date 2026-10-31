"""Native shared callbacks: success/failure plus whole-scratch credential scan."""
from pathlib import Path
import shutil
import unittest
from native_harness import NativeTestCase
import uuid

ROOT = Path(__file__).resolve().parents[1]

class SharedPrivateMaterialNative(NativeTestCase):
    native_timeout = 50

    def setUp(self):
        super().setUp()
        self.copy_behaviors('automatic-deploy', 'operation-runner', 'operations/_lib')
        (self.repo/'extensions/behaviors/private_material').mkdir()
        shutil.copy(ROOT/'tests/fixtures/private-material/mod.ts', self.repo/'extensions/behaviors/private_material/mod.ts')
        shutil.copy(ROOT/'tests/fixtures/private-material/workflow.yaml', self.repo/'workflows/workflow-private-material-proof.yaml')
        self.secret = 'private-sentinel-'+uuid.uuid4().hex
        self.secrets = (self.secret,)
        self.native_env['ORBIT_PRIVATE_TEST_SENTINEL'] = self.secret
    def test_success_and_secret_bearing_failure_leave_no_native_data_or_logs(self):
        for args, success in [(['workflow','validate','private-material-proof'],True), *[( ['workflow','run','private-material-proof','--input','failure=false','--input','attack='+attack],True) for attack in ['safe','direct','nested','getter','toJSON']], (['workflow','run','private-material-proof','--input','failure=true','--input','attack=safe'],False)]:
            self.cli(*args, success=success)

if __name__=='__main__': unittest.main()
