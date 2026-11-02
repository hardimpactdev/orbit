"""Hub filter on the real hub: the legacy contract after the tightened chart (2026-11-02).

The live chain holds every expected control and Router rule as nft prints it, the file and the unit equal the intent
with their modes, the unit is enabled and active, UFW holds the control and Router rules, and the Operation reports
the hub ready with the sources from its intent."""
import json
import os
import subprocess
import sys

with open(os.environ['PROOF_OPERATION_FILE']) as file:
    operation = json.load(file)
intent = operation['input']['intent']


def target(*argv):
    return subprocess.run(['incus', 'exec', os.environ['PROOF_TARGET'], '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


result = operation['result']
check(operation['status'] == 'succeeded' and result.get('ready') is True, 'the task reports the hub ready')
check(result.get('control_ssh_source') == intent['source'] == '10.231.0.1', 'the control SSH source is Node 209')
check(result.get('router_sources') == intent['policy']['routers'] == ['10.231.0.12'], 'the Router sources are the proof Router')
check(result.get('router_web_ports') == [80, 443] and result.get('control_ssh_port') == 22, 'the ports are SSH and web only')
chain = target('nft', 'list', 'chain', 'inet', 'orbit_task_vms', 'forward').stdout
check(len(intent['live_rules']) == 3, 'one control rule and two Router web rules are expected')
for rule in intent['live_rules']:
    check('\t\t' + rule + '\n' in chain, 'the live chain holds: ' + rule)
for path, content, mode in (('/etc/orbit/task-vms/hub.nft', intent['nft'], '600 root'),
                            ('/etc/systemd/system/orbit-task-vms-hub.service', intent['unit'], '644 root')):
    check(target('cat', path).stdout == content, path + ' equals the intent')
    check(target('stat', '-c', '%a %U', path).stdout.strip() == mode, path + ' is ' + mode)
check(target('systemctl', 'is-enabled', '--quiet', 'orbit-task-vms-hub.service').returncode == 0, 'the filter unit is enabled')
check(target('systemctl', 'is-active', '--quiet', 'orbit-task-vms-hub.service').returncode == 0, 'the filter unit is active')
ufw = target('ufw', 'status').stdout
for argv in [intent['control_rule'], *intent['router_rules']]:
    check(argv[-1] in ufw, 'UFW holds ' + argv[-1])
print('parity: exact live rules, file, unit, UFW rules and the ready result hold')
