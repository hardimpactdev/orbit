"""An admission refusal creates no run and leaves role records unchanged."""
import json
import os
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from common import ROOT, doctor

assert json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text()) is None
assert os.environ['PROOF_NATIVE_RUN'] == ''
subprocess.run(['runuser', '-u', 'orbit', '--', 'php', str(ROOT / 'swamp/proof/node.role_add/prepare.php'),
                '0', 'verify'], check=True)
doctor(json.loads(os.environ['PROOF_NODES'])['target'])
print('Refusal: no Operation, no native run, no role changes')
