#!/usr/bin/python3
"""One announced registry recovery. Run only under GatewayReleaseLock::run.

No binary/source/worker/credential changes. Native SIGTERM performs the drain.
The Gateway runner owns the release lock for this entire foreground command.
"""
import datetime
import fcntl
import hashlib
import importlib.util
import json
import pathlib
import signal
import ssl
import sys
import time
import urllib.request


def batch_key(pending):
    rows = sorted((r['type'], r['expected'], r['served']) for r in pending.get('mismatches', []))
    return hashlib.sha256(json.dumps([pending['source_sha'], rows]).encode()).hexdigest()


def check_guards(receipt, pending, runtime, last_stop, boot_id, entered, monotonic, utc_now, previous):
    assert receipt.get('gateway_release_lock') == 'GatewayReleaseLock::run', 'gateway_interlock_missing'
    assert receipt.get('gateway_active_release_count') == 0, 'gateway_release_active_or_unknown'
    assert receipt.get('gateway_auto_status_checked') is True, 'gateway_auto_status_missing'
    assert receipt.get('announcement'), 'restart_not_announced'
    announced = datetime.datetime.fromisoformat(receipt['announced_at'])
    assert 0 <= (utc_now - announced).total_seconds() <= 300, 'announcement_expired_or_future'
    assert receipt['source_sha'] == pending['source_sha'], 'announcement_source_changed'
    assert receipt['runtime_invocation'] == runtime['invocation'], 'serve_changed_since_announcement'
    key = batch_key(pending)
    assert receipt['batch_key'] == key and pending['mismatches'], 'announcement_batch_changed'
    assert previous.get('batch_key') != key, 'batch_already_attempted'
    return key


def configs(L):
    paths = [L.APP / '.swamp.yaml', L.APP / '.swamp/serve.yaml', *sorted((L.APP / 'vaults').rglob('*.yaml'))]
    return {str(p.relative_to(L.APP)): L.digest(p) for p in paths if p.is_file()}


def pauses(L):
    return {v['workflowId']: v.get('paused') for v in L.native('workflow', 'schedule', 'list', remote=True).get('schedules', [])}


def health():
    ctx = ssl.create_default_context(cafile='/usr/local/share/ca-certificates/orbit-root.crt')
    for url in ['http://127.0.0.1:9418/health', 'https://swamp.orbit/health']:
        with urllib.request.urlopen(url, context=ctx if url.startswith('https') else None, timeout=3) as r:
            assert r.status == 200 and json.load(r).get('status') == 'ok', 'health_not_ok'


PROTECTED_PRODUCTION_WORKFLOWS = {'automatic-production-deploy'}
PROTECTED_PRODUCTION_RUN_IDS = {'8f8478ee-880b-427e-a8c8-a101b4b29185'}
_LAST_DRAIN_DECISION = {}


def drain_reply(reply, quiet=None):
    # Direct Orchestrator standing rule: only production deploys and QUIET block.
    # Other interruption is accepted while Lab4223 remains unresolved.
    global _LAST_DRAIN_DECISION
    assert reply.get('type') == 'run.history', 'native_drain_status_unavailable'
    runs = reply.get('payload', {}).get('runs')
    assert isinstance(runs, list), 'native_drain_status_incomplete'
    assert all(isinstance(r, dict) and r.get('status') == 'running' and isinstance(r.get('id'), str) for r in runs), 'native_drain_status_incomplete'
    public = lambda row: {k: row.get(k) for k in ['id', 'runKind', 'workflowName', 'status', 'stale']}
    protected = set()
    for row in runs:
        name = row.get('workflowName')
        inputs = row.get('inputs') if isinstance(row.get('inputs'), dict) else {}
        production = (row['id'] in PROTECTED_PRODUCTION_RUN_IDS or name in PROTECTED_PRODUCTION_WORKFLOWS
                      or (name == 'operation-instance-deploy-direct' and
                          (inputs.get('environment') == 'production' or inputs.get('account') == 'production')))
        if production:
            protected.add(row['id'])
    changed = True
    while changed:
        changed = False
        for row in runs:
            parent = row.get('parentRunId') or row.get('parent_run_id')
            root = row.get('rootRunId') or row.get('root_run_id')
            if row['id'] not in protected and (parent in protected or root in protected or
                                              parent in PROTECTED_PRODUCTION_RUN_IDS or root in PROTECTED_PRODUCTION_RUN_IDS):
                protected.add(row['id']); changed = True
    quiet_active = isinstance(quiet, dict) and quiet.get('state') == 'QUIET_HELD'
    blocked = [public(row) for row in runs if quiet_active or row['id'] in protected]
    accepted = [public(row) for row in runs if not quiet_active and row['id'] not in protected]
    _LAST_DRAIN_DECISION = {'at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'policy': 'production_deploys_and_quiet_only', 'lab_issue': 4223,
        'active_quiet_record': quiet_active, 'protected_runs': blocked,
        'accepted_interruption_runs': accepted, 'native_terminal_inferred': False}
    if blocked or quiet_active:
        return {'state': 'restart_deferred_active_runs', 'active_runs': blocked,
                'accepted_interruption_runs': accepted, 'policy': 'production_deploys_and_quiet_only',
                'active_quiet_record': quiet_active}
    return None


def drain_before_restart(L):
    # The existing official admin run tracker includes workflow and method runs.
    # A refusal or unreadable result never counts as a drained boundary.
    js = """const token=(await Deno.readTextFile(Deno.args[0])).trim();
const ws=new WebSocket('ws://127.0.0.1:9418',{headers:{Authorization:`Bearer ${token}`}});
let timer;try{const response=await new Promise((resolve,reject)=>{timer=setTimeout(()=>reject(new Error('native_drain_status_timeout')),20000);ws.addEventListener('error',()=>reject(new Error('native_drain_status_unavailable')),{once:true});const id=crypto.randomUUID();ws.addEventListener('open',()=>ws.send(JSON.stringify({type:'run.history',id,payload:{active:true}})),{once:true});ws.addEventListener('message',e=>{const r=JSON.parse(e.data);if(r.id===id)resolve(r);});});console.log(JSON.stringify(response));}finally{clearTimeout(timer);ws.close();}"""
    reply = json.loads(L.command([str(L.HOME / '.swamp/deno/deno'), 'eval', '--quiet', js,
                                  str(L.HOME / '.config/swamp/serve-driver-token')], owner=True, timeout=25))
    quiet_path = L.STATE / 'quiet-window.json'
    quiet = json.loads(quiet_path.read_text()) if quiet_path.exists() else None
    decision = drain_reply(reply, quiet)
    L.atomic(L.STATE / 'last-load-drain-decision.json', _LAST_DRAIN_DECISION)
    return decision


def recover(L, receipt):
    with L.LOCK.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        pending_path = L.STATE / 'restart-required.json'
        if not pending_path.exists():
            return {'state': 'mismatch_already_cleared_no_stop'}
        L.clean_source()
        pending = json.loads(pending_path.read_text())
        assert L.git('rev-parse', 'HEAD') == pending['source_sha'], 'accepted_source_changed'
        assert not (L.STATE / 'halt.json').exists(), 'publication_halted_requires_diagnosis'
        runtime = L.serve_runtime()
        previous_path = L.STATE / 'last-registry-restart.json'
        previous = json.loads(previous_path.read_text()) if previous_path.exists() else {}
        stop_path = L.STATE / 'last-stop.json'
        last_stop = json.loads(stop_path.read_text()) if stop_path.exists() else {}
        entered = int(L.command(['systemctl', 'show', L.SERVE, '-p', 'ActiveEnterTimestampMonotonic', '--value'])) / 1e6
        boot = pathlib.Path('/proc/sys/kernel/random/boot_id').read_text().strip()
        key = check_guards(receipt, pending, runtime, last_stop, boot, entered, time.monotonic(),
                           datetime.datetime.now(datetime.timezone.utc), previous)
        audit = L.audit_behavior_versions()
        assert not audit['errors'], 'prestop_version_audit_incomplete'
        if not audit['mismatches']:
            L.record_restart_requirement(audit, pending['source_sha'])
            return {'state': 'fresh_audit_matches_no_stop', 'versions': audit}
        fresh = {**pending, 'mismatches': audit['mismatches']}
        assert batch_key(fresh) == key, 'fresh_audit_batch_changed_reannounce'
        cfg, pause_before = configs(L), pauses(L)
        health()
        # Recheck the announcement guards immediately before stop.
        check_guards(receipt, pending, L.serve_runtime(), last_stop, boot, entered, time.monotonic(),
                     datetime.datetime.now(datetime.timezone.utc), previous)
        deferred = drain_before_restart(L)
        if deferred:
            return deferred
        state = {'state': 'draining', 'batch_key': key, 'source_sha': pending['source_sha'],
                 'announcement': receipt, 'runtime_before': runtime, 'stop_begin': L.now()}
        # Claim before any stop: a failed/repeated timer must never restart this batch twice.
        L.atomic(previous_path, state)
        L.atomic(stop_path, {'at': L.now(), 'monotonic': time.monotonic(), 'boot_id': boot,
                             'target': pending['source_sha'], 'reason': 'announced_registry_mismatch'})
        started = time.monotonic()
        try:
            # One systemd restart job drains with the existing SIGTERM policy
            # and then starts. Recovery does not depend on this SSH client
            # surviving the stop/start boundary. Never issue a second job.
            L.command(['systemctl', 'restart', '--no-block', L.SERVE], timeout=15)
            deadline = time.monotonic() + 360
            while True:
                try:
                    after = L.serve_runtime()
                    assert after['invocation'] != runtime['invocation'], 'old_draining_process_not_recovered'
                    health()
                    break
                except Exception:
                    if time.monotonic() >= deadline:
                        raise RuntimeError('postrestart_health_deadline')
                    time.sleep(1)
            recovered = time.monotonic()
            stopped_at = int(L.command(['systemctl', 'show', L.SERVE, '-p', 'InactiveEnterTimestampMonotonic', '--value'])) / 1e6
            state.update(health_at=L.now(), drain_to_health_seconds=round(recovered - started, 3),
                         stop_to_health_seconds=round(recovered - stopped_at, 3) if started <= stopped_at <= recovered else None,
                         runtime_after=after, downtime_basis='systemd stop-complete to new-invocation configured-CA health; continuous external outage not sampled')
            assert state['runtime_after']['invocation'] != runtime['invocation'], 'serve_not_restarted'
            assert all(state['runtime_after'][x] == runtime[x] for x in ['remote_only', 'hot_reload', 'scheduled_runs']), 'execution_policy_changed'
            assert configs(L) == cfg and pauses(L) == pause_before, 'configuration_or_pause_changed'
            assert L.git('rev-parse', 'HEAD') == pending['source_sha'], 'source_changed_during_restart'
            L.clean_source()
            audit = L.audit_behavior_versions()
            state['versions'] = audit
            assert not audit['errors'] and not audit['mismatches'], 'postrestart_version_mismatch_or_unknown'
            L.record_restart_requirement(audit, pending['source_sha'])
            state['state'] = 'healthy_versions_match'
            state['load_event'] = L.record_load_event({
                'load_mode': 'restart', 'loaded_sha': pending['source_sha'],
                'version_checked_at': L.now(), 'behavior_versions': audit,
            })
            return state
        except Exception as exc:
            state.update(state='failed_requires_diagnosis_no_second_restart', error=type(exc).__name__)
            raise
        finally:
            L.atomic(previous_path, state)


def main():
    spec = importlib.util.spec_from_file_location('L', '/usr/local/libexec/orbit-swamp-main-load.py')
    L = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(L)
    receipt = json.loads(sys.stdin.read())
    def deadline(signum, frame):
        raise TimeoutError('registry_recovery_660s_budget')
    signal.signal(signal.SIGALRM, deadline)
    signal.alarm(660)  # Gateway's existing foreground SSH route retains its lock for720s.
    try:
        print(json.dumps(recover(L, receipt)))
    finally:
        signal.alarm(0)


if __name__ == '__main__':
    main()
