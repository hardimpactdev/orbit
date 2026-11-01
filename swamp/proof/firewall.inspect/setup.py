"""Prepare only the disposable workload; Gateway rule admission ran in prepare."""
import json
import os
import shlex
import subprocess

subprocess.run([*shlex.split(os.environ['PROOF_EXEC']), 'app-dev', '--argv=' + json.dumps([
    'sudo', '-n', 'bash', '/home/orbit/orbit/swamp/proof/firewall.inspect/target-setup.sh'])], check=True, timeout=60)
