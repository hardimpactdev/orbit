"""Route withdrawal on the signed executor: route.destroy and the destroy of a Route-owning clone, on the clone fixture.

The VPN Node of the nested fleet is the Gateway (Node 1), so the lane also runs the executor there with the `dns` family,
behind the guard that admits the Gateway's own `orbit` user to the bus."""
import functools
import json
import shlex
import subprocess
import traceback
import time

from clone_lifecycle import EXECUTOR, EXECUTOR_UNIT, TERMINAL, CloneLane, ProofFailure, ProofInfrastructure, require, wait_for

VPN_NODE = 1
GRACE_MS = 31_000
RECORDS = '/etc/dnsmasq.d/orbit-records.conf'
CADDYFILE = '/etc/caddy/Caddyfile'
LEAVES = '/etc/caddy/orbit-certificates'
DNS_QUERY = '''import json, socket, struct, sys
name, server = sys.argv[1], sys.argv[2]
query = struct.pack('>HHHHHH', 0x5a5a, 0x0100, 1, 0, 0, 0) + b''.join(bytes([len(part)]) + part.encode() for part in name.split('.')) + b'\\0' + struct.pack('>HH', 1, 1)
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(3)
sock.sendto(query, (server, 53))
data = sock.recv(1024)
print(json.dumps({'rcode': data[3] & 15, 'answers': struct.unpack('>H', data[6:8])[0]}))'''


def traced(method):
    """Keeps the location of an unexpected error (not a proof verdict) in the evidence; the runner reports only its type."""
    @functools.wraps(method)
    def step(self, *args, **kwargs):
        try:
            return method(self, *args, **kwargs)
        except ProofFailure:
            raise
        except Exception:
            self.run.evidence_text(method.__name__ + '-error.txt', ''.join(traceback.format_exc(limit=8).splitlines(keepends=True)[-12:]))
            raise
    return step


class RouteLane(CloneLane):
    KINDS = ('instance.create', 'instance.destroy', 'route.destroy')
    FAMILIES = 'route\npower\nproof\n'
    # A Route serves a relative web root; laravel/prompts has `src`.
    ROOT = 'src'

    def __init__(self, run, fleet_contract):
        super().__init__(run, fleet_contract)
        self.routes_made = {}
        self.dns = None
        self.gateway_executor = False

    def install(self, staged):
        gateway_paths = ('/etc/orbit/executor', '/var/lib/orbit/executor', EXECUTOR, '/etc/systemd/system/' + EXECUTOR_UNIT)
        self.guest('gateway', 'sh', '-eu', '-c', 'if getent passwd orbit-executor-relay >/dev/null; then exit 1; fi; for path; do test ! -e "$path"; done',
                   'sh', *gateway_paths)
        super().install(staged)
        settings = self.helper('configuration')
        self.gateway_executor = True
        self.guest('gateway', 'useradd', '--system', '--home-dir=/nonexistent', '--shell=/usr/sbin/nologin', 'orbit-executor-relay')
        self.push('gateway', staged, EXECUTOR)
        self.guest('gateway', 'install', '-d', '/etc/orbit/executor')
        self.put('gateway', '/etc/systemd/system/' + EXECUTOR_UNIT, settings['executor_unit'])
        for name, content in {'gateway.pub': settings['executor_keys'], 'node': f'{VPN_NODE}\n', 'nats.json': settings['executor_bus'],
                              'bus-guard.nft': settings['executor_guard_gateway'], 'restore-threads': '32\n'}.items():
            self.put('gateway', '/etc/orbit/executor/' + name, content)
        self.systemctl('gateway', 'daemon-reload')
        self.set_dns(True)

    def helper(self, *args):
        result = self.run.fleet.sh('gateway', shlex.join(['runuser', '-u', 'orbit', '--', 'php', self.guest_helper, *map(str, args)]), timeout=45)
        try:
            return json.loads(result.stdout)
        except ValueError:
            self.run.evidence_text(f'helper-{args[0]}.err', f'exit {result.returncode}\nstderr: {result.stderr[-1500:]}\nstdout: {result.stdout[-300:]}\n')
            raise ProofInfrastructure(f'the guest helper {args[0]} answered no JSON (exit {result.returncode})') from None

    def set_dns(self, enabled):
        if self.dns is not enabled:
            self.put('gateway', '/etc/orbit/executor/enabled', ('dns\n' if enabled else '') + 'proof\n')
            self.systemctl('gateway', 'restart', EXECUTOR_UNIT)
            self.dns = enabled

        def advertised():
            found = self.helper('capabilities', VPN_NODE)
            actions = found['actions']
            return found if 'proof.noop' in actions and ('dns.publish_records' in actions) is enabled else None

        try:
            return wait_for(advertised, f'the VPN Node executor did not advertise dns ({enabled})', 20)
        except ProofFailure:
            self.run.evidence_text('vpn-executor.log', self.guest('gateway', 'sh', '-c', 'systemctl status --no-pager orbit-executor.service | head -5; '
                                                                 'journalctl --no-pager -u orbit-executor.service -n 40 -o cat', timeout=30) + '\n')
            raise

    def uninstall(self):
        if self.gateway_executor:
            self.guest('gateway', 'sh', '-c', '''
                systemctl stop orbit-executor.service 2>/dev/null || :
                nft delete table inet orbit_bus_guard 2>/dev/null || :
                rm -rf /etc/orbit/executor /var/lib/orbit/executor /usr/local/libexec/orbit-executor /etc/systemd/system/orbit-executor.service
                userdel orbit-executor-relay 2>/dev/null || :
                systemctl daemon-reload
            ''')
        super().uninstall()

    def domain(self, name):
        return f'{name}-{self.prefix.removeprefix("proof-clones-")}.proof.test'

    def state(self, domain, scope):
        caddy = self.guest(self.node, 'sh', '-c', 'grep -cF -- "$1" "$2" || :', 'sh', domain, CADDYFILE)
        admin = self.guest(self.node, 'sh', '-c', 'curl --noproxy "*" --silent --max-time 5 http://127.0.0.1:2019/config/ | grep -cF -- "$1" || :', 'sh', domain)
        leaves = self.guest(self.node, 'sh', '-c', 'find "$1" -maxdepth 1 -name "$2" | wc -l', 'sh', LEAVES, scope)
        records = self.guest('gateway', 'sh', '-c', 'grep -cF -- "$1" "$2" || :', 'sh', domain, RECORDS)
        answer = json.loads(self.guest('gateway', 'python3', '-c', DNS_QUERY, domain, self.gateway_address))
        return {'caddy_lines': int(caddy), 'caddy_admin': int(admin) > 0, 'leaf_paths': int(leaves), 'dns_record_lines': int(records),
                'dns_answers': answer['answers'], 'dns_rcode': answer['rcode'], 'https': self.https(domain)}

    @staticmethod
    def present(state):
        return state['caddy_lines'] > 0 and state['caddy_admin'] and state['leaf_paths'] == 1 and state['dns_record_lines'] > 0 and state['dns_answers'] > 0 \
            and state['https'] != '000'

    @staticmethod
    def absent(state):
        return (state['caddy_lines'], state['caddy_admin'], state['leaf_paths'], state['dns_record_lines'], state['dns_answers'], state['https']) \
            == (0, False, 0, 0, 0, '000')

    def https(self, domain):
        response = subprocess.run(['curl', '--noproxy', '*', '--silent', '--output', '/dev/null', '--write-out', '%{http_code}', '--max-time', '5',
                                   '--cacert', self.ca_path, '--resolve', f'{domain}:443:{self.node_ip}', f'https://{domain}/'],
                                  capture_output=True, text=True, timeout=8)
        return response.stdout or '000'

    def healthy(self, name):
        """The sibling keeps its site, leaf and DNS answer, completes a TLS handshake with the nested Orbit root (the engine's own
        sibling probe), and answers the HTTP status it answered when it was created."""
        route = self.routes_made[name]
        state = self.state(route['domain'], route['scope'])
        require(self.present(state) and state['https'] == route['https'], f'sibling Route {name} is not healthy: {state}')
        return state

    @traced
    def route_create(self, name, clone, web_root=None):
        """The clone's own Route, or with `web_root` another site of it: route:destroy removes only such a site, because the
        Instance's own Route goes with the Instance."""
        domain = self.domain(name)
        instance = self.clones[clone]['instance']
        body = {'instance_id': instance, 'domain': domain} | ({'web_root': web_root} if web_root else {})
        row = self.admitted('POST', 'routes', body, name + '-route-create')
        try:
            self.terminal(int(row['id']), seconds=240)
        except ProofFailure:
            self.run.evidence_text(name + '-route-create-failure.log', self.guest(self.node, 'sh', '-c', '''
                grep -n -A12 -F -- "$1" /etc/caddy/Caddyfile | head -40; ls -la /etc/caddy/orbit-certificates
                incus exec --project orbit-instances "instance-$2" -- sh -c 'ls -la /srv/workspace 2>/dev/null | head -30' || :
                journalctl --no-pager -u caddy.service -n 15 -o cat''', 'sh', domain, instance, timeout=40) + '\n')
            raise
        route = self.db(f"SELECT id FROM routes WHERE domain='{domain}'")
        require(len(route) == 1, f'Route {domain} has no record')
        scope = f'route-{int(route[0]["id"])}' if web_root else f'app-instance-{instance}'
        state = wait_for(lambda: self.present(found := self.state(domain, scope)) and found, f'Route {domain} does not answer', 40, interval=1)
        self.routes_made[name] = {'domain': domain, 'route': int(route[0]['id']), 'instance': instance, 'scope': scope, 'create': int(row['id']),
                                  'https': state['https']}
        self.record(name + '-route-create', {**self.routes_made[name], 'state': state})

    def held(self, operation):
        return {table: self.db(f'SELECT count(*) AS n FROM {table} WHERE operation_id={operation}')[0]['n']
                for table in ('route_withdrawal_holds', 'route_publication_holds')}

    def route_journal(self, operation, node):
        if node == VPN_NODE:
            self.node, saved = 'gateway', self.node
            try:
                return self.journal(operation)
            finally:
                self.node = saved
        return self.journal(operation)

    def engine_receipts(self, summary, operation):
        journal = self.route_journal(operation, self.node_id) + self.route_journal(operation, VPN_NODE)
        node_steps = [step for step in summary['steps'] if step['target'] != 'gateway']
        for step in node_steps:
            mine = [entry for entry in journal if entry['operation'] == f'{operation}:{step["name"]}']
            require(sum(entry['record'] == 'open' for entry in mine) == 1 and [entry['outcome']['status'] for entry in mine if entry['record'] == 'done'] in (['applied'], ['done']),
                    f'step {step["name"]} of {operation} did not execute once in the Node journal: {mine}')
        return journal

    @traced
    def route_destroy(self, name, via, sibling, fault=None):
        require(via in ('engine', 'swamp') and fault in (None, 'receipt', 'dns-conflict') and (fault is None or via == 'engine'), 'invalid route destroy case')
        route, domain = self.routes_made[name]['route'], self.routes_made[name]['domain']
        sibling_before = self.healthy(sibling)
        self.set_dns(via == 'engine')
        scope = self.routes_made[name]['scope']
        before = self.state(domain, scope)
        require(self.present(before), f'Route {domain} is not live before its removal')
        path, key = f'routes/{route}', name + '-route-destroy'
        failed = self.dns_conflict(name, path, key, sibling) if fault == 'dns-conflict' else None
        started = time.monotonic()
        if fault == 'receipt':
            admitted, interrupted = self.dropped_withdraw(path, key, domain, scope, sibling)
        else:
            admitted, interrupted = self.admitted('DELETE', path, {}, key), None
        operation = int(admitted['id'])
        sibling_during = self.healthy(sibling)
        self.terminal(operation, seconds=240)
        elapsed = round((time.monotonic() - started) * 1000)
        summary = self.operation(operation, via)
        after = self.state(domain, scope)
        require(self.absent(after) and not self.db(f'SELECT id FROM routes WHERE id={route}') and self.held(operation) == {'route_withdrawal_holds': 0, 'route_publication_holds': 0},
                f'route.destroy {operation} left the Route, its answers or its holds: {after}')
        sibling_after = self.healthy(sibling)
        result = {'route': route, 'domain': domain, 'operation': summary, 'before': before, 'after': after, 'fault': fault,
                  'sibling': {'domain': self.routes_made[sibling]['domain'], 'before': sibling_before, 'during': sibling_during, 'after': sibling_after},
                  'request_through_terminal_ms': elapsed, 'dns_failure': failed, 'interrupted': interrupted}
        if via == 'engine':
            steps = {step['name']: step for step in summary['steps']}
            require(all(step['status'] == 'done' for step in steps.values()) and {'dns-0', 'grace', f'withdraw-{self.node_id}', f'sibling-{self.node_id}'} <= set(steps),
                    f'route.destroy {operation} did not finish its engine steps')
            grace_ms = steps[f'withdraw-{self.node_id}']['first_sent_ms'] - steps['dns-0']['done_ms']
            require(grace_ms >= GRACE_MS, f'route.destroy {operation} withdrew the site {grace_ms} ms after the DNS receipt')
            result['grace_ms'] = grace_ms
            result['journal'] = self.engine_receipts(summary, operation)
            if fault == 'receipt':
                withdraw = steps[f'withdraw-{self.node_id}']
                mine = [entry for entry in result['journal'] if entry['operation'] == f'{operation}:withdraw-{self.node_id}']
                require(withdraw['sends'] >= 2 and sum(entry['record'] == 'seen' for entry in mine) >= 2,
                        f'route.destroy {operation} did not resend the withdrawal and repeat the stored result')
        else:
            require(not summary['steps'], f'fallback route.destroy {operation} ran engine steps')
        self.routes_made[name]['destroy_result'] = result
        self.record(name + '-route-destroy', result)

    def dns_conflict(self, name, path, key, sibling):
        """A change to the live DNS records that the Gateway did not render: the guarded DNS step refuses and writes nothing.
        The site is kept, then the same command converges once the records are the Gateway's again."""
        original = self.guest('gateway', 'cat', RECORDS)
        self.guest('gateway', 'sh', '-eu', '-c', 'printf "# proof: a change the Gateway did not render\\n" >> "$1"', 'sh', RECORDS)
        try:
            row = self.admitted('DELETE', path, {}, key + '-conflict')
            operation = int(row['id'])
            terminal = self.terminal(operation, expect='failed', seconds=120)
            summary = self.operation(operation, 'engine')
            dns = next(step for step in summary['steps'] if step['name'] == 'dns-0')
            kept = self.state(self.routes_made[name]['domain'], self.routes_made[name]['scope'])
            require(dns['status'] == 'failed' and terminal['error_code'] and self.present(kept)
                    and self.db(f"SELECT id FROM routes WHERE domain='{self.routes_made[name]['domain']}'"),
                    f'route.destroy {operation} did not stop at the DNS conflict with the Route kept')
            failure = {'operation': summary, 'error_code': terminal['error_code'], 'failed_step': terminal.get('failed_step'), 'kept': kept,
                       'sibling': self.healthy(sibling)}
        finally:
            self.put('gateway', RECORDS, original + '\n')
        require(self.guest('gateway', 'cat', RECORDS) == original, 'the DNS records were not restored')
        return failure

    def dropped_withdraw(self, path, key, domain, scope, sibling):
        step, action = f'withdraw-{self.node_id}', 'route.withdraw_site'
        self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
        try:
            admitted = self.admitted('DELETE', path, {}, key)
            operation = int(admitted['id'])

            def published():
                rows = [row for row in self.steps(operation) if row['name'] == step]
                return rows[0] if rows and rows[0]['first_sent_ms'] else None

            dispatch = wait_for(published, f'route.destroy {operation} did not publish its withdrawal', 75, interval=0.25)
            require(dispatch['sends'] == 1, f'route.destroy {operation} resent its withdrawal before the fault')
            queued = wait_for(lambda: self.helper('pending-order', self.node_id, operation, self.node_id, step, action),
                              f'route.destroy {operation} had no signed withdrawal order on JetStream', 5)
            self.systemctl('gateway', 'stop', 'orbit-gateway-engine.service')
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, self.node_id, step, action),
                               f'route.destroy {operation} did not leave the expected queued receipt', 30)
            withdrawn = self.state(domain, scope)
            row = self.db(f'SELECT status, lock_key FROM operations WHERE id={operation}')[0]
            replay = self.admitted('DELETE', path, {}, key)
            pending = next(row for row in self.steps(operation) if row['name'] == step)
            # A route Operation takes no Operation lock (`lock_key`, on Swamp as on the engine): its fence is the Route Node hold, from
            # admission to the terminal state (RoutePublicationHolds), and the Route record.
            fence = {'withdrawn_on_node': self.absent(withdrawn), 'operation_open': row['status'] not in TERMINAL, 'nodes_held': sum((holds := self.held(operation)).values()) > 0,
                     'route_kept': bool(self.db(f"SELECT id FROM routes WHERE domain='{domain}'")), 'replay_same_operation': replay['id'] == operation,
                     'step_not_done': pending['status'] != 'done'}
            require(all(fence.values()), f'route.destroy {operation} did not keep its fence after the Node withdrew without a receipt: '
                    + ', '.join(name for name, held in fence.items() if not held) + f' (state {withdrawn}, operation {row})')
            interrupted = {'dispatch': dispatch, 'queued_signed_order': queued, 'dropped': dropped, 'state_without_receipt': withdrawn,
                           'operation_while_fenced': row, 'node_holds_while_fenced': holds, 'sibling_while_fenced': self.healthy(sibling), 'http_replay': {'id': replay['id'], 'status': replay['status']}}
            return admitted, interrupted
        finally:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            self.systemctl('gateway', 'start', 'orbit-gateway-engine.service')

    @traced
    def route_clone_destroy(self, name, via, sibling, route):
        """A clone that owns one private development Route: its withdrawal, then the signed container removal, on one engine."""
        instance, domain, scope = self.clones[name]['instance'], self.routes_made[route]['domain'], self.routes_made[route]['scope']
        sibling_before = self.healthy(sibling)
        self.set_capability(via == 'engine')
        self.set_dns(via == 'engine')
        before = {'route': self.state(domain, scope), 'resources': self.resources(instance)}
        require(self.present(before['route']) and before['resources']['incus'] == ['Running'], f'Route-owning clone {instance} is not live')
        started = time.monotonic()
        admitted = self.admitted('DELETE', f'instances/{instance}', {'force': True}, name + '-destroy')
        operation = int(admitted['id'])
        sibling_during = self.healthy(sibling)
        terminal = self.terminal(operation, seconds=300)
        elapsed = round((time.monotonic() - started) * 1000)
        summary = self.operation(operation, via)
        removal = self.removal(operation)
        after = {'route': self.state(domain, scope), 'resources': self.resources(instance)}
        require(terminal['result']['removed'] and removal['removal_status'] == 'completed' and removal['lock_key'] is None
                and not self.runtime(instance) and self.absent(after['route']) and not any(after['resources'].values())
                and not self.db(f"SELECT id FROM routes WHERE domain='{domain}'") and self.held(operation) == {'route_withdrawal_holds': 0, 'route_publication_holds': 0},
                f'Destroy {operation} did not withdraw the Route, free its holds and remove the clone')
        result = {'instance': instance, 'domain': domain, 'operation': summary, 'removal': removal, 'before': before, 'after': after,
                  'sibling': {'before': sibling_before, 'during': sibling_during, 'after': self.healthy(sibling)}, 'request_through_terminal_ms': elapsed}
        if via == 'engine':
            steps = {step['name']: step for step in summary['steps']}
            require(all(step['status'] == 'done' for step in steps.values())
                    and {f'withdraw-{self.node_id}', f'sibling-{self.node_id}', 'release', 'remove'} <= set(steps)
                    and any(name.startswith('dns-') for name in steps),
                    f'Destroy {operation} did not run the Route withdrawal before the signed removal')
            require(steps['remove']['first_sent_ms'] >= steps[f'withdraw-{self.node_id}']['done_ms'], f'Destroy {operation} removed the container before the withdrawal')
            result['journal'] = self.engine_receipts(summary, operation)
        else:
            require(not summary['steps'], f'Swamp destroy {operation} ran engine steps')
        self.clones[name]['destroy_result'] = result
        self.record(name + '-route-clone-destroy', result)

    def route_compare(self, engine, swamp, kind='route'):
        source = self.routes_made if kind == 'route' else self.clones
        timings = {label: source[name]['destroy_result']['request_through_terminal_ms'] for label, name in (('engine', engine), ('swamp', swamp))}
        signed = source[engine]['destroy_result']['operation']
        comparison = {'kind': kind, 'request_through_terminal_ms': timings, 'swamp_minus_engine_ms': timings['swamp'] - timings['engine'],
                      'engine_steps_ms': {step['name']: step['done_ms'] - (step['first_sent_ms'] or step['done_ms']) for step in signed['steps'] if step['done_ms']},
                      'engine_operation': signed['id'], 'swamp_operation': source[swamp]['destroy_result']['operation']['id'],
                      'swamp_gateway_start_ms': source[swamp]['destroy_result']['operation']['dispatch_timing']}
        self.record(f'{kind}-comparison', comparison)

    def cleanup(self):
        if self.installed and not self.closed and self.project is not None:
            self.set_dns(True)
        super().cleanup()
