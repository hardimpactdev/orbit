"""Check the actual admitted sweep and isolated workload Node through the harness."""
import json
import os
import shlex
import subprocess
import sys
from pathlib import Path


def main(vm_id):
    if vm_id < 1:
        raise ValueError('proof VM ID must come from normal provision')
    name = 'tvm-' + str(vm_id)
    outputs = json.loads(Path(os.environ['PROOF_OUTPUTS']).read_text())
    receipt = json.loads(outputs['stdout'])
    if outputs['ready'] is not True or receipt['ready'] is not True:
        raise ValueError('the real sweep did not report ready')
    if set(receipt['deleted_names']) != {name, name + '-topology-seed'} or receipt['observed_names'] != []:
        raise ValueError('the real sweep touched a different scope or lacks final absence')
    prefix = shlex.split(os.environ['PROOF_EXEC'])
    for argv, resource in (
        (['list', '--format=json'], name),
        (['storage', 'volume', 'list', 'orbit-proof-sweep', '--format=json'], name + '-topology-seed'),
    ):
        command = ['sudo', '-n', '--', '/usr/bin/incus', '--project', 'orbit-proof-sweep', *argv]
        result = subprocess.run([*prefix, 'app-dev', '--argv=' + json.dumps(command)],
                                check=True, capture_output=True, text=True, timeout=30)
        rows = json.loads(result.stdout)
        if not isinstance(rows, list) or any(row['name'] == resource for row in rows):
            raise ValueError(resource + ' is not confirmed absent on the real workload Node')
    print(json.dumps({'passed': 3, 'failed': 0, 'proof_run': os.environ['PROOF_RUN'],
                      'operation_id': os.environ['PROOF_OPERATION_ID']}))


if __name__ == '__main__':
    main(int(sys.argv[1]))
