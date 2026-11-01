#!/usr/bin/python3
"""Node209 foreground loader; all effects require the Gateway release interlock."""
import datetime
import fcntl
import hashlib
import importlib.util
import json
import os
import sys
sys.dont_write_bytecode = True
os.environ["PYTHONDONTWRITEBYTECODE"] = "1"
import pathlib
import re
import subprocess
import time
import types
import urllib.request

APP = pathlib.Path('/home/orbit/apps/orbit-monorepo-staging/checkout/swamp')
HOME = pathlib.Path('/home/orbit')
STATE = pathlib.Path('/var/lib/orbit-swamp-main-load')
FEED = HOME / '.local/state/orbit-swamp/main-feed'
LOCK = HOME / '.local/state/orbit-swamp/serve-load.lock'
SERVE = 'orbit-process-392-swamp-serve.service'
SHA = re.compile(r'^[a-f0-9]{40}$')
# 2026-10-30 boot spent85s in native stale sweep before listening.
HEALTH_READY_SECONDS = 180
ENV = ['HOME=/home/orbit', 'PATH=/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin',
       'SWAMP_HOME=/home/orbit/.swamp', 'SWAMP_CONFIG_DIR=/home/orbit/.config/swamp',
       'SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key',
       'SWAMP_NO_UPDATE_CHECK=1', 'SWAMP_NO_TELEMETRY=1', 'PYTHONDONTWRITEBYTECODE=1']


def reload_plan(paths):
    # Lab4223: workflows/startup modules restart directly; stale reloads restart once.
    runtime_source = lambda p: not p.endswith(('_test.ts', '_test.py', '.md'))
    startup = sorted(p for p in paths if runtime_source(p) and
                     p.startswith(('workflows/', 'extensions/vaults/', 'extensions/datastores/',
                                   'extensions/reports/', 'extensions/webhooks/')))
    if startup:
        return {'mode': 'restart', 'restart_reasons': startup}
    runtime = any((p.startswith(('workflows/', 'models/', 'definitions/', 'extensions/')) and runtime_source(p))
                  or p in {'.swamp/extensions.lock', '.swamp.lock', '.swamp.yaml', '.swamp/serve.yaml', '.swamp-sources.yaml'} for p in paths)
    return {'mode': 'hot_reload' if runtime else 'source_only', 'restart_reasons': []}


def execution_policy(argv, environment):
    truth = {'1', 'true', 'yes', 'on'}
    remote = '--remote-only' in argv or environment.get('SWAMP_REMOTE_ONLY', '').lower() in truth
    hot = '--hot-reload' in argv
    count = environment.get('SWAMP_MAX_CONCURRENT_SCHEDULED_RUNS', '1')
    if '--max-concurrent-scheduled-runs' in argv:
        count = argv[argv.index('--max-concurrent-scheduled-runs') + 1]
    return {'remote_only': remote, 'hot_reload': hot, 'scheduled_runs': int(count)}


def serve_runtime():
    state = dict(x.split('=', 1) for x in command(['systemctl', 'show', SERVE, '-p', 'MainPID', '-p', 'InvocationID']).splitlines())
    pid = int(state['MainPID']); assert pid > 0, 'serve_not_running'
    proc = pathlib.Path('/proc') / str(pid)
    argv = [x.decode() for x in (proc / 'cmdline').read_bytes().split(b'\0') if x]
    environment = dict(x.decode().split('=', 1) for x in (proc / 'environ').read_bytes().split(b'\0') if b'=' in x)
    return {'pid': pid, 'invocation': state['InvocationID'], **execution_policy(argv, environment)}


def reload_serve():
    # Native acknowledged reload; local SIGHUP only confirms signal delivery.
    response = json.loads(command([str(HOME / '.local/bin/swamp'), 'serve', 'reload',
                                  '--server', 'ws://127.0.0.1:9418', '--token-file',
                                  str(HOME / '.config/swamp/serve-driver-token'), '--json'], owner=True, timeout=150,
                                  request_environment={'SWAMP_SERVE_TIMEOUT_MS': '120000'}))
    assert response.get('success') is True and not response.get('errors'), 'native_reload_failed_or_partial'
    return response


def audit_behavior_versions(changed=None):
    path = pathlib.Path(__file__).with_name('behavior_versions.py')
    spec = importlib.util.spec_from_file_location('behavior_versions', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)

    rows = module.inventory(APP, changed)
    names = ['__registry__'] + sorted({row['type'] for row in rows if not row.get('error')})
    if names:
        reader = pathlib.Path(__file__).with_name('behavior_versions_reader.ts')
        token_file = HOME / '.config/swamp/serve-driver-token'
        replies = json.loads(command([str(HOME / '.swamp/deno/deno'), 'run', '--quiet', '--no-prompt',
                                     '--allow-read=' + str(token_file), '--allow-net=127.0.0.1:9418',
                                     str(reader), str(token_file), json.dumps(names)], owner=True, timeout=180))
        values = {reply['type']: reply for reply in replies}
    else:
        values = {}

    def describe(name):
        reply = values.get(name, {})
        if reply.get('error') or not reply.get('version'):
            raise RuntimeError(reply.get('error', 'metadata_version_missing'))
        return reply['version']

    audit = module.audit(APP, describe, changed, rows=rows)
    registry = values.get('__registry__', {})
    if registry.get('error') or not registry.get('version'):
        audit['errors'].append({'error': 'served_registry_inventory_missing'})
    else:
        covered = module.coverage(rows, json.loads(registry['version']))
        audit.update(covered)
        audit['errors'].extend({'type': name, 'error': key} for key in ['served_without_source', 'source_not_served'] for name in covered[key])
    return audit


def record_restart_requirement(audit, target):
    path = STATE / 'restart-required.json'
    previous = json.loads(path.read_text()) if path.exists() else {}
    pending = {row['type']: row for row in previous.get('mismatches', [])}
    for row in audit.get('versions', []):
        if row.get('error'):
            continue
        if row.get('expected') != row.get('served'):
            pending[row['type']] = row
        else:
            pending.pop(row['type'], None)
    if not pending:
        if path.exists():
            path.unlink()
        return False
    atomic(path, {'at': now(), 'source_sha': target, 'mismatches': list(pending.values()),
                  'restart_not_before': now(), 'announcement_required': True,
                  'action': 'immediate_interlocked_restart'})
    return True


def record_load_event(proof):
    path = pathlib.Path(__file__).with_name('load_events.py')
    spec = importlib.util.spec_from_file_location('load_events', path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.retain(types.SimpleNamespace(**globals()), proof)


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def atomic(path, value):
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, indent=2) + '\n')
    tmp.replace(path)


def command(args, timeout=30, owner=False, request_environment=None):
    extra = [key + '=' + value for key, value in (request_environment or {}).items()]
    if owner:
        args = ['runuser', '-u', 'orbit', '--', 'env', *ENV, *extra, *args]
    elif extra:
        args = ['env', *extra, *args]
    result = subprocess.run(args, capture_output=True, text=True,
                            timeout=timeout, cwd=APP, umask=0o022)
    if result.returncode:
        raise RuntimeError('command_failed:' + pathlib.Path(args[0]).name + ':' + str(result.returncode))
    return result.stdout.strip()


def git(*args):
    return command(['git', '-C', str(APP), *args], owner=True)


def native(*args, remote=False):
    if remote:
        args = (*args, '--server', 'ws://127.0.0.1:9418', '--token-file',
                str(HOME / '.config/swamp/serve-driver-token'))
    return json.loads(command([str(HOME / '.local/bin/swamp'), *args,
                              '--repo-dir', str(APP), '--json'], owner=True, timeout=45))


def clean_source():
    drift = git('status', '--porcelain=v1', '--untracked-files=all')
    assert not drift, 'source_drift:' + ','.join(drift.splitlines())[:600]


def consume_accepted_feed(manifest, head):
    """Retire only a manifest whose exact revision has an accepted proof."""
    proof = STATE / 'last-proof.json'
    if manifest['target'] != head or not proof.exists():
        return False
    accepted = json.loads(proof.read_text())
    audit = accepted.get('behavior_versions', {})
    if accepted.get('loaded_sha') != head or not audit.get('checked') or audit.get('checked') != audit.get('matched') or audit.get('errors') or audit.get('mismatches'):
        return False
    path = FEED / 'ready.json'
    assert json.loads(path.read_text()) == manifest, 'feed_changed_under_lock'
    atomic(STATE / 'last-consumed-feed.json', {'at': now(), 'accepted_sha': head, 'manifest': manifest})
    path.unlink()
    return True


def preflight(manifest):
    target, base = manifest['target'], manifest['base']
    assert SHA.fullmatch(target) and SHA.fullmatch(base), 'invalid_revision'
    assert manifest['bundle'] == target + '.bundle', 'invalid_bundle_path'
    assert git('rev-parse', 'HEAD') in (base, target), 'stale_feed_base'
    assert digest(FEED / manifest['bundle']) == manifest['bundle_sha256'], 'bundle_digest'
    git('merge-base', '--is-ancestor', base, target)
    assert git('rev-parse', target + ':swamp') == manifest['swamp_tree_sha'], 'swamp_tree_mismatch'
    clean_source()
    assert hashlib.sha256(manifest['threads_entry'].encode()).hexdigest() == manifest['threads_entry_sha256'], 'stop_log_digest'
    assert manifest['threads_logged_at'], 'missing_pre_stop_threads_log'
    return target, set(git('diff', '--relative', '--name-only', base, target, '--', '.').splitlines())


def snapshot():
    # Loads do not inspect family capabilities, workers or retired polled state.
    return {'head': git('rev-parse', 'HEAD')}


def validate(before, target, changed):
    deadline = time.monotonic() + HEALTH_READY_SECONDS
    while True:
        try:
            with urllib.request.urlopen('http://127.0.0.1:9418/health', timeout=3) as response:
                assert json.load(response)['status'] == 'ok'
            break
        except Exception:
            if time.monotonic() >= deadline:
                raise RuntimeError('health_deadline_exceeded')
            time.sleep(1)
    assert git('rev-parse', 'HEAD') == target, 'source_revision_mismatch'
    clean_source()
    return {'loaded_sha': target, 'swamp_tree_sha': git('rev-parse', 'HEAD:swamp'), 'ready_at': now(), 'health': 'ok', 'source_clean': True}


def apply_load(plan, receipt, target, before):
    policy_path = pathlib.Path(__file__).with_name('immediate_load_policy.py')
    spec = importlib.util.spec_from_file_location('immediate_load_policy', policy_path)
    policy = importlib.util.module_from_spec(spec); spec.loader.exec_module(policy)
    L = types.SimpleNamespace(**globals())
    if plan['mode'] == 'restart':
        return policy.restart(L, receipt)
    proof = {'loaded_sha': target, 'swamp_tree_sha': git('rev-parse', 'HEAD:swamp'),
             'load_mode': 'hot_reload', 'runtime_before': before}
    try:
        if plan['mode'] == 'hot_reload':
            proof['reload_result'] = reload_serve()
        audit = audit_behavior_versions()
    except Exception as exc:
        audit = {'checked': 0, 'matched': 0, 'mismatches': [],
                 'errors': [{'error': type(exc).__name__}], 'versions': []}
    proof.update(behavior_versions=audit, version_checked_at=now())
    proof['load_event'] = record_load_event(proof)
    if not audit.get('checked') or audit['checked'] != audit.get('matched') or audit['errors'] or audit['mismatches']:
        return policy.restart(L, receipt)
    policy.load_module('load_health', '/usr/local/libexec/guarded_registry_restart.py').health()
    assert serve_runtime() == before, 'unexpected_runtime_change_during_reload'
    proof.update(runtime_after=serve_runtime(), health='ok')
    atomic(STATE/'last-proof.json', proof)
    record_restart_requirement(audit, target)
    return proof


def can_advance_after_failure(failure, target):
    return failure.get('error') == 'postrestart_served_disk_false' and failure.get('target') != target


def main(receipt=None):
    # The host timer may inspect this entry point, but cannot perform a load.
    if not receipt or receipt.get('gateway_release_lock') != 'GatewayReleaseLock::run':
        print(json.dumps({'action': 'requires_gateway_interlocked_publisher'})); return
    assert receipt.get('gateway_auto_status_checked') is True and receipt.get('gateway_active_release_count') == 0
    assert receipt.get('announcement'), 'load_not_announced'
    os.umask(0o077); STATE.mkdir(mode=0o700, parents=True, exist_ok=True)
    with LOCK.open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        ready = FEED/'ready.json'
        if not ready.exists():
            print(json.dumps({'action': 'no_pending_feed'})); return
        manifest = json.loads(ready.read_text()); head = git('rev-parse', 'HEAD')
        halted = STATE/'halt.json'
        if halted.exists():
            failure = json.loads(halted.read_text())
            if not can_advance_after_failure(failure, manifest['target']):
                raise RuntimeError('loader_halted_requires_diagnosis')
            halted.replace(STATE/('failed-load-'+failure['target']+'.json'))
        if head == manifest['target'] and consume_accepted_feed(manifest, head):
            print(json.dumps({'action': 'accepted_feed_consumed', 'sha': head})); return
        try:
            target, changed = preflight(manifest)
            before = serve_runtime(); assert before['remote_only'] and before['scheduled_runs'] == 1
            plan = reload_plan(changed)
            if plan['mode'] == 'restart':
                spec = importlib.util.spec_from_file_location('restart_checks', pathlib.Path(__file__).with_name('guarded_registry_restart.py'))
                checks = importlib.util.module_from_spec(spec); spec.loader.exec_module(checks)
                deferred = checks.drain_before_restart(types.SimpleNamespace(**globals()))
                if deferred:
                    print(json.dumps(deferred)); return
            clean_source(); git('merge', '--ff-only', target)
            git('update-ref', 'refs/remotes/origin/main', target); clean_source()
            result = apply_load(plan, receipt, target, before)
            if result.get('state') == 'restart_deferred_active_runs':
                print(json.dumps(result)); return
            consume_accepted_feed(manifest, target)
            print(json.dumps(result))
        except Exception as exc:
            atomic(STATE/'halt.json', {'target': manifest['target'], 'at': now(),
                   'error': str(exc) if isinstance(exc, (RuntimeError, AssertionError)) else type(exc).__name__})
            raise


if __name__ == '__main__':
    main(json.loads(sys.stdin.read()) if '--gateway-interlocked' in sys.argv else None)
