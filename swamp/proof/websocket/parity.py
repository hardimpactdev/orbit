"""Read the full Operation from PROOF_OPERATION_FILE; run real Reverb checks on the proof Gateway."""
import os
from pathlib import Path
import subprocess
import sys

script = Path(__file__).with_name('check.php')
result = subprocess.run(['php', str(script), 'check', os.environ['PROOF_RUN']], env=os.environ, timeout=30, check=False)
sys.exit(result.returncode)
