"""Schedule contract (ADR 0244): the Schedule's own workflow and trigger on serve, and the command's effect on the target.

Arguments: present, absent, or ran. present also needs the cron the trigger must carry; ran needs the marker file
the command touched on the target. Nothing is ever installed on the target Node.
"""
import json
import os
import subprocess
import sys

operation = json.loads(os.environ['PROOF_OPERATION'])
expected, *detail = (argument.replace('${proof.run}', os.environ['PROOF_RUN']) for argument in sys.argv[1:])
schedule = operation['input']['id']
workflow = 'schedule-' + schedule
STATE = '/home/orbit/.orbit/swamp-proof'


def native(*argv):
    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'SWAMP_HOME': STATE + '/home', 'SWAMP_CONFIG_DIR': STATE + '/config',
           'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1'}
    out = subprocess.run(['/usr/sbin/runuser', '-u', 'orbit', '--', 'swamp', *argv, '--server', 'wss://swamp.orbit:443', '--token-file',
                          STATE + '/credentials/operator', '--ca-cert', STATE + '/tls/ca.pem', '--json'],
                         capture_output=True, text=True, env=env, cwd='/', check=False, timeout=60)
    return out.returncode, out.stdout


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit(f'parity: {message} ({workflow})')


check(operation['status'] == 'succeeded', 'the Operation succeeded')
units = target('sh', '-c', f'ls /etc/systemd/system | grep -c {schedule} || true').stdout.strip()
check(units == '0', 'no unit is installed on the target')
code, out = native('workflow', 'trigger', 'get', workflow)
if expected == 'absent':
    found, _ = native('workflow', 'get', workflow)
    check(found != 0, 'the workflow is gone')
    print(f'parity: {operation["kind"]} leaves no workflow and no trigger for {workflow}')
    sys.exit(0)
check(code == 0, 'the trigger can be read')
override = json.loads(out[out.index('{'):]).get('override') or {}
if expected == 'present':
    check(override.get('schedule') == detail[0], f'the trigger is {override.get("schedule")!r}')
if expected == 'ran':
    check(target('test', '-f', detail[0]).returncode == 0, f'the command touched {detail[0]}')
print(f'parity: {operation["kind"]} leaves {workflow} {expected}')
