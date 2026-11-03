"""Native park evidence and the real nested Incus VM's stopped state."""
import json
import os
import subprocess

operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
result = operation['result']
assert operation['status'] == 'succeeded', operation['status']
assert result['parked'] is True and result['stop_confirmed'] is True, result
for key in ('group_id', 'task_vm_id', 'instance_id', 'project_id'):
    assert result[key] == operation['input'][key], (key, result)
name = 'tvm-' + str(result['task_vm_id'])
rows = json.loads(subprocess.check_output(['incus', 'list', '--format', 'json', '--', name], text=True))
assert len(rows) == 1 and rows[0]['name'] == name, rows
assert rows[0]['type'] == 'virtual-machine' and rows[0]['status'] == 'Stopped', rows
print('Real nested Incus VM stopped; native provider completion records only the admitted IDs and stop confirmation.')
