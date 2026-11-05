"""A fake nested fleet: a UFW table per Node, listeners, traffic that follows the table, and a few CLI commands."""
import json
import re
import subprocess

BRIDGE = {'gateway': '10.232.2.10', 'app-dev': '10.232.2.11', 'app-prod': '10.232.2.12'}
NODE_IDS = {'gateway': 209, 'app-dev': 211, 'app-prod': 212}


def completed(code=0, out='', err=''):
    return subprocess.CompletedProcess([], code, out, err)


class FakeFleet:
    def __init__(self):
        self.nodes = {name: {'instance': 'vm-' + name, 'node_id': NODE_IDS[name], 'bridge': BRIDGE[name]} for name in BRIDGE}
        self.ufw = {name: [('22/tcp', 'ALLOW', 'Anywhere', 'orbit:ssh'), ('Anywhere', 'ALLOW', '10.232.2.0/24', 'proof-network')] for name in BRIDGE}
        self.listeners = {name: set() for name in BRIDGE}
        self.records, self.commands, self.cli_calls = [], [], []
        self.allow_effect = True        # False: the command reports success but changes no UFW row
        self.allow_status = 'succeeded'
        self.deny_ignored = False       # True: every listener is open, so the test port is not blocked
        self.failing_cli = None         # a command name that raises
        self.next_id = 1

    # ---- shell
    def sh(self, node, command, timeout=60, stdin_text=None):
        self.commands.append((node, command))
        if command == 'ufw status numbered':
            return completed(out=self.render(node))
        if command.startswith('ufw insert') or command.startswith('ufw '):
            return self.ufw_command(node, command)
        if command.startswith('sleep '):
            return completed()
        if command == 'ss -Htln':
            return completed(out=''.join(f'LISTEN 0 16 0.0.0.0:{port} 0.0.0.0:*\n' for port in sorted(self.listeners[node])))
        if command.startswith('cat > /tmp/proof-listen.py'):
            return completed()
        started = re.match(r'rm -f (\S+); python3 /tmp/proof-listen.py (\d+) ', command)
        if started:
            self.listeners[node].add(int(started[2]))
            return completed(out='4242\n')
        traffic = re.match(r"timeout (\d+) bash -c 'echo > /dev/tcp/([\d.]+)/(\d+)'", command)
        if traffic:
            return completed(self.connect(node, traffic[2], int(traffic[3])))
        route = re.match(r'ip -4 route get ([\d.]+)', command)
        if route:
            return completed(out=f'{route[1]} via 10.232.2.1 dev enp5s0 src {BRIDGE[node]} uid 1000\n')
        raise AssertionError('the fake fleet does not know: ' + command)

    def render(self, node):
        lines = ['Status: active', '', '     To                         Action      From', '     --                         ------      ----']
        for number, (to, action, source, comment) in enumerate(self.ufw[node], 1):
            lines.append(f'[{number:>2}] {to:<26} {action} IN     {source:<26}' + (f'# {comment}' if comment else ''))
        return '\n'.join(lines) + '\n'

    def ufw_command(self, node, command):
        if command.startswith('ufw --force delete '):
            del self.ufw[node][int(command.split()[-1]) - 1]
            return completed()
        match = re.match(r"ufw (?:insert (\d+) )?(\w+) proto (\w+) from (\S+) to any port (\S+) comment '([^']*)'$", command)
        if not match:
            return completed(1, '', 'bad ufw command')
        row = (f'{match[5]}/{match[3]}', match[2].upper(), 'Anywhere' if match[4] == 'any' else match[4], match[6])
        if match[1]:
            self.ufw[node].insert(int(match[1]) - 1, row)
        else:
            self.ufw[node].append(row)
        return completed()

    def connect(self, source, address, port):
        target = next(name for name, ip in BRIDGE.items() if ip == address) if address != '127.0.0.1' else source
        if port not in self.listeners[target]:
            return 1
        if address == '127.0.0.1' or self.deny_ignored:
            return 0
        for to, action, origin, _ in self.ufw[target]:
            if to not in (f'{port}/tcp', 'Anywhere') or origin not in ('Anywhere', BRIDGE[source], '10.232.2.0/24'):
                continue
            return 0 if action == 'ALLOW' else 124
        return 124

    # ---- CLI
    def cli(self, args, timeout=240):
        self.cli_calls.append(args)
        if args[0] == self.failing_cli:
            raise RuntimeError('the CLI did not start')
        document, code = {}, 0
        if args[0] == 'node:list':
            document = {'nodes': [{'id': node_id, 'name': name} for name, node_id in NODE_IDS.items()]}
        elif args[0] == 'firewall:list':
            document = {'rules': list(self.records)}
        elif args[0] == 'operation:list':
            document = {'operations': [{'id': number} for number in range(self.next_id - 1)]}
        elif args[0] == 'firewall:allow':
            document, code = self.allow(args)
        elif args[0] == 'doctor':
            drift = sum(1 for record in self.records if not any(row[:3] == (f"{record['port']}/{record['protocol']}", 'ALLOW', record['source']) for row in self.ufw['app-dev']))
            document, code = {'summary': {'drift': drift}}, 1 if drift else 0
        else:
            raise AssertionError('the fake fleet does not know: ' + ' '.join(args))
        return {'rc': code, 'json': document, 'stdout': json.dumps(document), 'stderr': '', 'secs': 0.1, 'args': ['orbit', *args, '--json']}

    def allow(self, args):
        port, source = args[1], next(item[7:] for item in args if item.startswith('--from='))
        operation = {'id': self.next_id, 'status': self.allow_status}
        self.next_id += 1
        if self.allow_status != 'succeeded':
            return operation, 1
        self.records.append({'id': operation['id'], 'node': 'app-dev', 'action': 'allow', 'source': source, 'protocol': 'tcp', 'port': port, 'status': 'active'})
        if self.allow_effect:
            first_test_rule = next(index for index, row in enumerate(self.ufw['app-dev']) if row[3].startswith('proof:'))
            self.ufw['app-dev'].insert(first_test_rule, (f'{port}/tcp', 'ALLOW', source, 'orbit'))
        return operation, 0
