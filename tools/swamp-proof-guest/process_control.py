"""Process start/stop and Node-timer Schedule enable through the signed executor and through Swamp, read back on the Node."""
import json
import time

from clone_lifecycle import (
    ENGINE_UNIT,
    EXECUTOR_UNIT,
    TERMINAL,
    CloneLane,
    require,
    wait_for,
)

IMAGE = 'busybox:1.37'
SHOW = ('ActiveState', 'SubState', 'UnitFileState', 'LoadState')


class ProcessLane(CloneLane):
    """Disposable Processes and Schedules on app-dev. Every fixture is created by this lane and removed by it; nothing that existed
    before the case is touched."""
    KINDS = ('process.start', 'process.stop', 'schedule.enable')
    FAMILY, PRESENT, ABSENT = 'process', 'process.ensure_state', 'schedule.ensure_timer'
    ALSO = ()

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

    def native_artifacts(self, schedule, workflow):
        """Whether a native Schedule's own artifacts exist: its Swamp workflow file and command copy, and serve's trigger state."""
        swamp = self.node_checkout + '/swamp'
        paths = {'workflow_file': f'{swamp}/workflows/workflow-schedule-{schedule}.yaml',
                 'command_copy': f'{swamp}/.swamp/secrets/local_encryption/orbit-control-inputs/schedule-command-{schedule}.enc'}
        found = {key: self.guest('gateway', 'sh', '-c', 'if test -e "$1"; then echo 1; fi', 'sh', path) == '1' for key, path in paths.items()}
        state = f'{swamp}/.swamp/_control/schedule-states/{workflow}' if workflow else None
        text = self.guest('gateway', 'sh', '-c', 'if test -e "$1"; then cat "$1"; fi', 'sh', state) if state else ''
        found['trigger'] = {key: value for key, value in json.loads(text).items() if key in ('workflowName', 'cronExpression')} if text else None
        return found

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
            args += ['--command=/usr/bin/php', '--command=artisan', '--command=horizon']
        else:
            args += ['--command=/bin/sleep', '--command=infinity']
        if start:
            args.append('--start')
        # The name must be free before the create, and the create must say it made a new Process: a reconcile of an existing row
        # also succeeds, and the lane never adopts (and so never removes) a Process it did not make.
        require(not self.db(f"SELECT id FROM processes WHERE name='{self.prefix}-{name}'"), f'Process name {self.prefix}-{name} is already taken')
        created = self.cli(*args, timeout=300)
        reply = created['json'] or {}
        require(created['rc'] == 0 and reply.get('kind') == 'process.create' and reply.get('status') == 'succeeded'
                and (reply.get('input') or {}).get('created') is True, f'process:create {name}: exit {created["rc"]}, not a new Process')
        process, operation, unit = int(reply['target_id']), int(reply['id']), reply['input']['name']
        rows = self.db(f"SELECT id FROM processes WHERE name='{self.prefix}-{name}'")
        require([int(row['id']) for row in rows] == [process], f'process:create {name} did not record exactly its own Process')
        self.processes[name] = {'id': process, 'runtime': runtime, 'unit': unit, 'container': isinstance(instance, int)}
        state = {'row': self.process_row(process)} if isinstance(instance, int) else self.state(name)
        self.record(f'fixture-{name}', {'process': process, 'unit': unit, 'create_operation': operation, **state})
        return process

    def schedule(self, name, calendar, retained=False, instance=None):
        owner = '--node=' + str(self.node_id) if instance is None else '--instance=' + str(self.instances[instance])
        args = ['schedule:create', f'{self.prefix}-{name}', owner, '--calendar=' + calendar,
                '--command=/bin/true', '--no-start', '--idempotency-key=' + self.prefix + '-create-' + name]
        require(not self.db(f"SELECT id FROM schedules WHERE name='{self.prefix}-{name}'"), f'Schedule name {self.prefix}-{name} is already taken')
        created = self.cli(*args, timeout=300)
        reply = created['json'] or {}
        require(created['rc'] == 0 and reply.get('kind') == 'schedule.create' and (reply.get('input') or {}).get('created') is True,
                f'schedule:create {name}: exit {created["rc"]}, not a new Schedule')
        rows = self.db(f"SELECT id,trigger,swamp_workflow_id FROM schedules WHERE name='{self.prefix}-{name}'")
        require([row['id'] for row in rows] == [reply['input']['id']], f'schedule:create {name} did not record exactly its own Schedule')
        self.schedules[name] = {'id': rows[0]['id'], 'trigger': rows[0]['trigger'], 'unit': f'orbit-schedule-{rows[0]["id"]}.timer',
                                'workflow': rows[0]['swamp_workflow_id'], 'calendar': calendar, 'native': self.native_artifacts(rows[0]['id'], rows[0]['swamp_workflow_id'])}
        require(all(self.schedules[name]['native'][key] for key in ('workflow_file', 'command_copy')),
                f'schedule:create {name} did not publish its own native workflow and command copy')
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
        return operation, summary, state, answer, {k: terminal.get(k) for k in ('status', 'error_code', 'result')}

    def start_stop(self, name, via, sibling=None, on_demand=False):
        """Stop then start one Process and read the exact Node state back after each; a sibling stays as it was."""
        fixture = self.processes[name]
        watched = self.sibling(sibling) if sibling else None
        results = {}
        for verb in ('stop', 'start'):
            operation, summary, state, answer, public = self.control(name, verb, via, f'{name}-{verb}-{via}')
            require(answer['rc'] == 0, f'process:{verb} {name}: exit {answer["rc"]}')
            node, row = state['node'], state['row']
            if fixture['runtime'] == 'docker':
                live = 'exited' if verb == 'stop' else 'running'
                require(node['status'] == live and node['image'] == IMAGE and node['name'] == fixture['unit'], f'{name}: container did not read back {verb}')
            else:
                live = 'inactive' if verb == 'stop' else 'active'
                boot = 'disabled' if verb == 'stop' or on_demand else 'enabled'
                require((node['ActiveState'], node['SubState'], node['UnitFileState'], node['LoadState'])
                        == (live, 'dead' if verb == 'stop' else 'running', boot, 'loaded'), f'{name}: unit read back {node} after {verb}')
            # The projection Swamp's end writes: the lifecycle stays active, the desired state follows the verb, no failure is left,
            # and the public result names the state the Node read back.
            require((row['status'], row['desired_state'], row['failed_step'], row['error_code']) == ('active', 'stopped' if verb == 'stop' else 'running', None, None)
                    and public['status'] == 'succeeded' and public['error_code'] is None and public['result'] == {'ready': True, 'state': live},
                    f'{name}: Process projection or result after {verb}: {row} {public}')
            if via == 'engine':
                steps = summary['steps']
                require(len(steps) == 1 and steps[0]['action'] == self.PRESENT and steps[0]['status'] == 'done'
                        and steps[0]['target'] == f'{self.node_id}:process-{fixture["id"]}', f'{name}: engine step {steps}')
                require(sum(item['record'] == 'open' for item in summary['journal']) == 1, f'{name}: executor did not run once')
            results[verb] = operation
            if sibling:
                seen = self.sibling(sibling)
                require(seen == watched, f'sibling {sibling} changed after {name} {verb}: {watched} -> {seen}')
                self.record(f'{name}-{verb}-{via}-sibling', seen)
        return results

    def sibling(self, name):
        """A running sibling unit's identity: the same main PID and activation time mean it ran on without a restart."""
        shown = self.guest(self.node, 'systemctl', 'show', '-p', 'ActiveState,SubState,MainPID,ActiveEnterTimestampMonotonic,NRestarts', self.processes[name]['unit'])
        found = dict(line.split('=', 1) for line in shown.splitlines() if '=' in line)
        require(found['ActiveState'] == 'active' and found['SubState'] == 'running' and int(found['MainPID']) > 0, f'sibling {name} is not running: {found}')
        return found

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
                and [item['outcome']['status'] for item in journal if item['record'] == 'done'] in (['applied'], ['output']),
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
        """A start that fails half-applied: the boot policy is enabled, then the start fails on a drop-in whose ExecStartPre exits 1.
        The Node keeps the enabled unit, not running; the Process records the Swamp step's failure. With the drop-in gone, a public
        retry converges."""
        fixture = self.processes[name]
        before = self.unit(fixture['unit'])
        require((before['ActiveState'], before['UnitFileState']) == ('inactive', 'disabled'), f'{name}: retry needs a stopped, disabled unit: {before}')
        directory = f'/etc/systemd/system/{fixture["unit"]}.d'
        # `mkdir` without -p refuses an existing directory, so the fault never touches a drop-in it did not make.
        self.guest(self.node, 'mkdir', directory)
        try:
            self.put(self.node, directory + '/proof-fail.conf', '[Service]\nExecStartPre=/bin/false\n')
            self.systemctl(self.node, 'daemon-reload')
            operation, summary, half, answer, public = self.control(name, 'start', 'engine', f'{name}-start-failed', expect='failed')
            step = summary['steps'][0]
            require(answer['rc'] != 0 and public['error_code'] == 'operation.native_failed' and step['status'] == 'failed'
                    and step['error_code'] == 'process.apply_failed' and half['node']['UnitFileState'] == 'enabled'
                    and half['node']['ActiveState'] in ('failed', 'inactive') and half['row']['error_code'] == 'operation.native_failed',
                    f'{name}: half-applied start {step} {half}')
        finally:
            self.guest(self.node, 'rm', '-f', directory + '/proof-fail.conf')
            self.guest(self.node, 'rmdir', directory)
            self.systemctl(self.node, 'daemon-reload')
        again, summary, state, answer, public = self.control(name, 'start', 'engine', f'{name}-start-retried')
        row = state['row']
        require(answer['rc'] == 0 and (state['node']['ActiveState'], state['node']['SubState'], state['node']['UnitFileState']) == ('active', 'running', 'enabled')
                and (row['status'], row['desired_state'], row['failed_step'], row['error_code']) == ('active', 'running', None, None)
                and public['result'] == {'ready': True, 'state': 'active'}, f'{name}: retry did not converge {state} {public}')
        self.record('retry', {'failed': operation, 'node_and_process_after_failure': half, 'retried': again, 'state': state})

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
        before_trigger = self.native_artifacts(fixture['id'], fixture['workflow'])['trigger'] if kind == 'schedule.enable' else None
        if kind == 'schedule.enable':
            require(self.schedule_row(fixture['id'])['desired_timer_state'] == 'disabled', f'{name}: the cron Schedule is already enabled')
        answer = self.cli(kind.replace('.', ':'), fixture['id'], '--idempotency-key=' + self.prefix + '-refused-' + name)
        started = self.db(f'SELECT id, kind FROM operations WHERE id > {mark} ORDER BY id')
        require(len(started) == 1, f'{name}: {kind} started {len(started)} Operations (exit {answer["rc"]})')
        operation = int(started[0]['id'])
        terminal = self.terminal(operation)
        # A native cron Schedule's enable updates its Swamp trigger in the Gateway and starts no workflow run; any other shape runs
        # the Swamp workflow. Neither records an engine step.
        native = kind == 'schedule.enable' and self.schedules[name]['trigger'] == 'swamp'
        if native:
            fixture = self.schedules[name]
            row = self.schedule_row(fixture['id'])
            after = self.native_artifacts(fixture['id'], fixture['workflow'])
            # The enable must set the native trigger: no serve trigger before, one with the Schedule's own cron after.
            require(before_trigger is None and after['trigger'] == {'workflowName': f'schedule-{fixture["id"]}', 'cronExpression': fixture['calendar']}
                    and row['desired_timer_state'] == 'enabled', f'{name}: the native trigger did not change ({before_trigger} -> {after["trigger"]}, {row})')
            fixture['trigger_state_created'] = True
        summary = self.db(f'''SELECT id,kind,status,error_code,json_extract(state,'$.swamp_run_id') AS run FROM operations WHERE id={operation}''')[0] \
            if native else self.operation(operation, 'swamp')
        summary['steps'] = self.steps(operation)
        require(not summary['steps'] and summary['run'] != f'engine-{operation}', f'{name}: {kind} recorded engine work')
        self.record(f'refused-{name}', {'trigger_before': before_trigger,
                                         'trigger_after': self.native_artifacts(fixture['id'], fixture['workflow'])['trigger'] if kind == 'schedule.enable' else None, 'kind': kind, 'started_kind': started[0]['kind'], 'cli_rc': answer['rc'], 'operation': summary,
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
            if fixture.get('files') and self.schedule_row(fixture['id']) is not None:
                # The retained timer was published as a native Schedule before the fixture turned it into a Node timer: back to its
                # native trigger, so its destroy removes its own Swamp workflow and command copy.
                self.guest('gateway', 'runuser', '-u', 'orbit', '--', 'sqlite3', '-cmd', '.timeout 3000', '/home/orbit/.orbit/gateway.sqlite',
                           f"UPDATE schedules SET trigger='swamp' WHERE id='{fixture['id']}' AND trigger='node'")
            if self.schedule_row(fixture['id']) is not None:
                answer = self.cli('schedule:destroy', fixture['id'], '--yes', '--idempotency-key=' + self.prefix + '-cleanup-' + name, timeout=300)
                require(answer['rc'] == 0, f'cleanup of Schedule {fixture["id"]}: exit {answer["rc"]}')
        absent = {}
        for name, fixture in self.schedules.items():
            if fixture.get('files'):
                # The fixture's own timer was enabled: stop and disable it (its boot link) before its files go, so nothing is left
                # that points at a removed unit.
                self.guest(self.node, 'systemctl', 'disable', '--now', fixture['unit'], timeout=75)
                self.guest(self.node, 'systemctl', 'stop', fixture['unit'][:-len('.timer')] + '.service', timeout=75)
            for path in fixture.get('files', []):
                self.guest(self.node, 'rm', '-f', path)
            if fixture.get('trigger_state_created'):
                self.guest('gateway', 'rm', '-f', f'{self.node_checkout}/swamp/.swamp/_control/schedule-states/{fixture["workflow"]}')
            left = self.native_artifacts(fixture['id'], fixture['workflow'] if fixture.get('trigger_state_created') else None)
            require(not any(left.values()), f'Schedule {name} left native artifacts: {left}')
            absent[name] = left
        self.systemctl(self.node, 'daemon-reload')
        timers = {}
        for name, fixture in self.schedules.items():
            if not fixture.get('files'):
                continue
            unit = fixture['unit']
            # A failed record of a unit that is gone would stay listed; clear it, then require no boot link and a unit that is
            # neither active nor failed.
            self.guest(self.node, 'sh', '-c', 'systemctl reset-failed -- "$1" 2>/dev/null || :', 'sh', unit)
            link = self.guest(self.node, 'sh', '-c', 'if test -e "$1" || test -L "$1"; then echo 1; fi', 'sh', '/etc/systemd/system/timers.target.wants/' + unit)
            shown = self.guest(self.node, 'systemctl', 'show', '-p', 'LoadState,ActiveState', unit)
            found = dict(line.split('=', 1) for line in shown.splitlines() if '=' in line) | {'boot_link': link == '1'}
            require(not found['boot_link'] and found['ActiveState'] == 'inactive' and found['LoadState'] == 'not-found',
                    f'Schedule {name} left its timer on the Node: {found}')
            timers[name] = found
        for instance, label, path in getattr(self, 'host_paths', []):
            removed = self.helper('host-instance-remove', instance, label)
            self.guest(self.node, 'rm', '-rf', '--', path)
            require(removed['removed'] == 1, f'host Instance fixture {instance} was not removed')
        if self.project is not None:
            code, _data, error = self.api('DELETE', f'projects/{self.project}')
            require(code in (200, 204), f'disposable project cleanup: HTTP {code} {error}')
        self.uninstall()
        self.closed = True
        self.record('cleanup', {'native_artifacts_after': absent, 'node_timers_after': timers, 'processes_removed': [f['id'] for f in self.processes.values()],
                                'schedules_removed': [f['id'] for f in self.schedules.values()], 'temporary_services_removed': True})
