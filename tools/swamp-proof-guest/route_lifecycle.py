"""Route withdrawal on the signed executor: route.destroy and the destroy of a Route-owning clone, on the clone fixture.

The VPN Node of the nested fleet is the Gateway (Node 1), so the lane also runs the executor there with the `dns` family,
behind the guard that admits the Gateway's own `orbit` user to the bus."""
import functools
import hashlib
import json
import shlex
import subprocess
import threading
import time
import traceback

from clone_lifecycle import (
    EXECUTOR,
    EXECUTOR_UNIT,
    GATEWAY_DB,
    POOL,
    TERMINAL,
    CloneLane,
    ProofFailure,
    ProofInfrastructure,
    require,
    wait_for,
)

VPN_NODE = 1
# Node actions that change nothing and keep no journal entry.
READS = ('file.read', 'http.probe', 'account.read')
GRACE_MS = 31_000
RECORDS = '/etc/dnsmasq.d/orbit-records.conf'
CADDYFILE = '/etc/caddy/Caddyfile'
PUBLISH_LOCK = '/etc/caddy/.orbit-publish.lock'
HOLD_UNIT = 'orbit-proof-publish-hold'
LEAVES = '/etc/caddy/orbit-certificates'
# Names no Route withdrawal may change: the Gateway's `.orbit` services and a name under each Instance suffix the VPN Node answers.
UNAFFECTED_NAMES = ('gateway.orbit', 'reverb.orbit', 'swamp.orbit', 'review-check.nested-prod', 'review-check.nested-dev')
DNS_QUERY = '''import json, socket, struct, sys
name, server = sys.argv[1], sys.argv[2]
query = struct.pack('>HHHHHH', 0x5a5a, 0x0100, 1, 0, 0, 0) + b''.join(bytes([len(part)]) + part.encode() for part in name.split('.')) + b'\\0' + struct.pack('>HH', 1, 1)
sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
sock.settimeout(3)
sock.sendto(query, (server, 53))
data = sock.recv(1024)
answers = struct.unpack('>H', data[6:8])[0]
# dnsmasq answers one A record, last in the reply: its address.
print(json.dumps({'rcode': data[3] & 15, 'answers': answers, 'address': socket.inet_ntoa(data[-4:]) if answers else None}))'''


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
    # `host` holds the primitives engine route.destroy composes; `route` keeps route.withdraw_site for a clone's Route steps.
    FAMILIES = 'route\nhost\npower\nproof\n'
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
        # Uninstall deletes the guard table: it must be the proof's own.
        self.guest('gateway', 'sh', '-c', 'if nft list table inet orbit_bus_guard >/dev/null 2>&1; then exit 1; fi')
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

    def set_dns(self, enabled, host=True):
        """The VPN Node's DNS work: `dns` for a clone's Route steps and route.update, and `host` for route.destroy's reads and
        writes. Without `host` it reports only what an executor before the primitives did."""
        host = enabled and host
        if self.dns != (enabled, host):
            self.put('gateway', '/etc/orbit/executor/enabled', ('dns\n' if enabled else '') + ('host\n' if host else '') + 'proof\n')
            self.guest('gateway', 'sh', '-c', 'systemctl reset-failed "$1" 2>/dev/null || :', 'sh', EXECUTOR_UNIT)
            self.systemctl('gateway', 'restart', EXECUTOR_UNIT)
            self.dns = (enabled, host)

        def advertised():
            found = self.helper('capabilities', VPN_NODE)
            actions = found['actions']
            return found if ('proof.noop' in actions and ('dns.publish_records' in actions) is enabled
                             and all((action in actions) is host for action in ('file.read', 'file.ensure', 'config.apply'))) else None

        try:
            return wait_for(advertised, f'the VPN Node executor did not advertise dns ({enabled}) and host ({host})', 20)
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
                'dns_answers': answer['answers'], 'dns_rcode': answer['rcode'], 'dns_address': answer['address'],
                'https': self.https(domain, answer['address'] or self.node_ip)}

    @staticmethod
    def present(state):
        return state['caddy_lines'] > 0 and state['caddy_admin'] and state['leaf_paths'] == 1 and state['dns_record_lines'] > 0 and state['dns_answers'] > 0 \
            and state['dns_address'] == '10.45.0.2' and state['https'] != '000'

    @staticmethod
    def absent(state):
        return (state['caddy_lines'], state['caddy_admin'], state['leaf_paths'], state['dns_record_lines'], state['dns_answers'], state['https']) \
            == (0, False, 0, 0, 0, '000')

    def https(self, domain, address):
        """A request to the address the VPN Node's DNS answers (the Route Node's when it answers none), trusting only the nested root."""
        response = subprocess.run(['curl', '--noproxy', '*', '--silent', '--output', '/dev/null', '--write-out', '%{http_code}', '--max-time', '5',
                                   '--cacert', self.ca_path, '--resolve', f'{domain}:443:{address}', f'https://{domain}/'],
                                  capture_output=True, text=True, timeout=8, check=False)
        return response.stdout or '000'

    def serve_known(self, route):
        """A known-good answer behind the sibling Route: a static page from a responder on the clone's web port, which the fixture
        starts in the disposable clone and which goes with the clone. The package fixture serves no application of its own."""
        token = f"{self.prefix}-route-{route['route']}"
        script = 'mkdir -p /run/orbit-proof-health && printf "%s" "$1" > /run/orbit-proof-health/index.html && ' \
                 'systemd-run --unit orbit-proof-health python3 -m http.server 8080 --bind 0.0.0.0 --directory /run/orbit-proof-health'
        self.incus('exec', f"instance-{route['instance']}", '--project', POOL, '--', 'sh', '-c', script, 'sh', token)
        route['token'] = token
        wait_for(lambda: self.body(route['domain'], self.node_ip) == ('200', token), f"the sibling responder on {route['domain']} did not answer", 30, interval=1)

    def body(self, domain, address):
        response = subprocess.run(['curl', '--noproxy', '*', '--silent', '--write-out', '\n%{http_code}', '--max-time', '5', '--cacert', self.ca_path,
                                   '--resolve', f'{domain}:443:{address}', f'https://{domain}/'], capture_output=True, text=True, timeout=8, check=False)
        text, _, code = response.stdout.rpartition('\n')
        return code or '000', text

    def healthy(self, name):
        """The sibling keeps its site, leaf and DNS answer, and at the address its DNS answer names, trusting only the nested root,
        answers exactly the known page the fixture serves behind it."""
        route = self.routes_made[name]
        if getattr(self, 'project_type', 'laravel-package') == 'laravel-app':
            # The app's own server answers the site: its site, leaf and DNS answer, and its page with HTTP 200 at the address that
            # answer names, trusting only the nested root, show it serves.
            state = self.state(route['domain'], route['scope'])
            page = self.app_page(route['domain'], state['dns_address'] or self.node_ip)
            require(self.present(state) and page['code'] == '200', f'sibling Route {name} is not healthy: {state} {page["code"]}')
            return {**state, 'page': page}
        if 'token' not in route:
            self.serve_known(route)
        state = self.state(route['domain'], route['scope'])
        answer = self.body(route['domain'], state['dns_address'] or self.node_ip)
        require(self.present(state) and answer == ('200', route['token']), f'sibling Route {name} is not healthy: {state} {answer[0]}')
        return {**state, 'known_page': answer[0]}

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
        # Reads and polls (`file.read`, `http.probe`) bypass the executor's journal: their Gateway receipt is the evidence. A link
        # removal that its read made unneeded is done without an order. Every write opened once and journaled its one result.
        for step in (step for step in summary['steps'] if step['target'] != 'gateway' and step['first_sent_ms'] is not None):
            if step['action'] in READS:
                require(step['status'] == 'done' and step['error_code'] is None and step['output'] is not None,
                        f'read step {step["name"]} of {operation} has no successful Gateway receipt: {step}')
                continue
            mine = [entry for entry in journal if entry['operation'] == f'{operation}:{step["name"]}']
            require(sum(entry['record'] == 'open' for entry in mine) == 1 and [entry['outcome']['status'] for entry in mine if entry['record'] == 'done'] in (['applied'], ['done']),
                    f'step {step["name"]} of {operation} did not execute once in the Node journal: {mine}')
        skipped = [step['name'] for step in summary['steps'] if step['target'] != 'gateway' and step['first_sent_ms'] is None]
        require(all(name.startswith(('dnsunlink-', 'caddyunlink-', 'pools-')) for name in skipped), f'{operation} skipped Node steps other than a link removal or an unneeded pool write: {skipped}')
        return journal

    @traced
    def route_destroy(self, name, via, sibling, fault=None):
        if fault == 'projection':
            require(via == 'engine', 'a projection fault runs on the engine')
            self.set_dns(True)
            return self.projection_fault(name, sibling)
        require(via in ('engine', 'swamp') and fault in (None, 'receipt', 'partial', 'feature', 'restart', 'cancel')
                and (fault is None or (fault == 'feature') == (via == 'swamp')), 'invalid route destroy case')
        route, domain = self.routes_made[name]['route'], self.routes_made[name]['domain']
        sibling_before = self.healthy(sibling)
        # A Swamp case either lacks the DNS work (dns family off) or keeps `dns` and lacks only the host primitives (`feature`).
        self.set_dns(via == 'engine' or fault == 'feature', host=via == 'engine')
        scope = self.routes_made[name]['scope']
        before = self.state(domain, scope)
        require(self.present(before), f'Route {domain} is not live before its removal')
        names_before = self.served_names(domain)
        path, key = f'routes/{route}', name + '-route-destroy'
        failed = self.partial_withdrawal(name, path, key, sibling) if fault == 'partial' else None
        cancelled = self.cancelled_in_grace(path, key, domain, scope, route, sibling) if fault == 'cancel' else None
        started = time.monotonic()
        cli = report = None
        if fault == 'receipt':
            admitted, interrupted = self.dropped_withdraw(path, key, domain, scope, sibling)
        elif fault == 'feature':
            admitted, interrupted, report = self.without_feature(path, key)
        elif via == 'engine':
            # The clean removal goes through the candidate CLI: its request, and the Operation its result names.
            admitted, interrupted, cli = self.cli_destroy(['route:destroy', str(route), '--yes'], 'route.destroy', route), None, True
        else:
            admitted, interrupted = self.admitted('DELETE', path, {}, key), None
        operation = int(admitted['id'])
        grace = self.in_grace(operation, domain, scope) if via == 'engine' and fault in (None, 'restart', 'cancel') else None
        restarted = self.restart_in_grace(operation) if fault == 'restart' else None
        sibling_during = self.healthy(sibling)
        self.terminal(operation, seconds=240)
        elapsed = round((time.monotonic() - started) * 1000)
        cli = self.cli_end(operation) if cli else None
        summary = self.operation(operation, via)
        after = self.state(domain, scope)
        require(self.absent(after) and not self.db(f'SELECT id FROM routes WHERE id={route}') and self.held(operation) == {'route_withdrawal_holds': 0, 'route_publication_holds': 0},
                f'route.destroy {operation} left the Route, its answers or its holds: {after}')
        sibling_after = self.healthy(sibling)
        names_after = self.served_names(domain)
        require(names_after == names_before, f'route.destroy {operation} changed another name the VPN Node answers: {names_before} -> {names_after}')
        result = {'route': route, 'domain': domain, 'operation': summary, 'before': before, 'after': after, 'fault': fault,
                  'sibling': {'domain': self.routes_made[sibling]['domain'], 'before': sibling_before, 'during': sibling_during, 'after': sibling_after},
                  'request_through_terminal_ms': elapsed, 'partial_failure': failed, 'interrupted': interrupted, 'in_grace': grace,
                  'cli': cli, 'report_without_feature': report, 'restarted': restarted, 'cancelled_first': cancelled,
                  'served_names': {'before': names_before, 'after': names_after}}
        if via == 'engine':
            steps = {step['name']: step for step in summary['steps']}
            require(all(step['status'] == 'done' for step in steps.values()) and {'dnsread-0-records', 'dnsread-0-catalog', 'dns-0', 'grace', f'caddyread-{self.node_id}',
                    f'guard-{self.node_id}', f'withdraw-{self.node_id}', f'poll-{self.node_id}', f'sibling-{self.node_id}'} <= set(steps),
                    f'route.destroy {operation} did not finish its engine steps')
            # From the last DNS receipt to the real withdrawal dispatch, the Caddyfile write; the read before it waits the grace too.
            last_dns = max(step['done_ms'] for name, step in steps.items() if name.startswith('dns-'))
            grace_ms = steps[f'withdraw-{self.node_id}']['first_sent_ms'] - last_dns
            require(steps[f'caddyread-{self.node_id}']['first_sent_ms'] - last_dns >= GRACE_MS and grace_ms >= GRACE_MS,
                    f'route.destroy {operation} withdrew the site {grace_ms} ms after the DNS receipt')
            result['grace_ms'] = grace_ms
            if fault == 'restart':
                # Resumed from the stored DNS receipt time: not from zero after the restart, and the withdrawal sent once.
                require(grace_ms < GRACE_MS + 5_000 and steps[f'withdraw-{self.node_id}']['sends'] == 1,  # within 36 s, sent once
                        f'route.destroy {operation} did not resume the grace after the restart: {grace_ms} ms, {steps[f"withdraw-{self.node_id}"]["sends"]} sends')
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

    @traced
    def route_main_serves(self, clone):
        """The clone's own Route (the authoritative one, without a web root) is active, still its Instance's only target, and serves."""
        instance = self.clones[clone]['instance']
        rows = self.db(f"""SELECT r.id, r.domain, r.status FROM routes r JOIN route_targets t ON t.route_id=r.id
            WHERE t.instance_id={instance} AND r.web_root IS NULL""")
        require(len(rows) == 1 and rows[0]['status'] == 'active', f'clone {clone} has no one authoritative Route: {rows}')
        state = self.state(rows[0]['domain'], f'app-instance-{instance}')
        page = self.app_page(rows[0]['domain'], state['dns_address'] or self.node_ip)
        # A name inside the TLD has no record line of its own: its site, its leaf and its page with HTTP 200 show that it serves.
        require(state['caddy_lines'] > 0 and state['caddy_admin'] and state['leaf_paths'] == 1 and page['code'] == '200',
                f'the main Route {rows[0]["domain"]} of clone {clone} does not serve: {state} {page["code"]}')
        status = self.db(f'SELECT status FROM instances WHERE id={instance}')[0]['status']
        require(status == 'active', f'clone {clone} is {status}, not active')
        # ORB-6339: the refused delete threw in the projection and the receipt came back forever, each time logged.
        refused = self.log_lines('An active Instance requires exactly one authoritative Route')
        require(refused['lines'] == 0, f'laravel.log records a refused Route delete: {refused}')
        # Each check keeps its own receipt, with the last route.destroy before it, so the order of observations stays readable.
        self.main_checks = getattr(self, 'main_checks', 0) + 1
        last = self.db("SELECT max(id) AS id FROM operations WHERE kind='route.destroy'")[0]['id']
        self.record(f'{clone}-main-route-{self.main_checks}', {'route': rows[0], 'instance_status': status, 'state': state, 'page': page,
                                                              'after_route_destroy': last, 'refused_delete_log_lines': refused['lines']})

    def app_page(self, domain, address):
        """The app's page at `address`, trusting only the nested root: its HTTP status, its size and the digest of its body."""
        code, text = self.body(domain, address)
        return {'code': code, 'bytes': len(text), 'sha256': hashlib.sha256(text.encode()).hexdigest()}

    def log_lines(self, needle):
        """The nested Gateway's current log (the daily channel's newest `laravel*.log`): its size, and how many lines hold `needle`."""
        out = self.guest('gateway', 'sh', '-c', '''log=$(ls -t "$1"/laravel*.log 2>/dev/null | head -1)
            if [ -z "$log" ]; then echo "- 0 0"; exit; fi
            printf '%s %s %s\n' "$log" "$(stat -c %s "$log")" "$(grep -cF -- "$2" "$log" || :)"''', 'sh', self.gateway_app + '/storage/logs', needle).split()
        return {'file': out[0], 'bytes': int(out[1]), 'lines': int(out[2])}

    def projection_fault(self, name, sibling):
        """A completion that always throws: a database trigger refuses the delete of this one Route. The engine must try the
        projection three times, fail the Operation with `engine.projection_failed` at the step `projection`, and then stay quiet."""
        route, domain = self.routes_made[name]['route'], self.routes_made[name]['domain']
        message = f'orbit proof projection fault {route}'
        self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'sqlite3', '-cmd', '.timeout 3000', GATEWAY_DB,
                   f"CREATE TRIGGER orbit_proof_projection_fault BEFORE DELETE ON routes WHEN OLD.id={route} BEGIN SELECT RAISE(ABORT, '{message}'); END;")
        try:
            before = self.log_lines(message)
            admitted = self.admitted('DELETE', f'routes/{route}', {}, name + '-route-destroy-fault')
            operation = int(admitted['id'])
            row = self.terminal(operation, expect='failed', seconds=240)
            ended = self.log_lines(message)
            time.sleep(30)
            quiet = self.log_lines(message)
            summary = self.db(f"""SELECT status, error_code, failed_step, lock_key, json_extract(state,'$.swamp_run_id') AS run,
                json_extract(state,'$.engine.finish_attempts') AS attempts FROM operations WHERE id={operation}""")[0]
            steps = {step['name']: step['status'] for step in self.steps(operation)}
            require(summary['run'] == f'engine-{operation}' and summary['status'] == 'failed' and summary['error_code'] == 'engine.projection_failed'
                    and summary['failed_step'] == 'projection' and summary['lock_key'] is None and summary['attempts'] == 3,
                    f'route.destroy {operation} did not fail once after three projection attempts: {summary}')
            require(steps and all(status == 'done' for status in steps.values()), f'route.destroy {operation} Node steps did not all finish: {steps}')
            # Each attempt reports its exception once: two log lines hold the message.
            require(quiet['lines'] == ended['lines'] and 0 < ended['lines'] - before['lines'] <= 6,
                    f'laravel.log kept growing after route.destroy {operation} failed: {before} {ended} {quiet}')
            require(self.db(f'SELECT id FROM routes WHERE id={route}') != [], f'route.destroy {operation} deleted the Route despite the fault')
        finally:
            self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'sqlite3', '-cmd', '.timeout 3000', GATEWAY_DB,
                       'DROP TRIGGER IF EXISTS orbit_proof_projection_fault;')
        # The documented recovery: the Route record stays, so the same command, once the projection can run, removes it.
        rerun = int(self.admitted('DELETE', f'routes/{route}', {}, name + '-route-destroy-rerun')['id'])
        self.terminal(rerun, seconds=240)
        require(not self.db(f'SELECT id FROM routes WHERE id={route}'), f'route.destroy {rerun} did not remove the Route after the fault')
        sibling_after = self.healthy(sibling)
        result = {'route': route, 'domain': domain, 'operation': operation, 'row_status': row['status'], 'summary': summary, 'steps': steps,
                  'log': {'before': before, 'at_end': ended, 'after_30s': quiet}, 'rerun': {'operation': rerun, 'steps': self.steps(rerun)}, 'sibling_after': sibling_after}
        self.routes_made[name]['destroy_result'] = result
        self.record(name + '-route-destroy-fault', result)

    def cli_destroy(self, words, kind, target):
        """Starts a destroy through the candidate CLI, which waits for the end: the run goes on beside it, and `cli_end` checks
        that the Operation its JSON result names is the one recorded for the target."""
        known = {row['id'] for row in self.db(f"SELECT id FROM operations WHERE kind='{kind}' AND target_id={int(target)}")}
        self.cli_answer = {}
        self.cli_thread = threading.Thread(target=lambda: self.cli_answer.update(self.run.fleet.cli(words, timeout=300)), daemon=True)
        self.cli_thread.start()

        def started():
            rows = [row['id'] for row in self.db(f"SELECT id FROM operations WHERE kind='{kind}' AND target_id={int(target)}") if row['id'] not in known]
            return rows[0] if rows else None

        return {'id': wait_for(started, f'{words[0]} did not start an Operation', 30, interval=0.25)}

    def cli_end(self, operation):
        self.cli_thread.join(320)
        answer = self.cli_answer
        found = answer.get('json') if isinstance(answer.get('json'), dict) else {}
        data = found.get('data') if isinstance(found.get('data'), dict) else found
        require(answer.get('rc') == 0 and data.get('id') == operation and data.get('status') == 'succeeded',
                f'the CLI did not return the terminal Operation {operation} (exit {answer.get("rc")}, id {data.get("id")}, status {data.get("status")})')
        return {'args': answer['args'], 'rc': answer['rc'], 'secs': answer['secs'], 'operation': data.get('id'), 'status': data.get('status')}

    def without_feature(self, path, key):
        """The VPN Node reports dns.publish_records but not the host primitives (an executor before them): admission must take the
        whole removal to Swamp."""
        report = self.set_dns(True, host=False)
        require('dns.publish_records' in report['actions'] and 'file.read' not in report['actions'] and 'config.apply' not in report['actions'],
                'the VPN Node report still lists the host primitives')
        return self.admitted('DELETE', path, {}, key), None, report

    def served_names(self, withdrawn):
        """`dig +short` against the VPN Node for the fixed unaffected names and every other Route's name in its records: the engine
        rewrites the whole records and catalog files, so each must answer, and answer the same before and after."""
        code = '''import re, sys
names = set()
for line in open(sys.argv[1]):
    found = re.match(r"host-record=([^,]+),", line) or re.match(r"address=/([^/.][^/]*)/", line)
    if found and found.group(1) != sys.argv[2]:
        names.add(found.group(1))
print("\\n".join(sorted(names)))'''
        names = sorted({*UNAFFECTED_NAMES, *self.guest('gateway', 'python3', '-c', code, RECORDS, withdrawn).split()})
        answers = {name: self.guest('gateway', 'dig', '+short', '+time=3', '+tries=1', f'@{self.gateway_address}', name).split() for name in names}
        require(all(answers.values()), f'a name the VPN Node serves did not answer: {answers}')
        return answers

    def restart_in_grace(self, operation):
        """Restarts orbit-gateway-engine 12 s into the grace: the step's time and deadline must survive it."""
        done = self.dns_done(operation)['dns-0']['done_ms']
        time.sleep(max(0.0, (done + 12_000) / 1000 - time.time()))
        restart_ms = round(time.time() * 1000)
        self.systemctl('gateway', 'restart', 'orbit-gateway-engine.service')
        withdraw = next(row for row in self.steps(operation) if row['name'] == f'withdraw-{self.node_id}')
        require(withdraw['first_sent_ms'] is None and self.systemctl('gateway', 'is-active', 'orbit-gateway-engine.service') == 'active',
                f'route.destroy {operation} withdrew before the grace ended, or the engine did not come back')
        grace = next(row for row in self.steps(operation) if row['name'] == 'grace')
        current = self.db(f'SELECT current_step FROM operations WHERE id={operation}')[0]['current_step']
        require(current == 'grace', f'route.destroy {operation} reports {current} as its current step during the grace')
        return {'dns_done_ms': done, 'restart_ms': restart_ms, 'restart_after_dns_ms': restart_ms - done, 'grace_row_after_restart': grace,
                'current_step_after_restart': current}

    def cancelled_in_grace(self, path, key, domain, scope, route, sibling):
        """A cancel 10 s into the grace: the Operation ends cancelled, nothing is withdrawn from the Route Node, and nothing is
        later either. The DNS answers stay withdrawn and the Route is kept; the case then destroys it again."""
        operation = int(self.admitted('DELETE', path, {}, key + '-cancel')['id'])
        done = self.dns_done(operation)['dns-0']['done_ms']
        sibling_in_grace = self.healthy(sibling)
        time.sleep(max(0.0, (done + 10_000) / 1000 - time.time()))
        code, row, error = self.api('POST', f'operations/{operation}/cancel', {}, key + '-cancel-request')
        require(code in (200, 202), f'cancel of {operation}: HTTP {code} {error}')
        terminal = self.terminal(operation, expect='cancelled', seconds=60)
        cancelled_ms = round(time.time() * 1000)
        # Past the grace the withdrawal would have run: it must still not have.
        time.sleep(max(0.0, (done + GRACE_MS + 5_000) / 1000 - time.time()))
        left = self.state(domain, scope)
        steps = {step['name']: step for step in self.steps(operation)}
        require(steps[f'withdraw-{self.node_id}']['first_sent_ms'] is None and left['caddy_lines'] > 0 and left['leaf_paths'] == 1 and left['dns_answers'] == 0
                and self.db(f'SELECT id FROM routes WHERE id={route}') and self.held(operation) == {'route_withdrawal_holds': 0, 'route_publication_holds': 0},
                f'route.destroy {operation} withdrew after its cancel, or kept the Route Nodes held: {left}')
        return {'operation': operation, 'status': terminal['status'], 'error_code': terminal.get('error_code'), 'dns_done_ms': done,
                'cancel_after_dns_ms': cancelled_ms - done, 'steps': steps, 'left_after_grace': left, 'sibling_in_grace': sibling_in_grace}

    def dns_done(self, operation):
        def done():
            rows = {row['name']: row for row in self.steps(operation)}
            return rows if rows.get('dns-0', {}).get('status') == 'done' else None
        return wait_for(done, f'route.destroy {operation} did not withdraw its DNS answers', 60, interval=0.25)

    def in_grace(self, operation, domain, scope):
        """Inside the 31 s grace: the answers are gone from the VPN Node while the Route Node still serves the site and its leaf."""
        rows = self.dns_done(operation)
        state = self.state(domain, scope)
        later = {row['name']: row for row in self.steps(operation)}[f'withdraw-{self.node_id}']
        require(state['dns_record_lines'] == 0 and state['dns_answers'] == 0 and state['caddy_lines'] > 0 and state['caddy_admin']
                and state['leaf_paths'] == 1 and state['https'] != '000' and later['first_sent_ms'] is None,
                f'route.destroy {operation} did not keep the site live through the DNS grace: {state}')
        return {'state': state, 'dns_done_ms': rows['dns-0']['done_ms'], 'withdraw_sent_ms_at_read': later['first_sent_ms']}

    def partial_withdrawal(self, name, path, key, sibling):
        """A failure after part of the withdrawal applied: once the DNS step withdrew the answers, the Route Node's Caddyfile gets a
        change the Gateway did not render, so its withdrawal refuses. The answers stay withdrawn and the site stays served; the fault
        alone is then removed, and the same command converges forward."""
        domain, scope = self.routes_made[name]['domain'], self.routes_made[name]['scope']
        original = self.guest(self.node, 'sha256sum', CADDYFILE).split()[0]
        row = self.admitted('DELETE', path, {}, key + '-partial')
        operation = int(row['id'])
        try:
            self.dns_done(operation)
            self.guest(self.node, 'sh', '-eu', '-c', 'printf "# proof: a change the Gateway did not render\\n" >> "$1"', 'sh', CADDYFILE)
            terminal = self.terminal(operation, expect='failed', seconds=120)
        finally:
            self.guest(self.node, 'sh', '-eu', '-c', 'tail -n 1 "$1" | grep -qx "# proof: a change the Gateway did not render" && sed -i "\\$d" "$1" || :',
                       'sh', CADDYFILE)
        require(self.guest(self.node, 'sha256sum', CADDYFILE).split()[0] == original, 'the Caddyfile fault was not removed exactly')
        summary = self.operation(operation, 'engine')
        steps = {step['name']: step for step in summary['steps']}
        left = self.state(domain, scope)
        # The Gateway's guard of the read Caddyfile refuses before any write: the withdrawal is never sent.
        require(steps['dns-0']['status'] == 'done' and steps[f'guard-{self.node_id}']['status'] == 'failed'
                and steps[f'guard-{self.node_id}']['error_code'] == 'owned_revision_conflict' and steps[f'withdraw-{self.node_id}']['first_sent_ms'] is None
                and terminal['error_code'] == 'route.withdraw_failed' and terminal.get('failed_step') == f'Withdraw site, remove cert-{self.node_id}'
                and left['dns_answers'] == 0 and left['dns_record_lines'] == 0 and left['caddy_lines'] > 0
                and self.db(f"SELECT id FROM routes WHERE domain='{domain}'"),
                f'route.destroy {operation} did not stop after its DNS withdrawal with the site kept: {left}')
        return {'operation': summary, 'error_code': terminal['error_code'], 'failed_step': terminal.get('failed_step'), 'left': left,
                'sibling': self.healthy(sibling)}

    def hold_publication(self):
        """Holds the Node's publication lock, which config.apply and route.withdraw_site take (and file.read does not), so the write
        waits inside its action with the executor alive. The lock file must already exist: the fixture creates nothing it would leave."""
        self.guest(self.node, 'test', '-f', PUBLISH_LOCK)
        self.guest(self.node, 'sh', '-c', 'if systemctl is-active --quiet "$1"; then exit 1; fi', 'sh', HOLD_UNIT)
        self.guest(self.node, 'systemd-run', '--unit', HOLD_UNIT, 'flock', PUBLISH_LOCK, 'sleep', '240')
        wait_for(lambda: self.guest(self.node, 'sh', '-c', 'flock -n "$1" true && echo free || echo held', 'sh', PUBLISH_LOCK) == 'held',
                 'the publication lock was not held', 10, interval=0.2)

    def release_publication(self):
        self.guest(self.node, 'sh', '-c', 'systemctl stop "$1" 2>/dev/null; systemctl reset-failed "$1" 2>/dev/null || :', 'sh', HOLD_UNIT)

    def dropped_withdraw(self, path, key, domain, scope, sibling):
        """A lost receipt: the Caddyfile write waits on the held publication lock, a deterministic boundary, until the engine's
        receipt consumer is stopped; then it runs and its receipt is dropped."""
        step, action = f'withdraw-{self.node_id}', 'config.apply'
        self.hold_publication()
        try:
            admitted = self.admitted('DELETE', path, {}, key)
            operation = int(admitted['id'])

            def published():
                rows = [row for row in self.steps(operation) if row['name'] == step]
                return rows[0] if rows and rows[0]['first_sent_ms'] else None

            # The grace (31 s) and the Caddyfile read come first.
            dispatch = wait_for(published, f'route.destroy {operation} did not publish its withdrawal', 90, interval=0.25)
            require(dispatch['sends'] == 1, f'route.destroy {operation} resent its withdrawal before the fault')
            queued = wait_for(lambda: self.helper('pending-order', self.node_id, operation, self.node_id, step, action),
                              f'route.destroy {operation} had no signed withdrawal order on JetStream', 5)
            self.systemctl('gateway', 'stop', 'orbit-gateway-engine.service')
            self.release_publication()
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, self.node_id, step, action),
                               f'route.destroy {operation} did not leave the expected queued receipt', 50)
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
            self.release_publication()
            self.systemctl('gateway', 'start', 'orbit-gateway-engine.service')

    @traced
    def route_clone_destroy(self, name, via, sibling, route, fault=None):
        """A clone that owns one private development Route: its withdrawal, then the signed container removal, on one engine.
        With `boundary`, the withdrawal's receipt is dropped, so the run stops between the withdrawal on the Node and the
        container removal: the fixture reads the live state there, while the clone still exists."""
        require(fault in (None, 'boundary') and (fault is None or via == 'engine'), 'invalid clone destroy case')
        instance, domain, scope = self.clones[name]['instance'], self.routes_made[route]['domain'], self.routes_made[route]['scope']
        sibling_before = self.healthy(sibling)
        self.set_capability(via == 'engine')
        self.set_dns(via == 'engine')
        before = {'route': self.state(domain, scope), 'resources': self.resources(instance)}
        require(self.present(before['route']) and before['resources']['incus'] == ['Running'], f'Route-owning clone {instance} is not live')
        names_before = self.served_names(domain)
        started = time.monotonic()
        boundary = None
        if fault == 'boundary':
            admitted, boundary = self.withdrawal_boundary(instance, name, domain, scope, sibling)
        elif via == 'engine':
            admitted = self.cli_destroy(['instance:destroy', str(instance), '--yes', '--force'], 'instance.destroy', instance)
        else:
            admitted = self.admitted('DELETE', f'instances/{instance}', {'force': True}, name + '-destroy')
        operation = int(admitted['id'])
        sibling_during = self.healthy(sibling)
        terminal = self.terminal(operation, seconds=300)
        elapsed = round((time.monotonic() - started) * 1000)
        cli = self.cli_end(operation) if via == 'engine' and fault is None else None
        summary = self.operation(operation, via)
        removal = self.removal(operation)
        after = {'route': self.state(domain, scope), 'resources': self.resources(instance)}
        require(terminal['result']['removed'] and removal['removal_status'] == 'completed' and removal['lock_key'] is None
                and not self.runtime(instance) and self.absent(after['route']) and not any(after['resources'].values())
                and not self.db(f"SELECT id FROM routes WHERE domain='{domain}'") and self.held(operation) == {'route_withdrawal_holds': 0, 'route_publication_holds': 0},
                f'Destroy {operation} did not withdraw the Route, free its holds and remove the clone')
        names_after = self.served_names(domain)
        require(names_after == names_before, f'Destroy {operation} changed what the VPN Node answers for other names: {names_before} -> {names_after}')
        result = {'instance': instance, 'domain': domain, 'operation': summary, 'removal': removal, 'before': before, 'after': after,
                  'served_names': {'before': names_before, 'after': names_after},
                  'sibling': {'before': sibling_before, 'during': sibling_during, 'after': self.healthy(sibling)}, 'request_through_terminal_ms': elapsed,
                  'cli': cli, 'boundary': boundary}
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

    def withdrawal_boundary(self, instance, name, domain, scope, sibling):
        step, action = f'withdraw-{self.node_id}', 'route.withdraw_site'
        try:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
            admitted = self.admitted('DELETE', f'instances/{instance}', {'force': True}, name + '-destroy')
            operation = int(admitted['id'])

            def published():
                rows = [row for row in self.steps(operation) if row['name'] == step]
                return rows[0] if rows and rows[0]['first_sent_ms'] else None

            wait_for(published, f'Destroy {operation} did not publish its withdrawal', 60, interval=0.25)
            self.systemctl('gateway', 'stop', 'orbit-gateway-engine.service')
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, self.node_id, step, action),
                               f'Destroy {operation} did not leave its withdrawal receipt', 30)
            state, resources = self.state(domain, scope), self.resources(instance)
            steps = {row['name']: row['status'] for row in self.steps(operation)}
            require(self.absent(state) and resources['incus'] == ['Running'] and steps.get('remove') == 'pending'
                    and self.runtime(instance) is not None,
                    f'Destroy {operation} did not stand between the withdrawal and the removal: {state} {resources["incus"]} {steps}')
            capture = {'state_with_clone_present': state, 'container': resources['incus'], 'steps': steps, 'dropped': dropped,
                       'sibling': self.healthy(sibling)}
            return admitted, capture
        finally:
            # On every exit path: a CONT to a running executor changes nothing.
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            self.systemctl('gateway', 'start', 'orbit-gateway-engine.service')

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
