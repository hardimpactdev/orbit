"""A fake nested fleet: a UFW table per Node, listeners, traffic that follows the table, and a few CLI commands."""
import contextlib
import json
import re
import subprocess
from types import SimpleNamespace
from unittest.mock import patch

BRIDGE = {'gateway': '10.232.2.10', 'app-dev': '10.232.2.11', 'app-prod': '10.232.2.12'}
BRIDGE6 = {'gateway': 'fd00:232::10', 'app-dev': 'fd00:232::11', 'app-prod': 'fd00:232::12'}
NODE_IDS = {'gateway': 209, 'app-dev': 211, 'app-prod': 212}


def completed(code=0, out='', err=''):
    return subprocess.CompletedProcess([], code, out, err)


@contextlib.contextmanager
def outer_machine(home):
    """The runner as the user whose home the operating system gives as `home`. A task VM is `/home/orbit`; an Instance container is
    `/home/agent`. The process of the test never decides."""
    import fleet
    with patch.object(fleet.pwd, 'getpwuid', return_value=SimpleNamespace(pw_dir=home)):
        yield


class FakeBackground:
    def __init__(self, fleet, args):
        self.fleet, self.args, self.running, self.starts = fleet, args, True, True

    def started(self, seconds):
        return self.starts

    def finish(self, seconds):
        self.running = False


class FakeFleet:
    def __init__(self):
        self.nodes = {name: {'instance': 'vm-' + name, 'node_id': NODE_IDS[name], 'bridge': BRIDGE[name]} for name in BRIDGE}
        self.ufw = {name: [('22/tcp', 'ALLOW', 'Anywhere', 'orbit:ssh'), ('Anywhere', 'ALLOW', '10.232.2.0/24', 'proof-network')] for name in BRIDGE}
        self.listeners = {name: set() for name in BRIDGE}   # (protocol, family, port)
        self.tty_calls, self.allow_skipped = [], None
        self.shim, self.fault, self.stalled, self.background = set(), {}, set(), []   # the ufw shim, per Node
        self.ipv6 = {}                  # Node -> its IPv6 address, once ipv6_addresses ran
        self.ipv6_reaches = True
        self.ipv6_allowed = set()
        self.dual_stack = False         # True: a rule for any source also gets an IPv6 line at the end of the table
        self.prompts = None             # the lines a terminal run prints; set by a test
        self.human_status = 'succeeded'
        self.operation_steps = []
        self.records, self.commands, self.cli_calls = [], [], []
        self.allow_effect = True        # False: the command reports success but changes no UFW row
        self.allow_status = 'succeeded'
        self.deny_ignored = False       # True: every listener is open, so the test port is not blocked
        self.failing_cli = None         # a command name that raises
        self.next_id = 1
        self.operation_rows, self.operation_queries = [], []
        self.list_nodes = None          # the Nodes of a `firewall:list` answer, when a test sets them
        self.callers, self.access_removed, self.down = [], [], set()   # the Node a CLI call ran as, `node:access:remove` calls, stopped Nodes

    # ---- shell
    def sh(self, node, command, timeout=60, stdin_text=None):
        self.commands.append((node, command))
        if command.startswith('ufw allow from fd42:232:2::/64 comment'):
            self.ipv6_allowed.add(node)
            return completed()
        if command == 'ufw status numbered':
            return completed(out=self.render(node))
        if command.startswith('ufw insert') or command.startswith('ufw '):
            return self.ufw_command(node, command)
        if command.startswith('sleep '):
            return completed()
        if command == 'ss -Htlun':
            return completed(out=''.join(self.ss_line(*item) for item in sorted(self.listeners[node])))
        if command.startswith('cat > /tmp/proof-listen.py'):
            return completed()
        started = re.match(r'rm -f (\S+); python3 /tmp/proof-listen.py \S+ (tcp|udp) (4|6) ([\d ]+)$', command)
        if started:
            self.listeners[node].update((started[2], int(started[3]), int(port)) for port in started[4].split())
            return completed(out='4242\n')
        local = re.match(r'for port in ([\d ]+); do python3 -c .* (\S+) "\$port" (tcp|udp) (4|6) 3 \|\| echo \$port; done$', command, re.S)
        if local:
            return completed(out=''.join(f'{port}\n' for port in local[1].split() if (local[3], int(local[4]), int(port)) not in self.listeners[node]))
        traffic = re.match(r"timeout (\d+) bash -c 'echo > /dev/tcp/([\d.]+)/(\d+)'", command)
        if traffic:
            return completed(self.connect(node, traffic[2], int(traffic[3]), 'tcp', 4))
        probe = re.match(r'python3 -c .* (\S+) (\d+) (tcp|udp) (4|6) 3$', command, re.S)
        if probe:
            return completed(self.connect(node, probe[1], int(probe[2]), probe[3], int(probe[4])))
        if command.startswith('mkdir -p /var/tmp/proof-ufw'):
            self.shim.add(node)
            return completed()
        if command.startswith("printf 'WORD="):
            word, count = re.search(r"SEEN=0\\n' (\S+) (\d+) >", command).groups()
            self.fault[node] = {'word': word, 'after': int(count), 'seen': 0}
            return completed()
        if command.startswith('systemctl stop orbit-agent'):
            self.down.add(node)
            return completed()
        if command.startswith('kill -- -'):
            return completed()
        if command.startswith('rm -rf /var/tmp/proof-ufw'):
            self.shim.discard(node)
            self.fault.pop(node, None)
            self.stalled.discard(node)
            return completed()
        if command.startswith('touch /var/tmp/proof-ufw/stall'):
            self.stalled.add(node)
            return completed()
        added = re.match(r"ip -6 addr replace (\S+)/64 dev ", command)
        if added:
            self.ipv6[node] = added[1]
            return completed()
        ping = re.match(r'ping -6 -c 1 -W 3 (\S+)$', command)
        if ping:
            return completed(0 if self.ipv6_reaches and ping[1] in self.ipv6.values() else 1)
        route6 = re.match(r'ip -6 route get (\S+)', command)
        if route6:
            return completed(out=f'{route6[1]} dev enp5s0 src {self.ipv6[node]} metric 256 pref medium\n')
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
        fault = self.fault.get(node)
        if fault:
            if fault['seen'] >= fault['after']:
                return completed(1, '', 'ufw: injected fault')
            if fault['word'] in command.split():
                fault['seen'] += 1
        if command.startswith('ufw --force delete '):
            del self.ufw[node][int(command.split()[-1]) - 1]
            return completed()
        v6 = re.match(r"ufw insert (\d+) (\w+) proto (\w+) from ::/0 to ::/0 port (\S+) comment '([^']*)'$", command)
        if v6:
            self.ufw[node].insert(int(v6[1]) - 1, (f'{v6[4]}/{v6[3]} (v6)', v6[2].upper(), 'Anywhere (v6)', v6[5]))
            return completed()
        match = re.match(r"ufw (?:insert (\d+) )?(\w+) proto (\w+) from (\S+) to any port (\S+) comment '([^']*)'$", command)
        if not match:
            return completed(1, '', 'bad ufw command')
        row = (f'{match[5]}/{match[3]}', match[2].upper(), 'Anywhere' if match[4] == 'any' else match[4], match[6])
        if match[1]:
            self.ufw[node].insert(int(match[1]) - 1, row)
        else:
            self.ufw[node].append(row)
        if self.dual_stack and match[4] == 'any':  # ufw adds the IPv6 line at the end of the table
            self.ufw[node].append((f'{match[5]}/{match[3]} (v6)', match[2].upper(), 'Anywhere (v6)', match[6]))
        return completed()

    @staticmethod
    def ss_line(proto, family, port):
        address = f'[::]:{port}' if family == 6 else f'0.0.0.0:{port}'
        return f'{proto} {"LISTEN" if proto == "tcp" else "UNCONN"} 0 16 {address} 0.0.0.0:*\n'

    def connect(self, source, address, port, proto='tcp', family=4):
        book = BRIDGE6 if family == 6 else BRIDGE
        target = source if address in ('127.0.0.1', '::1') else next(name for name, ip in book.items() if ip == address)
        if (proto, family, port) not in self.listeners[target]:
            return 1 if proto == 'tcp' else 111
        if address in ('127.0.0.1', '::1') or self.deny_ignored:
            return 0
        for to, action, origin, _ in self.ufw[target]:
            if to not in (f'{port}/{proto}', 'Anywhere') or origin not in ('Anywhere', book[source], '10.232.2.0/24'):
                continue
            return 0 if action == 'ALLOW' else 124
        return 124

    # ---- CLI
    def prepare_cli_callers(self, items):
        pass   # No guest CLI files in the in-memory fleet.

    def cli(self, args, timeout=240, form='json', node=None):
        self.cli_calls.append(args)
        self.callers.append(node)
        if args[0] == self.failing_cli:
            raise RuntimeError('the CLI did not start')
        if args[0] in ('firewall:allow', 'firewall:deny') and '--node=211' in args and 'app-dev' in self.stalled:
            document = {'error': {'code': 'operation.target_busy', 'message': 'Another operation holds the Node firewall.', 'request_id': 'r'}}
            text = json.dumps(document)
            return {'rc': 1, 'json': document if form == 'json' else None, 'stdout': text if form == 'json' else document['error']['message'] + '\n', 'stderr': '', 'secs': 0.1, 'args': ['orbit', *args]}
        document, code = {}, 0
        if args[0] == 'node:list':
            document = {'nodes': [{'id': node_id, 'name': name} for name, node_id in NODE_IDS.items()]}
        elif args[0] == 'firewall:list':
            document = {'nodes': [{'node_id': 1, 'rules': list(self.records)}] if self.list_nodes is None else
                        [item for item in self.list_nodes if not any(arg.startswith('--node=') for arg in args) or f"--node={item['node_id']}" in args]}
        elif args[0] == 'node:access:remove':
            self.access_removed.append(args)
        elif args[0] == 'operation:show':
            document = {'id': int(args[1]), 'status': self.human_status, 'steps': self.operation_steps}
        elif args[0] == 'firewall:allow':
            document, code = self.allow(args)
        elif args[0] == 'doctor':
            name = next((role for role, number in NODE_IDS.items() if f'--node={number}' in args), 'app-dev')
            drift = sum(1 for record in self.records if record.get('node', 'app-dev') == name and not any(row[:3] == (f"{record['port']}/{record['protocol']}", 'ALLOW', record['source']) for row in self.ufw[name]))
            document, code = {'summary': {'drift': drift}}, 1 if drift else 0
        else:
            raise AssertionError('the fake fleet does not know: ' + ' '.join(args))
        text = json.dumps(document)
        if form == 'human':
            document, text = None, self.human(args, text)
        return {'rc': code, 'json': document, 'stdout': text, 'stderr': '', 'secs': 0.1, 'args': ['orbit', *args] + (['--json'] if form == 'json' else [])}

    def operations(self, kind=None, target_type=None, target_id=None):
        self.operation_queries.append((kind, target_type, target_id))
        rows = [row for row in self.operation_rows if all(value is None or row[key] == value
                for key, value in zip(('kind', 'target_type', 'target_id'), (kind, target_type, target_id)))]
        document = {'data': sorted(rows, key=lambda row: row['id'], reverse=True)[:20]}
        return {'rc': 0, 'json': document, 'stdout': json.dumps(document), 'stderr': ''}

    def human(self, args, text):
        document = json.loads(text)
        if 'error' in document:
            return document['error']['message'] + '\n'
        if 'id' in document:
            return f"\x1b[32mFirewall rule {document['status']}.\x1b[0m\n\nOperation: {document['id']}\n"
        return text

    def cli_background(self, args):
        self.cli_calls.append(args)
        handle = FakeBackground(self, args)
        self.background.append(handle)
        return handle

    def cli_tty(self, args, script, timeout=120):
        """A terminal run: `prompts` is what the command prints; the script is recorded and the command runs as in the human form."""
        self.tty_calls.append((args, script))
        result = self.cli([*args], form='human')
        return {**result, 'stdout': (self.prompts or '') + result['stdout']}

    def allow(self, args):
        if not any(item.startswith('--node=') for item in args):
            return {'error': {'code': 'firewall.node_id_invalid', 'message': 'Node ID must be a positive integer.', 'request_id': None}}, 1
        if not any(item.startswith('--from=') for item in args):
            return {'error': {'code': 'firewall.source_invalid', 'message': 'Source is required.', 'request_id': None}}, 1
        port, source = args[1], next(item[7:] for item in args if item.startswith('--from='))
        name = next((role for role, number in NODE_IDS.items() if f'--node={number}' in args), 'app-dev')
        operation = {'id': self.next_id, 'status': self.allow_status}
        self.operation_rows.append({**operation, 'kind': 'firewall.allow', 'target_type': 'node_firewall', 'target_id': NODE_IDS[name]})
        self.next_id += 1
        if self.allow_status != 'succeeded':
            return operation, 1
        if self.allow_skipped:
            return {**operation, 'result': {'skipped': self.allow_skipped}}, 0
        self.records.append({'id': operation['id'], 'node': name, 'action': 'allow', 'source': source, 'protocol': 'tcp', 'port': port, 'status': 'active'})
        if self.allow_effect:
            first_test_rule = next((index for index, row in enumerate(self.ufw[name]) if row[3].startswith('proof:')), 1)
            self.ufw[name].insert(first_test_rule, (f'{port}/tcp', 'ALLOW', source, 'orbit'))
        return operation, 0
