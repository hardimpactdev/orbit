#!/usr/bin/python3
"""Run one kind fixture in the minimal proof topology, as root in the task VM.

Prepare and request go through the Gateway API (gateway.php request); native history
comes from the topology's own serve. Prints the receipt body as one JSON line.
"""
import json
import re
import subprocess
import sys
import time
import uuid
from pathlib import Path

SOURCE = Path(sys.argv[1]).resolve()
KIND = sys.argv[2]
STATE = Path('/home/orbit/.orbit/swamp-proof')
TLS = STATE / 'tls/ca.pem'


def run(argv, *, input=None, timeout=300, user=None, cwd=None, env=None, check=True):
    if user:
        argv = ['/usr/sbin/runuser', '-u', user, '--', *argv]
    result = subprocess.run(argv, input=input, capture_output=True, text=True, timeout=timeout, cwd=cwd, env=env, check=False)
    if check and result.returncode:
        raise RuntimeError(f'{" ".join(argv[:8])} exited {result.returncode}: {result.stderr[-2000:]}')
    return result


def bind(value, context):
    if isinstance(value, dict):
        return {key: bind(item, context) for key, item in value.items()}
    if isinstance(value, list):
        return [bind(item, context) for item in value]
    if isinstance(value, str):
        whole = re.fullmatch(r'\$\{([a-z_.]+)\}', value)
        if whole:
            return context[whole[1]]
        return re.sub(r'\$\{([a-z_.]+)\}', lambda match: str(context[match[1]]), value)
    return value


def gateway(request, seconds):
    result = run(['php', str(SOURCE / 'tools/swamp-proof-guest/gateway.php'), 'request'], input=json.dumps({**request, 'seconds': seconds}),
                 user='orbit', cwd=SOURCE / 'apps/gateway', timeout=seconds + 60, check=False)
    try:
        return json.loads(result.stdout.strip().splitlines()[-1])
    except (IndexError, ValueError):
        raise RuntimeError('gateway request produced no reply: ' + result.stderr[-2000:]) from None


def native(*argv):
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'SWAMP_HOME': str(STATE / 'home'),
           'SWAMP_CONFIG_DIR': str(STATE / 'config'), 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1',
           'SWAMP_API_KEY_FILE': '/home/orbit/.config/swamp/club-api-key'}
    out = run(['swamp', *argv, '--server', 'wss://swamp.orbit:443', '--token-file', str(STATE / 'credentials/operator'),
               '--ca-cert', str(TLS), '--json'], user='orbit', env=env, timeout=60, cwd=SOURCE / 'swamp').stdout
    return json.loads(out[out.index('{') if '{' in out else 0:])


def on_target(node, argv, input=None):
    return run(['incus', 'exec', node, '--', *argv], input=input, timeout=60)


def seconds_between(row):
    started, finished = row.get('startedAt'), row.get('completedAt') or row.get('finishedAt')
    if not started or not finished:
        return None
    def parse(value):
        return time.mktime(time.strptime(value[:19], '%Y-%m-%dT%H:%M:%S')) + float('0.' + (value[20:23] or '0'))
    return round(parse(finished) - parse(started), 3)


def steps(history):
    rows = []
    for job in history.get('jobs', []):
        for row in job.get('steps', []):
            rows.append({'job': job.get('name') or job.get('jobName'), 'step': row.get('name') or row.get('stepName'),
                         'status': row.get('status'), 'seconds': seconds_between(row), 'error': row.get('error')})
    return rows


def parity_check(fixture, up, run_id, outputs, operation):
    outputs_file = STATE / f'outputs-{run_id}.json'
    outputs_file.write_text(json.dumps(outputs))
    script, *arguments = [fixture['parity']] if isinstance(fixture['parity'], str) else fixture['parity']
    parity = run(['python3', '-I', str(SOURCE / script), *arguments], check=False, cwd=SOURCE, timeout=120,
                 env={'PATH': '/usr/sbin:/usr/bin:/bin', 'PROOF_OUTPUTS': str(outputs_file), 'PROOF_RESULT': json.dumps(operation and operation.get('result')),
                      'PROOF_TARGET': up['target']['key'], 'PROOF_RUN': run_id, 'PROOF_NODES': json.dumps(up['nodes']),
                      'PROOF_OPERATION': json.dumps(operation)})
    return {'exit': parity.returncode, 'output': (parity.stdout + parity.stderr)[-2000:]}


def main():
    fixture = json.loads((SOURCE / 'swamp/proof' / (KIND + '.json')).read_text())
    up = json.loads((STATE / 'up.json').read_text())
    run_id = str(uuid.uuid4())
    context = {'node.' + key: value for key, value in up['nodes'].items()} | {'proof.run': run_id}
    operator = up['operator_address']
    receipt = {'kind': KIND, 'workflow': fixture['workflow'], 'run': run_id, 'budget_seconds': fixture['budget_seconds'], 'prepare': []}
    # Earlier attempts may have left target state behind; the fixture names what a clean start is.
    for argv in fixture.get('clean_target', []):
        on_target(up['target']['key'], argv)
    for index, raw in enumerate(fixture.get('prepare', [])):
        request = bind(raw, context)
        reply = gateway({**request, 'caller': operator, 'key': f'proof-{run_id}-prepare-{index}'}, fixture['budget_seconds'])
        if request.get('operation', True) is False:
            # A record request; `bind` names its reply so later requests can use `${<bind>.id}`.
            receipt['prepare'].append({'path': request['path'], 'http_status': reply.get('http_status')})
            if not 200 <= (reply.get('http_status') or 0) < 300:
                receipt.update(proof_pass=False, error='prepare failed', prepare_reply=reply)
                return receipt
            if 'bind' in request:
                data = reply['response']['data']
                if isinstance(data, list):
                    data = next(item for item in data if all(item.get(key) == value for key, value in request.get('match', {}).items()))
                context[request['bind'] + '.id'] = data['id']
            continue
        receipt['prepare'].append({'path': request['path'], 'status': reply.get('operation', {}).get('status'),
                                   'error_code': reply.get('operation', {}).get('error_code'), 'seconds': reply.get('terminal_seconds')})
        if reply.get('operation', {}).get('status') != 'succeeded':
            receipt.update(proof_pass=False, error='prepare failed', prepare_reply=reply)
            return receipt
        if 'bind' in request:
            # An Operation request binds its target, such as the Process it created.
            context[request['bind'] + '.id'] = reply['operation']['target_id']
    for argv in fixture.get('before_target', []):
        on_target(up['target']['key'], bind(argv, context))
    reply = gateway({**bind(fixture['request'], context), 'caller': operator, 'key': f'proof-{run_id}-request'}, fixture['budget_seconds'])
    operation = reply.get('operation') or {}
    receipt.update(http_status=reply.get('http_status'), operation=operation, admit_seconds=reply.get('admit_seconds'),
                   total_seconds=reply.get('terminal_seconds'))
    refusal = fixture['expect'].get('error_code')
    if refusal is not None:
        # An admission refusal starts no run: prove the code, that no Operation exists, and the target is unchanged.
        error = (reply.get('response') or {}).get('error') or {}
        receipt.update(refusal={'http_status': reply.get('http_status'), 'error_code': error.get('code'), 'details': error.get('details')},
                       parity=parity_check(fixture, up, run_id, None, None))
        receipt['proof_pass'] = (not operation and reply.get('http_status') == fixture['expect']['http_status']
                                 and error.get('code') == refusal and receipt['parity']['exit'] == 0)
        return receipt
    if not operation.get('swamp_run_id'):
        receipt.update(proof_pass=False, error='no native run was bound', reply=reply)
        return receipt
    history = native('workflow', 'history', 'get', operation['swamp_run_id'])
    outputs = native('workflow', 'history', 'outputs', operation['swamp_run_id'])
    receipt['native'] = {'run_id': operation['swamp_run_id'], 'status': history.get('status'), 'steps': steps(history)}
    phases = []
    for phase in fixture['phases']:
        row = next((item for item in receipt['native']['steps'] if item['job'] == phase['job'] and item['step'] == phase['step']), None)
        phases.append({**phase, 'seconds': row and row['seconds'], 'status': row and row['status'],
                       'passed': bool(row) and row['seconds'] is not None and row['seconds'] <= phase['budget_seconds']})
    receipt['phases'] = phases
    receipt['parity'] = parity_check(fixture, up, run_id, outputs, operation)
    expected = fixture['expect']['status']
    receipt['proof_pass'] = (operation.get('status') == expected and str(history.get('status', '')).lower() == expected
                             and receipt['parity']['exit'] == 0 and all(phase['passed'] for phase in phases)
                             and receipt['total_seconds'] is not None and receipt['total_seconds'] <= fixture['budget_seconds'])
    return receipt


if __name__ == '__main__':
    try:
        print(json.dumps(main()))
    except (RuntimeError, OSError, subprocess.SubprocessError, KeyError, ValueError) as error:
        print(json.dumps({'kind': KIND, 'proof_pass': False, 'error': str(error)}))
        sys.exit(1)
