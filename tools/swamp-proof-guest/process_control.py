"""Process start/stop and Node-timer Schedule enable through the signed executor and through Swamp, read back on the Node."""
import json
import time

from clone_lifecycle import EXECUTOR_UNIT, ENGINE_UNIT, TERMINAL, CloneLane, ProofFailure, require, wait_for

IMAGE = 'busybox:1.37'
SHOW = ('ActiveState', 'SubState', 'UnitFileState', 'LoadState')


class ProcessLane(CloneLane):
    """Disposable Processes and Schedules on app-dev. Every fixture is created by this lane and removed by it; nothing that existed
    before the case is touched."""
    KINDS = ('process.start', 'process.stop', 'schedule.enable')
    FAMILY, PRESENT, ABSENT = 'process', 'process.ensure_state', 'schedule.ensure_timer'

    def __init__(self, run, fleet_contract):
        super().__init__(run, fleet_contract)
        self.prefix = 'proof-proc-' + self.prefix.rsplit('-', 1)[1]
        self.processes = {}
        self.schedules = {}
        self.instances = {}
        self.project = None
        self.timings = {}

    def setup(self):
        before = {'processes': self.db('SELECT count(*) AS n FROM processes')[0]['n'],
                  'schedules': self.db('SELECT count(*) AS n FROM schedules')[0]['n']}
        self.identify()
        self.record('start-state', before)

    # Reads -------------------------------------------------------------------------------------------------------------------

    def cli(self, *args, timeout=150):
        return self.run.fleet.cli([str(arg) for arg in args], timeout=timeout)

    def unit(self, name):
        shown = self.guest(self.node, 'systemctl', 'show', '-p', ','.join(SHOW), name)
        return dict(line.split('=', 1) for line in shown.splitlines() if '=' in line)

    def timer(self, name):
        shown = self.guest(self.node, 'systemctl', 'show', '-p', 'ActiveState,UnitFileState,NextElapseUSecRealtime,LoadState', name)
        return dict(line.split('=', 1) for line in shown.splitlines() if '=' in line)

    def container(self, name):
        found = self.guest(self.node, 'docker', 'container', 'inspect', '--format', '{{json .State.Status}} {{json .Config.Image}} {{json .Name}}', name)
        status, image, named = (json.loads(part) for part in found.split(' '))
        return {'status': status, 'image': image, 'name': named.lstrip('/')}

    def process_row(self, process):
        rows = self.db(f'SELECT id,name,runtime,desired_state,status,failed_step,error_code FROM processes WHERE id={process}')
        return rows[0] if rows else None

    def schedule_row(self, schedule):
        rows = self.db(f"SELECT id,name,trigger,desired_timer_state,status,failed_step,error_code FROM schedules WHERE id='{schedule}'")
        return rows[0] if rows else None

    def state(self, name):
        fixture = self.processes[name]
        live = self.container(fixture['unit']) if fixture['runtime'] == 'docker' else self.unit(fixture['unit'])
        return {'row': self.process_row(fixture['id']), 'node': live}

    def latest(self, kind, target):
        match = f"json_extract(input,'$.id')='{target}'" if kind.startswith('schedule.') else f"target_id='{target}'"
        rows = self.db(f"SELECT id FROM operations WHERE kind='{kind}' AND {match} ORDER BY id DESC LIMIT 1")
        return int(rows[0]['id']) if rows else 0

    # Fixtures ----------------------------------------------------------------------------------------------------------------

    def host_instance(self, name):
        """A host (non-container) app-dev Instance with its own Git checkout: the owner a retained Node timer has. Its Project comes
        from the public API; its row is a fixture (see the guest helper), as Orbit makes no new host Instance."""
        code, project, error = self.api('POST', 'projects', {'slug': self.prefix, 'type': 'laravel-package',
                                                             'repository_url': 'https://github.com/laravel/prompts.git', 'default_branch': 'main', 'root': '.'})
        require(code == 201, f'disposable project admission: HTTP {code} {error}')
        self.project = int(project['id'])
        label = f'{self.prefix}-{name}'
        path = '/home/orbit/' + label
        self.guest(self.node, 'sh', '-c', 'test ! -e "$1"', 'sh', path)
        self.guest(self.node, 'runuser', '-u', 'orbit', '--', 'sh', '-eu', '-c',
                   'mkdir "$1"; cd "$1"; git init -q -b main; git -c user.email=proof@example.invalid -c user.name=proof commit -q --allow-empty -m proof', 'sh', path)
        instance = int(self.helper('host-instance', self.project, self.node_id, label, path)['instance'])
        self.instances[name] = instance
        self.host_paths = getattr(self, 'host_paths', []) + [(instance, label, path)]
        self.record(f'fixture-{name}', {'project': self.project, 'instance': instance, 'checkout': path})

    def process(self, name, runtime='systemd', start=False, instance=None):
        """A host Process on app-dev, created through Swamp's process:create. Its Node unit or container is read back before use."""
        require(name not in self.processes, 'one fixture per name')
        owner = '--node=' + str(self.node_id) if instance is None else '--instance=' + str(self.instances.get(instance, instance))
        args = ['process:create', f'{self.prefix}-{name}', owner, '--runtime=' + runtime, '--idempotency-key=' + self.prefix + '-create-' + name]
        if runtime == 'docker':
            args += ['--image=' + IMAGE, '--command=sleep', '--command=infinity']
        elif name == 'horizon':
            # The shape GatewayHorizon::runs recognises: a Node host unit running `artisan horizon`, whose stop waits for its drain.
            args += ['--command=php', '--command=artisan', '--command=horizon']
        else:
            args += ['--command=/bin/sleep', '--command=infinity']
        if start:
            args.append('--start')
        created = self.cli(*args, timeout=300)
        require(created['rc'] == 0, f'process:create {name}: exit {created["rc"]}')
        rows = self.db(f"SELECT id FROM processes WHERE name='{self.prefix}-{name}'")
        require(len(rows) == 1, f'process:create {name} did not record one Process')
        process = int(rows[0]['id'])
        operation = self.latest('process.create', process)
        unit = self.db(f"SELECT json_extract(input,'$.name') AS name FROM operations WHERE id={operation}")[0]['name'] if operation else None
        if not unit:
            unit = f'orbit-process-{process}-{self.prefix}-{name}' + ('' if runtime == 'docker' else '.service')
        self.processes[name] = {'id': process, 'runtime': runtime, 'unit': unit, 'container': isinstance(instance, int)}
        state = {'row': self.process_row(process)} if isinstance(instance, int) else self.state(name)
        self.record(f'fixture-{name}', {'process': process, 'unit': unit, 'create_operation': operation, **state})
        return process

    def schedule(self, name, calendar, retained=False, instance=None):
        owner = '--node=' + str(self.node_id) if instance is None else '--instance=' + str(self.instances[instance])
        args = ['schedule:create', f'{self.prefix}-{name}', owner, '--calendar=' + calendar,
                '--command=/bin/true', '--no-start', '--idempotency-key=' + self.prefix + '-create-' + name]
        created = self.cli(*args, timeout=300)
        require(created['rc'] == 0, f'schedule:create {name}: exit {created["rc"]}')
        rows = self.db(f"SELECT id,trigger FROM schedules WHERE name='{self.prefix}-{name}'")
        require(len(rows) == 1, f'schedule:create {name} did not record one Schedule')
        self.schedules[name] = {'id': rows[0]['id'], 'trigger': rows[0]['trigger'], 'unit': f'orbit-schedule-{rows[0]["id"]}.timer'}
        if retained:
            # Fixture only: the public schedule:create makes a native cron Schedule; a host Node timer is a retained row (a systemd
            # calendar instantiated from a Project definition before ADR 0244). The lane turns its own new row into one and writes its
            # disabled timer pair on the Node, after checking both names are free there.
            unit = self.schedules[name]['unit']
            base = '/etc/systemd/system/' + unit[:-len('.timer')]
            self.guest(self.node, 'sh', '-c', 'test ! -e "$1.timer" && test ! -e "$1.service"', 'sh', base)
            self.put(self.node, base + '.service', '[Service]\nType=oneshot\nExecStart=/bin/true\n')
            self.put(self.node, base + '.timer', f'[Timer]\nOnCalendar={calendar}\n\n[Install]\nWantedBy=timers.target\n')
            self.systemctl(self.node, 'daemon-reload')
            self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'sqlite3', '-cmd', '.timeout 3000', '/home/orbit/.orbit/gateway.sqlite',
                       f"UPDATE schedules SET trigger='node' WHERE id='{rows[0]['id']}'")
            self.schedules[name].update(trigger='node', files=[base + '.timer', base + '.service'])
        self.record(f'fixture-{name}', {**self.schedules[name], 'row': self.schedule_row(rows[0]['id'])})
        return rows[0]['id']

    # Acts --------------------------------------------------------------------------------------------------------------------

    def control(self, name, verb, via, key, expect='succeeded'):
        """One process:start or process:stop through the public CLI; asserts the engine or Swamp path by its recorded steps."""
        fixture = self.processes[name]
        advertised = self.set_capability(via == 'engine')
        started = time.monotonic()
        answer = self.cli(f'process:{verb}', fixture['id'], '--idempotency-key=' + self.prefix + '-' + key)
        elapsed = round((time.monotonic() - started) * 1000)
        operation = self.latest(f'process.{verb}', fixture['id'])
        terminal = self.terminal(operation, expect=expect)
        summary = self.operation(operation, via)
        state = self.state(name)
        self.record(f'{key}', {'cli': {'rc': answer['rc'], 'secs': answer['secs']}, 'advertised_version': advertised['version'],
                               'operation': summary, 'public': {k: terminal.get(k) for k in ('status', 'error_code', 'result')},
                               'state': state, 'request_through_terminal_ms': elapsed})
        self.timings[key] = elapsed
        return operation, summary, state, answer

    def start_stop(self, name, via, sibling=None, on_demand=False):
        """Stop then start one Process and read the exact Node state back after each; a sibling stays as it was."""
        fixture = self.processes[name]
        watched = self.state(sibling)['node'] if sibling else None
        results = {}
        for verb in ('stop', 'start'):
            operation, summary, state, answer = self.control(name, verb, via, f'{name}-{verb}-{via}')
            require(answer['rc'] == 0, f'process:{verb} {name}: exit {answer["rc"]}')
            node = state['node']
            if fixture['runtime'] == 'docker':
                require(node['status'] == ('exited' if verb == 'stop' else 'running') and node['image'] == IMAGE
                        and node['name'] == fixture['unit'], f'{name}: container did not read back {verb}')
            else:
                boot = 'disabled' if verb == 'stop' or on_demand else 'enabled'
                require((node['ActiveState'], node['UnitFileState'], node['LoadState']) == (('inactive' if verb == 'stop' else 'active'), boot, 'loaded'),
                        f'{name}: unit read back {node} after {verb}')
            require(state['row']['status'] == ('stopped' if verb == 'stop' else 'running') or state['row']['desired_state'] == ('stopped' if verb == 'stop' else 'running'),
                    f'{name}: Process projection after {verb}: {state["row"]}')
            if via == 'engine':
                steps = summary['steps']
                require(len(steps) == 1 and steps[0]['action'] == self.PRESENT and steps[0]['status'] == 'done'
                        and steps[0]['target'] == f'{self.node_id}:process-{fixture["id"]}', f'{name}: engine step {steps}')
                require(sum(item['record'] == 'open' for item in summary['journal']) == 1, f'{name}: executor did not run once')
            results[verb] = operation
        if sibling:
            require(self.state(sibling)['node'] == watched, f'sibling {sibling} changed while {name} was controlled')
        return results

    def dropped(self, path, key, step, action, target, probe):
        """Admit one engine Operation with its receipt dropped: the Node runs the order, the receipt never reaches the Gateway, and
        `probe` runs while the Operation is outstanding. The engine then resends; the journal answers the second send."""
        self.set_capability(True)
        self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
        try:
            started = time.monotonic()
            admitted = self.admitted('POST', path, {}, key)
            operation = int(admitted['id'])
            # The order expires seconds after its send: the engine stops and the Node resumes at once, and the fence is probed after.
            wait_for(lambda: (rows := self.steps(operation)) and rows[0]['first_order_expires_ms'] and rows, f'{operation} did not publish', 10, 0.1)
            self.systemctl('gateway', 'stop', ENGINE_UNIT)
            queued = self.helper('pending-order', self.node_id, operation, target, step, action)
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, target, step, action),
                               f'{operation} left no receipt to drop', 20)
            require(not dropped.get('kept'), f'{operation}: the Node answered {dropped.get("status")} {dropped.get("code")}, not a success to drop')
            replayed = self.admitted('POST', path, {}, key)
            require(replayed['id'] == operation and replayed['status'] not in TERMINAL and self.steps(operation)[0]['status'] != 'done',
                    f'{operation} ended or moved without its receipt')
            probed = probe()
        finally:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            self.systemctl('gateway', 'start', ENGINE_UNIT)
        terminal = self.terminal(operation)
        elapsed = round((time.monotonic() - started) * 1000)
        summary = self.operation(operation, 'engine')
        journal = summary['journal']
        require(summary['steps'][0]['sends'] >= 2 and sum(item['record'] == 'open' for item in journal) == 1
                and [item['outcome']['status'] for item in journal if item['record'] == 'done'] == ['applied'],
                f'{operation} did not replay one execution on a second send')
        return operation, {'operation': summary, 'queued_signed_order': queued, 'dropped': dropped, 'probed_while_outstanding': probed,
                           'http_replay': {'id': replayed['id'], 'status': replayed['status']},
                           'public': {k: terminal.get(k) for k in ('status', 'error_code')}, 'request_through_terminal_ms': elapsed}

    def replay(self, name, parent=None):
        """process:stop with its receipt dropped: removal and start of the Process, and destroy of its parent Instance, are refused
        with operation.target_busy until the replayed send ends the stop once."""
        fixture = self.processes[name]
        path = f'processes/{fixture["id"]}'
        instance = self.instances[parent] if parent else None

        def probe():
            found = {'process_destroy': self.api('DELETE', path, {}, f'replay-remove-{name}')[2],
                     'process_start': self.api('POST', path + '/start', {}, f'replay-start-{name}')[2], 'node': self.unit(fixture['unit'])}
            if instance:
                found['instance_destroy'] = self.api('DELETE', f'instances/{instance}', {'force': True}, f'replay-parent-{name}')[2]
            require(found['process_destroy'] == found['process_start'] == 'operation.target_busy' and found['node']['ActiveState'] == 'inactive'
                    and found.get('instance_destroy', 'operation.target_busy') == 'operation.target_busy', f'{name}: fence while outstanding {found}')
            return found

        operation, result = self.dropped(path + '/stop', f'replay-stop-{name}', 'converge', self.PRESENT, f'{self.node_id}:process-{fixture["id"]}', probe)
        if instance:
            # After the terminal receipt the parent's destroy is no longer fenced by the Process. The proof's host Instance is a
            # fixture row, so the removal contract refuses it after the fence; the answer is recorded, not a removal.
            after = self.api('DELETE', f'instances/{instance}', {'force': True}, f'replay-parent-after-{name}')
            require(after[2] != 'operation.target_busy', f'{name}: the parent stayed fenced after the stop ended')
            result['instance_destroy_after_terminal'] = {'status': after[0], 'code': after[2]}
        result['state'] = self.state(name)
        self.record(f'replay-{name}', result)
        self.timings[f'replay-stop-{name}'] = result['request_through_terminal_ms']

    def retry(self, name):
        """A start whose unit file is missing on the Node fails on the engine with the Swamp step's public error; once the unit is
        back, a new start converges it."""
        fixture = self.processes[name]
        unit_path = f'/etc/systemd/system/{fixture["unit"]}'
        held = f'/run/{self.prefix}-held.service'
        self.guest(self.node, 'mv', unit_path, held)
        self.systemctl(self.node, 'daemon-reload')
        try:
            operation, summary, state, answer = self.control(name, 'start', 'engine', f'{name}-start-failed', expect='failed')
            require(answer['rc'] != 0 and summary['error_code'] == 'operation.native_failed' and summary['steps'][0]['status'] == 'failed'
                    and summary['steps'][0]['error_code'] == 'process.unit_missing', f'{name}: failed start {summary}')
        finally:
            self.guest(self.node, 'mv', held, unit_path)
            self.systemctl(self.node, 'daemon-reload')
        again, summary, state, answer = self.control(name, 'start', 'engine', f'{name}-start-retried')
        require(answer['rc'] == 0 and state['node']['ActiveState'] == 'active' and state['node']['UnitFileState'] == 'enabled',
                f'{name}: retry did not converge {state}')
        self.record('retry', {'failed': operation, 'retried': again, 'state': state})

    def enable(self, name, via, fault=None):
        """schedule:enable of a retained Node timer, read back on the Node: enabled, active, a next elapse."""
        fixture = self.schedules[name]
        before = self.timer(fixture['unit'])
        if fault == 'receipt':
            def probe():
                found = {'schedule_destroy': self.api('DELETE', f'schedules/{fixture["id"]}', {}, f'replay-schedule-remove-{name}')[2]}
                require(found['schedule_destroy'] == 'operation.target_busy', f'{name}: fence while outstanding {found}')
                return found

            operation, result = self.dropped(f'schedules/{fixture["id"]}/activate', f'replay-enable-{name}', 'timer', self.ABSENT,
                                             f'{self.node_id}:schedule-{fixture["id"]}', probe)
            summary, answer_rc, elapsed = result['operation'], 0, result['request_through_terminal_ms']
        else:
            self.set_capability(via == 'engine')
            started = time.monotonic()
            answer = self.cli('schedule:enable', fixture['id'], '--idempotency-key=' + self.prefix + '-enable-' + name + '-' + via)
            elapsed = round((time.monotonic() - started) * 1000)
            operation = self.latest('schedule.enable', fixture['id'])
            terminal = self.terminal(operation)
            summary, answer_rc = self.operation(operation, via), answer['rc']
            result = {'cli': {'rc': answer['rc'], 'secs': answer['secs']}, 'operation': summary,
                      'public': {k: terminal.get(k) for k in ('status', 'error_code')}, 'request_through_terminal_ms': elapsed}
        after = self.timer(fixture['unit'])
        row = self.schedule_row(fixture['id'])
        require(answer_rc == 0 and (after['ActiveState'], after['UnitFileState'], after['LoadState']) == ('active', 'enabled', 'loaded')
                and after['NextElapseUSecRealtime'] not in ('', '0', 'n/a') and row['status'] == 'active' and row['desired_timer_state'] == 'enabled',
                f'{name}: timer read back {after} {row}')
        if via == 'engine':
            require(len(summary['steps']) == 1 and summary['steps'][0]['action'] == self.ABSENT and summary['steps'][0]['status'] == 'done'
                    and sum(item['record'] == 'open' for item in summary['journal']) == 1, f'{name}: engine step {summary["steps"]}')
        self.record(f'enable-{name}-{via}' + (f'-{fault}' if fault else ''), {**result, 'before': before, 'after': after, 'row': row})
        self.timings[f'enable-{name}-{via}' + (f'-{fault}' if fault else '')] = elapsed

    def refused(self, name, kind):
        """With the family advertised, a shape outside the signed contract runs wholly on Swamp: no engine step."""
        self.set_capability(True)
        mark = self.db('SELECT coalesce(max(id), 0) AS id FROM operations')[0]['id']
        fixture = self.schedules[name] if kind == 'schedule.enable' else self.processes[name]
        answer = self.cli(kind.replace('.', ':'), fixture['id'], '--idempotency-key=' + self.prefix + '-refused-' + name)
        started = self.db(f'SELECT id, kind FROM operations WHERE id > {mark} ORDER BY id')
        require(len(started) == 1, f'{name}: {kind} started {len(started)} Operations (exit {answer["rc"]})')
        operation = int(started[0]['id'])
        terminal = self.terminal(operation)
        summary = self.operation(operation, 'swamp')
        self.record(f'refused-{name}', {'kind': kind, 'started_kind': started[0]['kind'], 'cli_rc': answer['rc'], 'operation': summary,
                                         'public': {k: terminal.get(k) for k in ('status', 'error_code')}})

    def compare(self):
        self.record('timing', self.timings)

    # Cleanup -----------------------------------------------------------------------------------------------------------------

    def cleanup(self):
        if not self.installed or self.closed:
            return
        for name, fixture in self.processes.items():
            if self.process_row(fixture['id']) is not None:
                answer = self.cli('process:destroy', fixture['id'], '--yes', '--idempotency-key=' + self.prefix + '-cleanup-' + name, timeout=300)
                require(answer['rc'] == 0, f'cleanup of Process {fixture["id"]}: exit {answer["rc"]}')
        for name, fixture in self.schedules.items():
            if self.schedule_row(fixture['id']) is not None:
                answer = self.cli('schedule:destroy', fixture['id'], '--yes', '--idempotency-key=' + self.prefix + '-cleanup-' + name, timeout=300)
                require(answer['rc'] == 0, f'cleanup of Schedule {fixture["id"]}: exit {answer["rc"]}')
        for fixture in self.schedules.values():
            for path in fixture.get('files', []):
                self.guest(self.node, 'rm', '-f', path)
        self.systemctl(self.node, 'daemon-reload')
        for instance, label, path in getattr(self, 'host_paths', []):
            removed = self.helper('host-instance-remove', instance, label)
            self.guest(self.node, 'rm', '-rf', '--', path)
            require(removed['removed'] == 1, f'host Instance fixture {instance} was not removed')
        if self.project is not None:
            code, _data, error = self.api('DELETE', f'projects/{self.project}')
            require(code in (200, 204), f'disposable project cleanup: HTTP {code} {error}')
        self.uninstall()
        self.closed = True
        self.record('cleanup', {'processes_removed': [f['id'] for f in self.processes.values()],
                                'schedules_removed': [f['id'] for f in self.schedules.values()], 'temporary_services_removed': True})
