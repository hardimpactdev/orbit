"""Check the actual isolated sweep run and target, never a scratch replay."""
import json
import os
import shlex
import subprocess
from pathlib import Path


def main():
    outputs = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
    receipt = json.loads(outputs['stdout'])
    if outputs['ready'] is not True or receipt['ready'] is not True:
        raise ValueError('the real sweep did not report ready')
    if set(receipt['deleted_names']) != {'tvm-6', 'tvm-6-topology-seed'} or receipt['observed_names'] != []:
        raise ValueError('the real sweep receipt differs from the isolated resources')
    ssh = shlex.split(os.environ['PROOF_TARGET_SSH'])
    for command, resource in (
        ('sudo -n -- incus --project orbit-proof-sweep list --format=json', 'tvm-6'),
        ('sudo -n -- incus --project orbit-proof-sweep storage volume list orbit-proof-sweep --format=json', 'tvm-6-topology-seed'),
    ):
        result = subprocess.run([*ssh, command], check=True, capture_output=True, text=True, timeout=30)
        rows = json.loads(result.stdout)
        if not isinstance(rows, list) or any(row['name'] == resource for row in rows):
            raise ValueError(resource + ' is not confirmed absent on the real proof target')
    print(json.dumps({'passed': 3, 'failed': 0, 'native_run_id': os.environ['PROOF_RUN_ID']}))


if __name__ == '__main__':
    main()
