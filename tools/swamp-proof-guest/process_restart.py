"""Process restart through the signed executor and through Swamp: one restart per order, read back on the Node by its run identity."""
import json
import time

from clone_lifecycle import ENGINE_UNIT, TERMINAL, require, wait_for
from process_control import IMAGE, ProcessLane

# The run identity of a unit: a random InvocationID per run, its main PID and its activation time.
RUN = ('ActiveState', 'SubState', 'UnitFileState', 'InvocationID', 'MainPID', 'ActiveEnterTimestampMonotonic')


class RestartLane(ProcessLane):
    """Disposable Processes on app-dev, restarted through the public process:restart. A restart is not a desired state, so every
    act compares the run identity before and after: it must change exactly once per order."""
    KINDS = ('process.restart',)
    FAMILY, PRESENT, ABSENT = 'process', 'process.restart', 'process.read_instance'
    ALSO = ('process.read_instance',)

    def run_of(self, name):
        """The run the Node has now: a unit's InvocationID, main PID and activation time; a container's ID, start time and PID."""
        fixture = self.processes[name]
        if fixture['runtime'] == 'docker':
            found = self.guest(self.node, 'docker', 'container', 'inspect', '--format',
                               '{{json .Id}} {{json .State.Status}} {{json .State.StartedAt}} {{json .State.Pid}} {{json .Config.Image}}', fixture['unit'])
            identity, status, started, pid, image = (json.loads(part) for part in found.split(' '))
            return {'id': identity, 'status': status, 'started_at': started, 'pid': pid, 'image': image,
                    'run': f'{identity}@{started}'}
        shown = self.guest(self.node, 'systemctl', 'show', '-p', ','.join(RUN), fixture['unit'])
        found = dict(line.split('=', 1) for line in shown.splitlines() if '=' in line)
        return found | {'run': found['InvocationID']}

    def running(self, name, run, on_demand=False):
        fixture = self.processes[name]
        if fixture['runtime'] == 'docker':
            return run['status'] == 'running' and run['image'] == IMAGE and run['pid'] > 0
        return (run['ActiveState'], run['SubState'], run['UnitFileState']) == ('active', 'running', 'disabled' if on_demand else 'enabled') \
            and run['InvocationID'] != '' and int(run['MainPID']) > 0

    def engine_steps(self, name, summary, restarted=('now',)):
        """The two signed steps, each executed once on the Node, and a restart output that names the new run."""
        fixture = self.processes[name]
        target = f'{self.node_id}:process-{fixture["id"]}'
        steps = summary['steps']
        require([(step['name'], step['action'], step['status'], step['target']) for step in steps]
                == [('read', self.ABSENT, 'done', target), ('restart', self.PRESENT, 'done', target)], f'{name}: engine steps {steps}')
        for step in ('read', 'restart'):
            opened = [item for item in summary['journal'] if item['operation'].endswith(':' + step) and item['record'] == 'open']
            require(len(opened) == 1, f'{name}: the executor opened {step} {len(opened)} times')
        output = json.loads(self.db(f"SELECT output FROM operation_engine_steps WHERE operation_id={summary['id']} AND name='restart'")[0]['output'])
        require(output['restarted'] in restarted, f'{name}: restart output {output}')
        return output

    def restart(self, name, via, sibling=None, on_demand=False, key=None):
        """One process:restart through the public CLI: the run changes once, the Process reads back running with its boot policy,
        and it stays on that run after the terminal receipt; a sibling keeps its main PID and activation time."""
        fixture = self.processes[name]
        key = key or f'{name}-restart-{via}'
        watched = self.sibling(sibling) if sibling else None
        before = self.run_of(name)
        require(self.running(name, before, on_demand), f'{name}: restart needs a running Process: {before}')
        advertised = self.set_capability(via == 'engine')
        started = time.monotonic()
        answer = self.cli('process:restart', fixture['id'], '--idempotency-key=' + self.prefix + '-' + key)
        elapsed = round((time.monotonic() - started) * 1000)
        operation = self.latest('process.restart', fixture['id'])
        terminal = self.terminal(operation)
        summary = self.operation(operation, via)
        after = self.run_of(name)
        time.sleep(3)
        settled = self.run_of(name)
        row = self.process_row(fixture['id'])
        public = {k: terminal.get(k) for k in ('status', 'error_code', 'result')}
        require(answer['rc'] == 0 and self.running(name, after, on_demand) and after['run'] != before['run'] and settled == after,
                f'{name}: restart via {via} did not change the run exactly once: {before} -> {after} -> {settled}')
        # The projection Swamp's end writes: the lifecycle active, the desired state running, no failure left.
        require((row['status'], row['desired_state'], row['failed_step'], row['error_code']) == ('active', 'running', None, None)
                and public['status'] == 'succeeded' and public['error_code'] is None, f'{name}: Process projection or result {row} {public}')
        output = self.engine_steps(name, summary) if via == 'engine' else None
        if output is not None:
            require(output['instance'] == after['run'], f'{name}: the receipt names run {output["instance"]}, the Node runs {after["run"]}')
        seen = self.sibling(sibling) if sibling else None
        require(seen == watched, f'sibling {sibling} changed after {name} restart: {watched} -> {seen}')
        self.record(key, {'cli': {'rc': answer['rc'], 'secs': answer['secs']}, 'advertised_version': advertised['version'],
                          'operation': summary, 'public': public, 'row': row, 'run_before': before, 'run_after': after,
                          'run_3s_later': settled, 'restart_output': output, 'sibling': seen, 'request_through_terminal_ms': elapsed})
        self.timings[key] = elapsed
        return operation

    def replay(self, name, sibling=None):
        """process:restart with the restart step's receipt dropped after the Node ran it: the Process stays fenced, a second send
        answers from the journal, and the run changed once, not twice."""
        fixture = self.processes[name]
        path = f'processes/{fixture["id"]}'
        target = f'{self.node_id}:process-{fixture["id"]}'
        watched = self.sibling(sibling) if sibling else None
        before = self.run_of(name)
        require(self.running(name, before), f'{name}: replay needs a running Process: {before}')
        self.set_capability(True)
        directory = f'/etc/systemd/system/{fixture["unit"]}.d'
        if fixture['runtime'] == 'systemd':
            # The new run takes 8 seconds to start, so the engine is stopped before the receipt exists. A container's restart already
            # waits out its stop timeout (busybox `sleep` as PID 1 ignores SIGTERM). `mkdir` without -p never adopts a drop-in.
            self.guest(self.node, 'mkdir', directory)
            self.put(self.node, directory + '/proof-slow.conf', '[Service]\nExecStartPre=/bin/sleep 8\n')
            self.systemctl(self.node, 'daemon-reload')
        key = f'replay-restart-{name}'
        try:
            started = time.monotonic()
            operation = int(self.admitted('POST', path + '/restart', {}, key)['id'])
            try:
                wait_for(lambda: (rows := self.steps(operation)) and len(rows) == 2 and rows[1]['first_sent_ms'] and rows,
                         f'{operation} did not send its restart', 20, 0.05)
                self.systemctl('gateway', 'stop', ENGINE_UNIT)
                queued = self.helper('pending-order', self.node_id, operation, target, 'restart', self.PRESENT)
                dropped = wait_for(lambda: self.helper('drop-receipt', self.node_id, operation, target, 'restart', self.PRESENT),
                                   f'{operation} left no restart receipt to drop', 60)
                require(not dropped.get('kept'), f'{operation}: the Node answered {dropped.get("status")} {dropped.get("code")}, not a success to drop')
                replayed = self.admitted('POST', path + '/restart', {}, key)
                rows = self.steps(operation)
                require(replayed['id'] == operation and replayed['status'] not in TERMINAL and rows[1]['status'] != 'done',
                        f'{operation} ended or moved without its receipt')
                executed = self.run_of(name)
                probed = {'process_destroy': self.api('DELETE', path, {}, f'replay-remove-{name}')[2],
                          'process_stop': self.api('POST', path + '/stop', {}, f'replay-stop-{name}')[2],
                          'process_restart_new_key': self.api('POST', path + '/restart', {}, f'replay-other-{name}')[2]}
                require(set(probed.values()) == {'operation.target_busy'}, f'{name}: fence while outstanding {probed}')
                require(self.running(name, executed) and executed['run'] != before['run'],
                        f'{name}: the Node did not run the first send: {before} -> {executed}')
            finally:
                self.systemctl('gateway', 'start', ENGINE_UNIT)
            terminal = self.terminal(operation)
            elapsed = round((time.monotonic() - started) * 1000)
        finally:
            if fixture['runtime'] == 'systemd':
                self.guest(self.node, 'rm', '-f', directory + '/proof-slow.conf')
                self.guest(self.node, 'rmdir', directory)
                self.systemctl(self.node, 'daemon-reload')
        summary = self.operation(operation, 'engine')
        after = self.run_of(name)
        output = self.engine_steps(name, summary)
        restart = summary['steps'][1]
        done = [item['outcome']['status'] for item in summary['journal'] if item['operation'].endswith(':restart') and item['record'] == 'done']
        require(restart['sends'] >= 2 and len(done) == 1, f'{operation} did not replay one execution on a second send: {restart} {done}')
        # Exactly one restart: the run after the terminal receipt is the one the first send started, and the receipt names it.
        require(after == executed and output['instance'] == after['run'], f'{name}: the run changed again on the resend: {executed} -> {after}')
        seen = self.sibling(sibling) if sibling else None
        require(seen == watched, f'sibling {sibling} changed after the replay: {watched} -> {seen}')
        self.record(key, {'operation': summary, 'queued_signed_order': queued, 'dropped': dropped, 'probed_while_outstanding': probed,
                          'http_replay': {'id': replayed['id'], 'status': replayed['status']},
                          'public': {k: terminal.get(k) for k in ('status', 'error_code', 'result')}, 'run_before': before,
                          'run_after_first_send': executed, 'run_after_terminal': after, 'restart_output': output, 'sibling': seen,
                          'request_through_terminal_ms': elapsed})
        self.timings[key] = elapsed

    def retry(self, name):
        """A restart that fails after its stop: a drop-in whose ExecStartPre exits 1 leaves the unit failed and the Process failed with
        Swamp's step. With the drop-in gone, a new restart reads the failed unit afresh and runs it once."""
        fixture = self.processes[name]
        before = self.run_of(name)
        require(self.running(name, before), f'{name}: retry needs a running Process: {before}')
        self.set_capability(True)
        directory = f'/etc/systemd/system/{fixture["unit"]}.d'
        self.guest(self.node, 'mkdir', directory)
        try:
            self.put(self.node, directory + '/proof-fail.conf', '[Service]\nExecStartPre=/bin/false\n')
            self.systemctl(self.node, 'daemon-reload')
            answer = self.cli('process:restart', fixture['id'], '--idempotency-key=' + self.prefix + f'-{name}-restart-failed')
            operation = self.latest('process.restart', fixture['id'])
            terminal = self.terminal(operation, expect='failed')
            summary = self.operation(operation, 'engine')
            half = {'node': self.run_of(name), 'row': self.process_row(fixture['id']),
                    'operation_failed_step': self.db(f'SELECT failed_step FROM operations WHERE id={operation}')[0]['failed_step']}
            steps, failed = summary['steps'], summary
            self.record('retry-failed', {'cli_rc': answer['rc'], 'public': {k: terminal.get(k) for k in ('status', 'error_code')},
                                         'operation': summary, **half})
            require(answer['rc'] != 0 and terminal['error_code'] == 'operation.native_failed'
                    and [(step['name'], step['status']) for step in steps] == [('read', 'done'), ('restart', 'failed')]
                    and steps[1]['error_code'] == 'process.apply_failed' and half['node']['ActiveState'] in ('failed', 'inactive')
                    and half['row']['error_code'] == 'operation.native_failed' and half['operation_failed_step'] == 'Restart the Process',
                    f'{name}: failed restart {steps} {half} {terminal.get("error_code")}')
        finally:
            self.guest(self.node, 'rm', '-f', directory + '/proof-fail.conf')
            self.guest(self.node, 'rmdir', directory)
            self.systemctl(self.node, 'daemon-reload')
        answer = self.cli('process:restart', fixture['id'], '--idempotency-key=' + self.prefix + f'-{name}-restart-retried')
        again = self.latest('process.restart', fixture['id'])
        terminal = self.terminal(again)
        summary = self.operation(again, 'engine')
        after = self.run_of(name)
        row = self.process_row(fixture['id'])
        output = self.engine_steps(name, summary)
        require(answer['rc'] == 0 and again != operation and self.running(name, after) and after['run'] != before['run'] and output['instance'] == after['run']
                and (row['status'], row['desired_state'], row['failed_step'], row['error_code']) == ('active', 'running', None, None),
                f'{name}: retry did not converge {after} {row}')
        self.record('retry', {'failed': operation, 'failed_operation': failed,
                              'node_and_process_after_failure': half, 'retried': again, 'retried_operation': summary,
                              'run_before': before, 'run_after_retry': after, 'row': row})

    def refused(self, name, kind='process.restart'):
        """With the family advertised, a shape outside the signed contract runs wholly on Swamp: one Operation, a Swamp run, no
        engine step, no order. Its own outcome is Swamp's (a Horizon-shaped fixture has no artisan to run)."""
        require(kind == 'process.restart', 'the restart lane refuses restarts only')
        fixture = self.processes[name]
        self.set_capability(True)
        mark = self.db('SELECT coalesce(max(id), 0) AS id FROM operations')[0]['id']
        answer = self.cli('process:restart', fixture['id'], '--idempotency-key=' + self.prefix + '-refused-' + name)
        started = self.db(f'SELECT id, kind FROM operations WHERE id > {mark} ORDER BY id')
        require([row['kind'] for row in started] == ['process.restart'], f'{name}: process:restart started {started} (exit {answer["rc"]})')
        operation = int(started[0]['id'])
        terminal = wait_for(lambda: (row := self.api('GET', f'operations/{operation}')[1]) and row['status'] in TERMINAL and row,
                            f'Operation {operation} did not end', 140)
        summary = self.operation(operation, 'swamp')
        require(not summary['steps'] and not self.journal(operation), f'{name}: process:restart recorded engine work')
        self.record(f'refused-{name}', {'kind': kind, 'cli_rc': answer['rc'], 'operation': summary,
                                         'public': {k: terminal.get(k) for k in ('status', 'error_code')}})
