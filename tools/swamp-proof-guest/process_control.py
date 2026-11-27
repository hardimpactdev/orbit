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
        rows = self.db(f"SELECT id FROM operations WHERE kind='{kind}' AND target_id='{target}' ORDER BY id DESC LIMIT 1")
        return int(rows[0]['id']) if rows else 0

    # Fixtures ----------------------------------------------------------------------------------------------------------------

    def process(self, name, runtime='systemd', start=False):
        """A host Process on app-dev, created through Swamp's process:create. Its Node unit or container is read back before use."""
        require(name not in self.processes, 'one fixture per name')
        args = ['process:create', f'{self.prefix}-{name}', '--node=' + str(self.node_id), '--runtime=' + runtime,
                '--idempotency-key=' + self.prefix + '-create-' + name]
        args += ['--image=' + IMAGE, '--command=sleep', '--command=infinity'] if runtime == 'docker' else ['--command=/bin/sleep', '--command=infinity']
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
        self.processes[name] = {'id': process, 'runtime': runtime, 'unit': unit}
        self.record(f'fixture-{name}', {'process': process, 'unit': unit, 'create_operation': operation, **self.state(name)})
        return process

    def schedule(self, name, calendar):
        args = ['schedule:create', f'{self.prefix}-{name}', '--node=' + str(self.node_id), '--calendar=' + calendar,
                '--command=/bin/true', '--no-start', '--idempotency-key=' + self.prefix + '-create-' + name]
        created = self.cli(*args, timeout=300)
        require(created['rc'] == 0, f'schedule:create {name}: exit {created["rc"]}')
        rows = self.db(f"SELECT id,trigger FROM schedules WHERE name='{self.prefix}-{name}'")
        require(len(rows) == 1, f'schedule:create {name} did not record one Schedule')
        self.schedules[name] = {'id': rows[0]['id'], 'trigger': rows[0]['trigger'], 'unit': f'orbit-schedule-{rows[0]["id"]}.timer'}
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

    def replay(self, name):
        """process:stop with its receipt dropped: the fence holds a removal and a second start of the Process, a new signed send
        replays the journal, and the stop ends once with one Node execution."""
        fixture = self.processes[name]
        self.set_capability(True)
        path, key = f'processes/{fixture["id"]}', 'replay-stop'
        target = f'{self.node_id}:process-{fixture["id"]}'
        self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=STOP', EXECUTOR_UNIT)
        try:
            started = time.monotonic()
            admitted = self.admitted('POST', path + '/stop', {}, key)
            operation = int(admitted['id'])
            wait_for(lambda: (rows := self.steps(operation)) and rows[0]['first_order_expires_ms'] and rows, f'Stop {operation} did not publish', 10)
            queued = wait_for(lambda: self.helper('pending-order', self.node_id, operation, target, 'converge', self.PRESENT),
                              f'Stop {operation} had no signed order on JetStream', 8)
            removal = self.api('DELETE', path, {}, key + '-remove')
            second = self.api('POST', path + '/start', {}, key + '-start')
            self.systemctl('gateway', 'stop', ENGINE_UNIT)
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, target, 'converge', self.PRESENT),
                               f'Stop {operation} left no receipt to drop', 20)
            node_stopped = self.unit(fixture['unit'])
            fenced = self.admitted('POST', path + '/stop', {}, key)
            busy_after_node = self.api('DELETE', path, {}, key + '-remove-after')
            require(removal[2] == 'operation.target_busy' and second[2] == 'operation.target_busy' and busy_after_node[2] == 'operation.target_busy'
                    and fenced['id'] == operation and fenced['status'] not in TERMINAL and self.steps(operation)[0]['status'] != 'done'
                    and node_stopped['ActiveState'] == 'inactive',
                    f'Stop {operation}: fence or Node state wrong without a receipt ({removal[2]}, {second[2]}, {busy_after_node[2]})')
        finally:
            self.systemctl(self.node, 'kill', '--kill-whom=main', '--signal=CONT', EXECUTOR_UNIT)
            self.systemctl('gateway', 'start', ENGINE_UNIT)
        terminal = self.terminal(operation)
        elapsed = round((time.monotonic() - started) * 1000)
        summary = self.operation(operation, 'engine')
        journal = summary['journal']
        require(summary['steps'][0]['sends'] >= 2 and sum(item['record'] == 'open' for item in journal) == 1
                and [item['outcome']['status'] for item in journal if item['record'] == 'done'] == ['done'],
                f'Stop {operation} did not replay one execution on a second send')
        self.record('replay', {'operation': summary, 'queued_signed_order': queued, 'dropped': dropped,
                               'fenced_while_outstanding': {'process_destroy': removal[2], 'process_start': second[2],
                                                            'process_destroy_after_node_done': busy_after_node[2]},
                               'http_replay': {'id': fenced['id'], 'status': fenced['status']}, 'node_before_receipt': node_stopped,
                               'public': {k: terminal.get(k) for k in ('status', 'error_code')}, 'state': self.state(name),
                               'request_through_terminal_ms': elapsed})
        self.timings['replay-stop'] = elapsed

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

    def enable(self, name, via):
        fixture = self.schedules[name]
        self.set_capability(via == 'engine')
        before = self.timer(fixture['unit'])
        started = time.monotonic()
        answer = self.cli('schedule:enable', fixture['id'], '--idempotency-key=' + self.prefix + '-enable-' + name + '-' + via)
        elapsed = round((time.monotonic() - started) * 1000)
        operation = self.latest('schedule.enable', fixture['id'])
        terminal = self.terminal(operation)
        summary = self.operation(operation, via)
        after = self.timer(fixture['unit'])
        row = self.schedule_row(fixture['id'])
        require(answer['rc'] == 0 and (after['ActiveState'], after['UnitFileState'], after['LoadState']) == ('active', 'enabled', 'loaded')
                and after['NextElapseUSecRealtime'] not in ('', '0', 'n/a') and row['status'] == 'active',
                f'{name}: timer read back {after} {row}')
        if via == 'engine':
            require(len(summary['steps']) == 1 and summary['steps'][0]['action'] == self.ABSENT and summary['steps'][0]['status'] == 'done',
                    f'{name}: engine step {summary["steps"]}')
        self.record(f'enable-{name}-{via}', {'cli': {'rc': answer['rc'], 'secs': answer['secs']}, 'before': before, 'after': after,
                                              'row': row, 'operation': summary, 'public': {k: terminal.get(k) for k in ('status', 'error_code')},
                                              'request_through_terminal_ms': elapsed})
        self.timings[f'enable-{via}'] = elapsed

    def refused(self, name, kind):
        """With the family advertised, a shape outside the signed contract runs wholly on Swamp: no engine step."""
        self.set_capability(True)
        if kind == 'schedule.enable':
            fixture = self.schedules[name]
            answer = self.cli('schedule:enable', fixture['id'], '--idempotency-key=' + self.prefix + '-refused-' + name)
            operation = self.latest(kind, fixture['id'])
        else:
            fixture = self.processes[name]
            answer = self.cli(kind.replace('.', ':'), fixture['id'], '--idempotency-key=' + self.prefix + '-refused-' + name)
            operation = self.latest(kind, fixture['id'])
        terminal = self.terminal(operation)
        summary = self.operation(operation, 'swamp')
        self.record(f'refused-{name}', {'kind': kind, 'cli_rc': answer['rc'], 'operation': summary,
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
        self.uninstall()
        self.closed = True
        self.record('cleanup', {'processes_removed': [f['id'] for f in self.processes.values()],
                                'schedules_removed': [f['id'] for f in self.schedules.values()], 'temporary_services_removed': True})
