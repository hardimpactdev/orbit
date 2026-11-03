"""tasks.provision for a routed Project with runtime definitions: admission captured the workspace runtime.

Group 920 belongs to Project 92, a routed laravel-app with one development Process definition. Admission must
reserve the workspace Route, record the Process, and capture the Route and Process children with only the guest's host keys left for the bind step. The run fails at
the VM step on this topology, and the failure must settle the Operation and the group.
"""
import json
import os
import sqlite3
import sys

GATEWAY = 'apps/gateway'
with open(os.environ['PROOF_OPERATION_FILE']) as source:
    operation = json.load(source)
with open(f'{GATEWAY}/.env') as source:
    environment = dict(line.split('=', 1) for line in source.read().splitlines() if '=' in line)
database = sqlite3.connect(environment['DB_DATABASE'])
database.row_factory = sqlite3.Row


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


def targets(value, found):
    """Every SSH target in a captured intent: a mapping with host_keys."""
    if isinstance(value, dict):
        if 'host_keys' in value:
            found.append(value)
        for item in value.values():
            targets(item, found)
    elif isinstance(value, list):
        for item in value:
            targets(item, found)
    return found


intent = operation.get('input') or {}
check(operation.get('kind') == 'tasks.provision' and operation.get('target_id') == 920, 'the Operation is group 920 provision')
check(operation.get('status') == 'failed', f'the Operation is {operation.get("status")}, not settled failed')
guest = intent.get('guest_node_id')
instance = database.execute('select * from instances where id = ?', (intent.get('instance_id'),)).fetchone()
check(instance is not None and instance['node_id'] == guest, 'the workspace Instance belongs to the guest Node')

route = database.execute('select * from routes where id = ?', (intent.get('route_id'),)).fetchone()
target = database.execute('select instance_id from route_targets where route_id = ?', (intent.get('route_id'),)).fetchone()
check(route is not None and target is not None and target[0] == instance['id'], f'admission reserved no workspace Route: {intent.get("route_id")}')
check(route['status'] == 'pending', f'the Route is {route["status"]}, not pending')

processes = database.execute("select * from processes where owner_type like '%nstance' and owner_id = ?", (instance['id'],)).fetchall()
check([p['name'] for p in processes] == ['worker'], f'the workspace Processes are {[p["name"] for p in processes]}')
members = intent.get('members') or []
check([m['id'] for m in members] == [processes[0]['id']], f'the captured Process members are {members}')

guest_address = database.execute('select wireguard_ip from nodes where id = ?', (guest,)).fetchone()[0]
found = targets({'route': intent.get('route'), 'members': members}, [])
unpinned = [t for t in found if t['host_keys'] is None]
check(unpinned, 'no captured target waits for the guest host key')
check(all(t.get('host') in (None, guest_address) or t.get('address') in (None, guest_address) for t in unpinned),
      'a target other than the guest is unpinned')
check(any(t['host_keys'] for t in found), 'no captured target kept its stored pin')

group = database.execute('select status from tasks where id = 920').fetchone()[0]
check(group == 'failed', f'group 920 is {group}')
print(f'runtime: Route {route["id"]} pending, Process {processes[0]["id"]}, '
      f'{len(unpinned)} guest targets unpinned of {len(found)}')
print('parity: tasks.provision admitted the workspace Route and Process and settled the failed run')
