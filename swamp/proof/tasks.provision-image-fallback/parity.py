"""tasks.provision with the image fallback: admission took the host's older promoted image and recorded it.

The target's only promoted base belongs to an older recipe, promoted one hour ago. Group 910 (default) was admitted
on it; the VM row and the Operation input carry that build. Then two admissions must wait: group 911, which
declared current_recipe, and group 912 once the same image is 25 hours old. Each refusal creates no VM or Operation.
"""
import datetime
import hashlib
import json
import os
import sqlite3
import subprocess
import sys

BUILD = 77
OLD = 'sha256:' + hashlib.sha256(b'proof-older-recipe').hexdigest()
FINGERPRINT = hashlib.sha256(b'proof-image-fallback').hexdigest()
GATEWAY = 'apps/gateway'
operation = json.load(open(os.environ['PROOF_OPERATION_FILE']))
topology = json.load(open('swamp/proof/topologies/minimal/topology.json'))
environment = dict(line.split('=', 1) for line in open(f'{GATEWAY}/.env').read().splitlines() if '=' in line)
database = sqlite3.connect(environment['DB_DATABASE'])
database.row_factory = sqlite3.Row


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message}')


def guest_php(command, payload, seconds=60):
    result = subprocess.run(['runuser', '-u', 'orbit', '--', 'env', '-C', GATEWAY, 'php', '../../tools/swamp-proof-guest/gateway.php', command],
                            input=json.dumps(payload), capture_output=True, text=True, timeout=seconds + 60, check=False)
    return result.stdout.strip().splitlines()[-1] if result.stdout.strip() else result.stderr[-500:]


def provision(group):
    reply = json.loads(guest_php('request', {'caller': topology['operator']['address'], 'method': 'POST', 'path': f'task-groups/{group}/provision',
                                             'body': {}, 'key': f'proof-{os.environ["PROOF_RUN"]}-provision-{group}', 'seconds': 30}))
    error = (reply.get('response') or {}).get('error') or {}
    return reply.get('http_status'), error.get('code'), error.get('message') or '', (error.get('details') or {}).get('reason')


def vms(group):
    return database.execute('select count(*) from task_vms where group_id = ?', (group,)).fetchone()[0]


def operations(group):
    return database.execute("select count(*) from operations where kind = 'tasks.provision' and target_id = ?", (group,)).fetchone()[0]


vm = database.execute('select * from task_vms where group_id = 910').fetchone()
check(vm is not None, 'group 910 has no task VM')
check(vm['image_fallback'] == 1, f'image_fallback is {vm["image_fallback"]}')
check(vm['image_build_id'] == BUILD, f'image_build_id is {vm["image_build_id"]}')
check(vm['recipe_identity'] == OLD, 'the VM records the older recipe identity')
check(vm['image_fingerprint'] == FINGERPRINT, 'the VM records the fallback build fingerprint')
intent = operation.get('input') or {}
check(operation.get('kind') == 'tasks.provision' and operation.get('target_id') == 910, 'the Operation is group 910 provision')
check(intent.get('image_fingerprint') == FINGERPRINT and intent.get('recipe_identity') == OLD, 'the Operation input carries the fallback image')
check((intent.get('create') or {}).get('image') == FINGERPRINT, 'the Incus create intent launches the fallback fingerprint')
image = (operation.get('result') or {}).get('image')
if operation.get('status') == 'succeeded':
    check(image == {'recipe_identity': OLD, 'fingerprint': FINGERPRINT, 'build_id': BUILD, 'fallback': True}, f'the result image is {image}')
print(f'fallback: VM {vm["id"]} build {BUILD} fallback=1, Operation {operation.get("id")} {operation.get("status")} {operation.get("error_code")}')

status, code, message, reason = provision(911)
check((status, code, reason) == (409, 'tasks.capacity_unavailable', 'capacity') and 'waiting for recipe' in message,
      f'current_recipe group answered {status} {code} {message}')
check(vms(911) == 0 and operations(911) == 0, 'the current_recipe refusal recorded a VM or Operation')
print(f'current_recipe: 409 {code}: {message}')

old = (datetime.datetime.now(datetime.UTC) - datetime.timedelta(hours=25)).strftime('%Y-%m-%d %H:%M:%S')
guest_php('fixture', {'task_vm_image_builds': [{'id': BUILD, 'promoted_at': old}]})
check(database.execute('select promoted_at from task_vm_image_builds where id = ?', (BUILD,)).fetchone()[0].startswith(old[:13]), 'promoted_at did not move')
status, code, message, reason = provision(912)
check((status, code, reason) == (409, 'tasks.capacity_unavailable', 'capacity') and 'waiting for recipe' in message,
      f'stale fallback group answered {status} {code} {message}')
check(vms(912) == 0 and operations(912) == 0, 'the stale-image refusal recorded a VM or Operation')
print(f'over a day: 409 {code}: {message}')
print('parity: tasks.provision took the one-hour fallback image and recorded it; current_recipe and a 25-hour image wait')
