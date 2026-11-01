#!/usr/bin/python3
"""Publish main via existing Beast->Gateway->209 transport; no Git credentials leave Beast."""
import base64
import datetime
import fcntl
import hashlib
import json
import os
import pathlib
import re
import subprocess
import time
from loader import reload_plan, can_advance_after_failure
from registry_recovery_notice import announce_load
from interlocked_load import run as run_interlocked_load
from swamp_load_log import collect as collect_load_events

ROOT = pathlib.Path('/home/nckrtl/swamp-execution')
CODE = ROOT / 'platform/load-on-merge'
CACHE = ROOT / 'evidence/load-on-merge'
REPO = pathlib.Path('/home/nckrtl/.t3/worktrees/default/feat-process-required-mount-paths')
TRANSPORT = CODE / 'transport.py'
TARGET = re.compile(r'^[a-f0-9]{40}$')


def defer(reason, target=None):
    record = {'at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
              'action': 'deferred', 'reason': reason, 'target': target,
              'retry_after': time.time() + 300}
    (CACHE / 'publisher-backoff.json').write_text(json.dumps(record) + '\n')
    with (ROOT / 'THREADS.md').open('a') as stream:
        stream.write('\n' + record['at'] + ' — AUTOMATIC source publication blocked: ' + reason +
                     '; target ' + str(target) + '; next attempt after ' +
                     datetime.datetime.fromtimestamp(record['retry_after'], datetime.timezone.utc).isoformat() +
                     '. No drain/stop or native health/history request.\n')
    print(json.dumps(record), flush=True)


def command(args, timeout=30, **kwargs):
    result = subprocess.run(args, capture_output=True, text=True, timeout=timeout, **kwargs)
    if result.returncode:
        raise RuntimeError('command_failed:' + pathlib.Path(args[0]).name + ':' + str(result.returncode))
    return result.stdout.strip()


def remote(body, name):
    # Preserve the previously approved transport exactly, including its existing
    # Gateway SshExecutor credentials. Root is used only by the fixed host helper.
    data = base64.b64encode(body.encode()).decode()
    wrapper = "import subprocess,base64\nr=subprocess.run(['sudo','-n','/usr/bin/python3','-B','-'],input=base64.b64decode(" + repr(data) + "),capture_output=True,timeout=90)\nprint(r.stdout.decode(),end='')\nif r.returncode:print('remote_metadata_failure='+str(r.returncode))\nraise SystemExit(r.returncode)\n"
    helper = CACHE / (name + '.py')
    helper.write_text(wrapper)
    return json.loads(command(['/usr/bin/python3', str(TRANSPORT), str(helper)], timeout=120))


def main():
    os.umask(0o077)
    CACHE.mkdir(parents=True, exist_ok=True)
    with (CACHE / 'publisher.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        current = time.time()
        gate = CACHE/'load-rate.json'
        previous = json.loads(gate.read_text()).get('attempted_at', 0) if gate.exists() else 0
        if current - previous < 300:
            print(json.dumps({'action': 'five_minute_load_limit', 'eligible_at': previous+300})); return
        publish_locked()


def publish_locked():
    backoff = CACHE / 'publisher-backoff.json'
    if backoff.exists() and time.time() < json.loads(backoff.read_text())['retry_after']:
        print(json.dumps({'action': 'backoff', 'retry_after': json.loads(backoff.read_text())['retry_after']}))
        return
    # ls-remote checks only main; fetch/bundle work occurs only for a changed SHA.
    lines = command(['git', '-C', str(REPO), 'ls-remote', 'origin', 'refs/heads/main'],
                    env={**os.environ, 'GIT_TERMINAL_PROMPT': '0'}).split()
    target = lines[0]
    assert TARGET.fullmatch(target), 'invalid_main_revision'
    cursor_path = CACHE / 'load-log-cursor.json'
    cursor = json.loads(cursor_path.read_text()).get('last_event', '') if cursor_path.exists() else ''
    assert not cursor or re.fullmatch(r'[0-9TZ]+-(reload|restart)-[a-f0-9]{40}\.json', cursor), 'load_log_cursor_invalid'
    status = remote("""import json,pathlib,subprocess,fcntl
A='/home/orbit/apps/orbit-monorepo-staging/checkout';S=pathlib.Path('/var/lib/orbit-swamp-main-load');F=pathlib.Path('/home/orbit/.local/state/orbit-swamp/main-feed')
p=subprocess.run(['runuser','-u','orbit','--','git','-C',A,'rev-parse','HEAD'],capture_output=True,text=True,timeout=15)
assert p.returncode==0,'head_read_failed'
r={'head':p.stdout.strip(),'halted':(S/'halt.json').exists()}
with open('/home/orbit/.local/state/orbit-swamp/serve-load.lock','a') as maintenance:
 try:fcntl.flock(maintenance,fcntl.LOCK_EX|fcntl.LOCK_NB);r['maintenance_busy']=False
 except BlockingIOError:r['maintenance_busy']=True
if r['halted']:r['failure']=json.loads((S/'halt.json').read_text())
for key,path in [('proof',S/'last-proof.json'),('ready',F/'ready.json'),('restart_required',S/'restart-required.json')]:
 if path.is_file():
  value=json.loads(path.read_text())
  r[key]=({k:value.get(k)for k in ['loaded_sha','swamp_tree_sha','version_checked_at']}if key=='proof'else value)
r['load_events']=[json.loads(p.read_text()) for p in sorted((S/'load-events').glob('*.json')) if p.name>EVENT_CURSOR_LITERAL][:64]
print(json.dumps(r))
""".replace('EVENT_CURSOR_LITERAL', repr(cursor)), 'status-helper')
    appended = collect_load_events(status.get('load_events', []), CACHE)
    if appended:
        print(json.dumps({'action': 'load_records_appended', 'rows': appended}), flush=True)
    failure = status.get('failure', {})
    known_previous_failure = can_advance_after_failure(failure, target)
    if status['halted'] and not known_previous_failure:
        failure = status.get('failure', {})
        report = CACHE / ('failure-' + str(failure.get('target', 'unknown')) + '.json')
        if not report.exists():
            report.write_text(json.dumps(failure, indent=2) + '\n')
            with (ROOT / 'FLEET-CHANGES.md').open('a') as stream:
                stream.write('\nAUTOMATIC Node209 load halted: ' + str(failure.get('error', 'unknown')) + '; proof ' + str(report.relative_to(ROOT)) + '. No further automatic stop until operator review.\n')
        raise RuntimeError('node209_automatic_loader_halted_requires_operator')
    if status.get('maintenance_busy'):
        defer('maintenance_lock_reserved', target)
        return
    if status['head'] == target and status.get('ready', {}).get('target') == target and status.get('proof', {}).get('loaded_sha') != target:
        execute_published(target)
        return
    if status['head'] == target:
        print(json.dumps({'at': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                          'action': 'unchanged', 'sha': target}))
        return
    ready = status.get('ready', {})
    if ready.get('target') == target and ready.get('base') == status['head']:
        execute_published(target)
        return
    command(['git', '-C', str(REPO), 'fetch', 'origin', 'main'])
    command(['git', '-C', str(REPO), 'merge-base', '--is-ancestor', status['head'], target])
    bundle = CACHE / (target + '.bundle')
    command(['git', '-C', str(REPO), 'bundle', 'create', str(bundle), status['head'] + '..origin/main'])
    heads = command(['git', '-C', str(REPO), 'bundle', 'list-heads', str(bundle), 'refs/remotes/origin/main']).split()
    assert heads and heads[0] == target, 'main_moved_while_staging'
    payload = bundle.read_bytes()
    changed = command(['git', '-C', str(REPO), 'diff', '--relative=swamp/', '--name-only', status['head'], target, '--', 'swamp/']).splitlines()
    plan = reload_plan(changed)
    at = datetime.datetime.now(datetime.timezone.utc).isoformat()
    entry = ('\n' + at + ' — AUTOMATIC planned Node209 source load ' + status['head'] + '→' + target +
             '; mode ' + plan['mode'] + '; real Gateway release lock and shared host maintenance lock. ' +
             ('Workflow/startup changes restart directly. ' if plan['mode'] == 'restart' else 'Any stale reload restarts immediately, once. ') +
             'Health checks only; no family gates, receipts, schedule changes, credentials, grants or writer changes. '+
             'This line precedes publication of the one-use load authorization; first failure halts automation.\n')
    with (ROOT / 'THREADS.md').open('a') as stream:
        stream.write(entry)
        stream.flush()
        os.fsync(stream.fileno())
    manifest = {'target': target, 'base': status['head'], 'bundle': target + '.bundle',
                'load_mode': plan['mode'], 'restart_reasons': plan['restart_reasons'],
                'swamp_tree_sha': command(['git', '-C', str(REPO), 'rev-parse', target + ':swamp']),
                'bundle_sha256': hashlib.sha256(payload).hexdigest(),
                'threads_logged_at': at, 'threads_entry': entry,
                'threads_entry_sha256': hashlib.sha256(entry.encode()).hexdigest()}
    stage = """import base64,fcntl,json,pathlib,subprocess,hashlib,os,pwd,sys
os.umask(0o077);F=pathlib.Path('/home/orbit/.local/state/orbit-swamp/main-feed');A='/home/orbit/apps/orbit-monorepo-staging/checkout'
lock=open('/home/orbit/.local/state/orbit-swamp/serve-load.lock','a')
try:fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
except BlockingIOError:print(json.dumps({'action':'maintenance_busy'}));raise SystemExit(0)
manifest=json.loads(base64.b64decode(MANIFEST));data=base64.b64decode(BUNDLE)
assert hashlib.sha256(data).hexdigest()==manifest['bundle_sha256']
def git(*args):
 p=subprocess.run(['runuser','-u','orbit','--','git','-C',A,*args],capture_output=True,text=True,timeout=30,umask=0o022)
 assert p.returncode==0,'source_stage_git_failed'
 return p.stdout.strip()
assert git('rev-parse','HEAD')==manifest['base'],'source_stage_stale_base'
F.mkdir(mode=0o700,parents=True,exist_ok=True);account=pwd.getpwnam('orbit');os.chown(F,account.pw_uid,account.pw_gid)
path=F/manifest['bundle'];path.write_bytes(data);os.chown(path,account.pw_uid,account.pw_gid)
git('bundle','verify',str(path));git('fetch',str(path),'refs/remotes/origin/main');git('merge-base','--is-ancestor','HEAD',manifest['target'])
assert not git('status','--porcelain=v1','--untracked-files=all'),'source_drift_requires_main_pr'
changed=set(git('diff','--name-only','HEAD',manifest['target']).splitlines())
tmp=F/'ready.tmp';tmp.write_text(json.dumps(manifest));os.chown(tmp,account.pw_uid,account.pw_gid);tmp.replace(F/'ready.json')
print(json.dumps({'action':'published','target':manifest['target'],'changed_paths':len(changed),'local_edits_preserved':0}))
""".replace('MANIFEST', repr(base64.b64encode(json.dumps(manifest).encode()).decode())).replace('BUNDLE', repr(base64.b64encode(payload).decode()))
    staged = remote(stage, 'stage-helper')
    if staged.get('action') == 'maintenance_busy':
        defer('maintenance_lock_acquired_during_stage', target)
        return
    print(json.dumps(staged), flush=True)
    execute_published(target)

def execute_published(target):
    message = ('ANNOUNCED AUTOMATIC Node209 load ' + target +
               ': Gateway release lock and active0 required. Changed workflows restart directly while Lab4223 is open; '
               'other changes reload, then any served/disk mismatch triggers ONE immediate Serve restart. '
               'No restart spacing floor. Preserve configuration, state, pauses, workers and credentials. '
               'Full audit and configured-CA health required; failure halts without a second restart. No release resume.')
    announce_load(message)
    (CACHE/'load-rate.json').write_text(json.dumps({'attempted_at':time.time(), 'target':target}) + '\n')
    result = run_interlocked_load(message)
    print(json.dumps({'action': 'interlocked_load_completed', 'target': target, 'result': result}), flush=True)
    events = remote("import pathlib,json;S=pathlib.Path('/var/lib/orbit-swamp-main-load/load-events');print(json.dumps({'events':[json.loads(p.read_text())for p in sorted(S.glob('*.json'))]}))", 'completed-events')
    collect_load_events(events['events'], CACHE)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        if CACHE.exists():
            defer('publisher_failure:' + type(error).__name__)
        print(json.dumps({'action': 'failed', 'error_type': type(error).__name__,
                         'error': str(error) if isinstance(error, (AssertionError, RuntimeError)) else 'bounded_operation_failed'}), flush=True)
        raise SystemExit(1)
