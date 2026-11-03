"""Peer DNS without a read-and-decide step (rule 22): the hook and marker persist the policy, one guarded command sets it,
and the run ends by resolving a private name on the orbit link and a public name elsewhere.

The peer keeps the Gateway-rendered hook and a 0600 marker, its orbit link holds the admitted server and routing
domains with no default route, gateway.orbit resolves through orbit and example.com does not, and the Operation
records the applied policy."""
import json
import os
import subprocess
import sys
from pathlib import Path

operation = json.loads(Path(os.environ['PROOF_OPERATION_FILE']).read_text())
intent = operation['input']['intent']
server, domains = intent['server'], intent['domains']


def on(node, *argv):
    return subprocess.run(['incus', 'exec', node, '--', *argv], capture_output=True, text=True, check=False)


def check(condition, message):
    if not condition:
        sys.exit('parity: ' + message)


def setting(name):
    return on('peer', 'resolvectl', name, 'orbit').stdout.split(':', 1)[-1].split()


check(operation['result'] == {'server': server, 'domains': domains}, 'the Operation records the applied policy')
check(server == '10.231.0.201' and intent['probe'] == 'gateway.orbit', 'the intent names the hub DNS server and the private probe')
check(intent['dns_hook'] in on('peer', 'cat', '/etc/wireguard/orbit.conf').stdout.splitlines(), 'orbit.conf keeps the Gateway-rendered hook')
check(on('peer', 'cat', '/etc/wireguard/orbit.dns-link').stdout == '\n'.join(['orbit', server, *domains]) + '\n', 'the marker names the link, server and domains')
check(on('peer', 'stat', '-c', '%a %U', '/etc/wireguard/orbit.dns-link').stdout.strip() == '600 root', 'the marker is 0600 root')
check(setting('dns') == [server], 'the orbit link uses the admitted server')
check(sorted(setting('domain')) == sorted('~' + d for d in domains), 'the orbit link routes exactly the admitted domains')
check(setting('default-route') == ['no'], 'the orbit link is not a default DNS route')
private = on('peer', 'resolvectl', 'query', '--cache=no', 'gateway.orbit').stdout
check('10.231.0.1' in private and '-- link: orbit' in private, 'gateway.orbit resolves through orbit')
public = on('peer', 'resolvectl', 'query', '--cache=no', 'example.com')
check(public.returncode == 0 and '-- link: orbit' not in public.stdout, 'example.com resolves outside orbit')
print('parity: hook and marker kept, admitted policy live on orbit, private name on orbit, public name on the uplink')
