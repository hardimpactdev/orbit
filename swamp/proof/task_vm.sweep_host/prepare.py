"""Recreate one legitimately destroyed VM identity on the isolated workload Node."""
import json
import os
import shlex
import subprocess
import sys

PROJECT = 'orbit-proof-sweep'
POOL = 'orbit-proof-sweep'


def execute(node, argv):
    prefix = shlex.split(os.environ['PROOF_EXEC'])
    return subprocess.run([*prefix, node, '--argv=' + json.dumps(argv)], check=True,
                          capture_output=True, text=True, timeout=30).stdout


def prepare(vm_id, host_id, group_id, cancel_id):
    if min(vm_id, host_id, group_id, cancel_id) < 1:
        raise ValueError('proof IDs must come from normal admission')
    operation = json.loads(execute('operator', ['orbit', 'operation:show', str(cancel_id), '--json']))
    captured = operation['input']
    cleanup = operation['result']
    if (operation['kind'] != 'tasks.cancel' or operation['status'] != 'succeeded'
            or operation['target_type'] != 'task_group' or operation['target_id'] != group_id
            or captured['task_vm_id'] != vm_id or captured['node_id'] != host_id
            or cleanup['cleanup_complete'] is not True or cleanup['vm']['destroyed'] is not True
            or cleanup['vm']['task_vm_id'] != vm_id):
        raise ValueError('the real cancellation did not finish this exact VM cleanup')
    name = 'tvm-' + str(vm_id)
    execute('app-dev', ['sudo', '-n', '--', '/usr/bin/incus', '--project', PROJECT,
                        'init', '--empty', '--vm', name, '--storage', POOL, '--no-profiles',
                        '-c', 'user.orbit.proof=task_vm.sweep_host',
                        '-c', 'user.orbit.expires_at=2000-01-01T00:00:00Z'])
    execute('app-dev', ['sudo', '-n', '--', '/usr/bin/incus', '--project', PROJECT,
                        'storage', 'volume', 'create', POOL, name + '-topology-seed'])


if __name__ == '__main__':
    prepare(*(int(value) for value in sys.argv[1:]))
