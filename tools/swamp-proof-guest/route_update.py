"""route.update on the signed executor: a private Route's domain change inside the Node's TLD, on the clone fixture.

A name inside the TLD moves no DNS record (private DNS answers the whole TLD), and the fixture's clones serve `src`, which holds no
composer.json, so they write no APP_URL line and no .env: the shape the engine admits. The old name's staging leaf leaves with
route.destroy's `route.withdraw_site`."""
import time

from clone_lifecycle import EXECUTOR_UNIT, TERMINAL, require, wait_for
from route_lifecycle import CADDYFILE, RouteLane, traced

FAULT_LINE = '# proof: a change the Gateway did not render'
PUBLISH_LOCK = '/etc/caddy/.orbit-publish.lock'
HOLD_UNIT = 'orbit-proof-publish-hold'


class UpdateLane(RouteLane):
    KINDS = ('instance.create', 'instance.destroy', 'route.update')

    def __init__(self, run, fleet_contract):
        super().__init__(run, fleet_contract)
        self.route_family = True
        self.tld = None

    def tld_domain(self, name):
        if self.tld is None:
            self.tld = self.db(f'SELECT tld FROM nodes WHERE id={self.node_id}')[0]['tld']
            require(self.tld, 'the Route Node has no TLD')
        return f'{name}-{self.prefix.removeprefix("proof-clones-")}.{self.tld}'

    def in_tld(self, state):
        """A name inside the TLD: served, its leaf in place, and answered by the TLD's record, not a record of its own."""
        return state['caddy_lines'] > 0 and state['caddy_admin'] and state['leaf_paths'] == 1 and state['dns_record_lines'] == 0 \
            and state['dns_answers'] > 0 and state['dns_address'] == self.node_ip and state['https'] != '000'

    def gone(self, state):
        return state['caddy_lines'] == 0 and not state['caddy_admin']

    def set_route_family(self, enabled):
        """The fallback: the Route Node's executor without the `route` family reports no route action, so admission takes Swamp."""
        if self.route_family is not enabled:
            self.put(self.node, '/etc/orbit/executor/enabled', self.FAMILY + '\n' + (self.FAMILIES if enabled else self.FAMILIES.replace('route\n', '')))
            self.guest(self.node, 'sh', '-c', 'systemctl reset-failed "$1" 2>/dev/null || :', 'sh', EXECUTOR_UNIT)
            self.systemctl(self.node, 'restart', EXECUTOR_UNIT)
            self.route_family = enabled
        return wait_for(lambda: (found := self.helper('capabilities', self.node_id)) and 'proof.noop' in found['actions']
                        and ('route.withdraw_site' in found['actions']) is enabled and found, f'the Route Node did not report route ({enabled})', 20)

    @traced
    def route_create_tld(self, name, clone):
        """The clone's own Route under a name inside the Node's TLD. The fixture checks first that no Route has it."""
        domain = self.tld_domain(name)
        require(not self.db(f"SELECT id FROM routes WHERE domain='{domain}'"), f'{domain} already exists')
        instance = self.clones[clone]['instance']
        row = self.admitted('POST', 'routes', {'instance_id': instance, 'domain': domain}, name + '-route-create')
        self.terminal(int(row['id']), seconds=240)
        route = int(self.db(f"SELECT id FROM routes WHERE domain='{domain}'")[0]['id'])
        scope = f'app-instance-{instance}'
        state = wait_for(lambda: self.in_tld(found := self.state(domain, scope)) and found, f'Route {domain} does not answer', 40, interval=1)
        self.routes_made[name] = {'domain': domain, 'route': route, 'instance': instance, 'scope': scope, 'create': int(row['id']), 'https': state['https']}
        self.record(name + '-route-create', {**self.routes_made[name], 'state': state})

    def hold_publication(self):
        """Holds the Node's publication lock, which route.withdraw_site takes (and route.publish_site does not), so the withdrawal waits
        inside its action with the executor alive. The lock file must already exist: the fixture creates nothing it would leave."""
        self.guest(self.node, 'test', '-f', PUBLISH_LOCK)
        self.guest(self.node, 'sh', '-c', 'if systemctl is-active --quiet "$1"; then exit 1; fi', 'sh', HOLD_UNIT)
        self.guest(self.node, 'systemd-run', '--unit', HOLD_UNIT, 'flock', PUBLISH_LOCK, 'sleep', '240')
        wait_for(lambda: self.guest(self.node, 'sh', '-c', 'flock -n "$1" true && echo free || echo held', 'sh', PUBLISH_LOCK) == 'held',
                 'the publication lock was not held', 10, interval=0.2)

    def release_publication(self):
        self.guest(self.node, 'sh', '-c', 'systemctl stop "$1" 2>/dev/null; systemctl reset-failed "$1" 2>/dev/null || :', 'sh', HOLD_UNIT)

    def published(self, operation, step):
        def found():
            rows = [row for row in self.steps(operation) if row['name'] == step]
            return rows[0] if rows and rows[0]['first_sent_ms'] else None
        return wait_for(found, f'route.update {operation} did not publish {step}', 120, interval=0.25)

    def engine_receipts(self, summary, operation):
        """Each Node step opened once and journaled its one result; `tls.ensure_csr` answers `output`, the site steps `applied`."""
        journal = self.route_journal(operation, self.node_id)
        for step in (step for step in summary['steps'] if step['target'] != 'gateway'):
            mine = [entry for entry in journal if entry['operation'] == f'{operation}:{step["name"]}']
            require(sum(entry['record'] == 'open' for entry in mine) == 1
                    and [entry['outcome']['status'] for entry in mine if entry['record'] == 'done'] in (['applied'], ['output']),
                    f'step {step["name"]} of {operation} did not execute once in the Node journal: {mine}')
        return journal

    def admit_update(self, route, domain, key):
        return self.admitted('PATCH', f'routes/{route}', {'domain': domain}, key)

    def dropped_withdraw(self, route, domain, key, sibling):
        """A lost receipt after the cutover: the withdrawal waits on the held publication lock until the engine stops, then runs and its
        receipt is dropped. The same request answers the same Operation, which stays open, and the resent order repeats the journal's
        stored result."""
        step, action = f'withdraw-{self.node_id}', 'route.withdraw_site'
        self.hold_publication()
        try:
            admitted = self.admit_update(route, domain, key)
            operation = int(admitted['id'])
            dispatch = self.published(operation, step)
            require(dispatch['sends'] == 1, f'route.update {operation} resent {step} before the fault')
            self.systemctl('gateway', 'stop', 'orbit-gateway-engine.service')
            self.release_publication()
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, self.node_id, step, action),
                               f'route.update {operation} did not leave the expected queued receipt', 50)
            row = self.db(f'SELECT status FROM operations WHERE id={operation}')[0]
            replay = self.admit_update(route, domain, key)
            steps = {row['name']: row for row in self.steps(operation)}
            fence = {'operation_open': row['status'] not in TERMINAL, 'replay_same_operation': replay['id'] == operation,
                     'step_not_done': steps[step]['status'] != 'done', 'cutover_committed': steps['cutover']['status'] == 'done',
                     'lasting_site_done': steps[f'final-{self.node_id}']['status'] == 'done'}
            require(all(fence.values()), f'route.update {operation} did not keep its fence without a receipt: '
                    + ', '.join(name for name, held in fence.items() if not held))
            return admitted, {'dispatch': dispatch, 'dropped': dropped, 'operation_while_fenced': row, 'fence': fence,
                              'sibling_while_fenced': self.healthy(sibling)}
        finally:
            self.release_publication()
            self.systemctl('gateway', 'start', 'orbit-gateway-engine.service')

    def failed_after_cutover(self, route, old, domain, key, sibling):
        """A failure after the cutover: while the withdrawal waits on the held publication lock (the lasting Caddyfile is installed), the
        Node's Caddyfile gets a change the Gateway did not render, so the withdrawal refuses. The replacement stays authoritative; the
        fault alone is removed, and a retry runs forward."""
        original = None
        self.hold_publication()
        try:
            operation = int(self.admit_update(route, domain, key + '-partial')['id'])
            self.published(operation, f'withdraw-{self.node_id}')
            original = self.guest(self.node, 'sha256sum', CADDYFILE).split()[0]
            require(self.guest(self.node, 'sh', '-c', 'grep -cF -- "$1" "$2" || :', 'sh', old, CADDYFILE) == '0',
                    f'route.update {operation} sent its withdrawal before the lasting Caddyfile')
            self.guest(self.node, 'sh', '-eu', '-c', 'printf "%s\\n" "$2" >> "$1"', 'sh', CADDYFILE, FAULT_LINE)
        finally:
            self.release_publication()
        try:
            terminal = self.terminal(operation, expect='failed', seconds=180)
        finally:
            self.guest(self.node, 'sh', '-eu', '-c', 'tail -n 1 "$1" | grep -qxF -- "$2" && sed -i "\\$d" "$1" || :', 'sh', CADDYFILE, FAULT_LINE)
        require(original is None or self.guest(self.node, 'sha256sum', CADDYFILE).split()[0] == original, 'the Caddyfile fault was not removed exactly')
        summary = self.operation(operation, 'engine')
        steps = {step['name']: step for step in summary['steps']}
        routes = self.db(f"SELECT id,domain,status FROM routes WHERE id={route} OR domain='{domain}' ORDER BY id")
        withdraw = steps[f'withdraw-{self.node_id}']
        require(steps['cutover']['status'] == 'done' and withdraw['status'] == 'failed' and withdraw['error_code'] == 'owned_revision_conflict'
                and [r['status'] for r in routes] == ['retiring', 'active'],
                f'route.update {operation} did not fail after its cutover with the replacement kept authoritative: {routes}')
        return {'operation': summary, 'error_code': terminal['error_code'], 'failed_step': terminal.get('failed_step'), 'routes': routes,
                'sibling': self.healthy(sibling)}

    @traced
    def route_update(self, name, to, via, sibling, fault=None):
        require(via in ('engine', 'swamp') and fault in (None, 'receipt', 'partial') and (fault is None or via == 'engine'), 'invalid route update case')
        made = self.routes_made[name]
        route, old, scope = made['route'], made['domain'], made['scope']
        new = self.tld_domain(to)
        require(not self.db(f"SELECT id FROM routes WHERE domain='{new}'"), f'{new} already exists')
        sibling_before = self.healthy(sibling)
        self.set_route_family(via == 'engine')
        before = {'old': self.state(old, scope), 'new': self.state(new, scope)}
        require(self.in_tld(before['old']) and self.gone(before['new']), f'Route {old} is not live alone before its update')
        key = f'{name}-{to}-route-update'
        failed = self.failed_after_cutover(route, old, new, key, sibling) if fault == 'partial' else None
        started = time.monotonic()
        cli = interrupted = None
        if fault == 'receipt':
            admitted, interrupted = self.dropped_withdraw(route, new, key, sibling)
        elif fault == 'partial':
            # The forward retry: the same command again, with the fault removed.
            admitted = self.admit_update(route, new, key + '-retry')
        elif via == 'engine':
            admitted, cli = self.cli_destroy(['route:update', str(route), '--domain=' + new], 'route.update', route), True
        else:
            admitted = self.admit_update(route, new, key)
        operation = int(admitted['id'])
        sibling_during = self.healthy(sibling)
        self.terminal(operation, seconds=300)
        elapsed = round((time.monotonic() - started) * 1000)
        cli = self.cli_end(operation) if cli else None
        summary = self.operation(operation, via)
        after = {'old': self.state(old, scope), 'new': self.state(new, scope)}
        replacement = self.db(f"SELECT id,status FROM routes WHERE domain='{new}'")
        staging = self.guest(self.node, 'sh', '-c', 'find "$1" -maxdepth 1 -name "$2" | wc -l', 'sh', '/etc/caddy/orbit-certificates', scope + '-hostname-change')
        require(self.in_tld(after['new']) and self.gone(after['old']) and [r['status'] for r in replacement] == ['active']
                and not self.db(f"SELECT id FROM routes WHERE id={route}") and staging == '0',
                f'route.update {operation} did not move {old} to {new}: {after} {replacement} staging {staging}')
        result = {'route': route, 'replacement': replacement[0]['id'], 'old': old, 'new': new, 'operation': summary, 'before': before, 'after': after,
                  'fault': fault, 'sibling': {'domain': self.routes_made[sibling]['domain'], 'before': sibling_before, 'during': sibling_during,
                                              'after': self.healthy(sibling)},
                  'request_through_terminal_ms': elapsed, 'partial_failure': failed, 'interrupted': interrupted, 'cli': cli}
        if via == 'engine':
            steps = {step['name']: step for step in summary['steps']}
            require(all(step['status'] == 'done' for step in steps.values())
                    and {f'final-{self.node_id}', f'withdraw-{self.node_id}', f'sibling-{self.node_id}'} <= set(steps), f'route.update {operation} did not finish its engine steps')
            if fault == 'partial':
                require('cutover' not in steps and not any(name.startswith(('csr-', 'sign-', 'site-', 'probe-')) for name in steps),
                        f'the retry {operation} ran pre-cutover steps')
            else:
                require({'cutover', f'site-{self.node_id}', 'probe-0'} <= set(steps)
                        and steps[f'final-{self.node_id}']['first_sent_ms'] >= steps['cutover']['done_ms'] >= steps['probe-0']['done_ms'],
                        f'route.update {operation} did not probe before its cutover and write the lasting site after it')
            result['journal'] = self.engine_receipts(summary, operation)
            if fault == 'receipt':
                withdraw = steps[f'withdraw-{self.node_id}']
                mine = [entry for entry in result['journal'] if entry['operation'] == f'{operation}:withdraw-{self.node_id}']
                require(withdraw['sends'] >= 2 and sum(entry['record'] == 'seen' for entry in mine) >= 2,
                        f'route.update {operation} did not resend the withdrawal and repeat the stored result')
        made['domain'], made['route'] = new, int(replacement[0]['id'])
        made.setdefault('update_results', {})[to] = result
        self.record(f'{name}-{to}-route-update', result)
        self.set_route_family(True)

    def update_compare(self, engine, swamp):
        found = {label: next(result for made in self.routes_made.values() for key, result in made.get('update_results', {}).items() if key == name)
                 for label, name in (('engine', engine), ('swamp', swamp))}
        timings = {label: result['request_through_terminal_ms'] for label, result in found.items()}
        signed = found['engine']['operation']
        self.record('update-comparison', {'request_through_terminal_ms': timings, 'swamp_minus_engine_ms': timings['swamp'] - timings['engine'],
                                          'engine_steps_ms': {step['name']: step['done_ms'] - (step['first_sent_ms'] or step['done_ms']) for step in signed['steps'] if step['done_ms']},
                                          'engine_operation': signed['id'], 'swamp_operation': found['swamp']['operation']['id'],
                                          'swamp_gateway_start_ms': found['swamp']['operation']['dispatch_timing']})

    def cleanup(self):
        if self.installed and not self.closed and self.project is not None:
            self.set_route_family(True)
        super().cleanup()
