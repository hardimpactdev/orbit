#!/usr/bin/env python3
"""S1 case: one real `orbit firewall:allow` changes real traffic. Started by allow-fresh-ipv4-tcp.sh."""
import datetime
import json
import os
import random
import re
import signal
import subprocess
import sys
import time
from pathlib import Path

CASE = 'allow-fresh-ipv4-tcp'
STARTED = time.time()
CASE_DIR = Path(__file__).resolve().parent
SOURCE = Path(os.environ.get('PROOF_SOURCE') or CASE_DIR.parents[3])
CLI_ENV = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'HOME': '/home/orbit', 'ORBIT_HOME': '/home/orbit/.orbit'}
UTC = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
GROUP = os.environ.get('PROOF_GROUP', '3187')
if os.environ.get('PROOF_RECEIPTS'):
    RECEIPT_ROOT = Path(os.environ['PROOF_RECEIPTS'])
elif os.environ.get('PROOF_EVIDENCE'):
    RECEIPT_ROOT = Path(os.environ['PROOF_EVIDENCE']).parents[1] / 'cases'
else:
    RECEIPT_ROOT = SOURCE / '.orbit-artifacts' / GROUP / 'cases'
RECEIPT = RECEIPT_ROOT / CASE / UTC
RECEIPT.mkdir(parents=True, exist_ok=True)
DENY_COMMENT = 'proof:test-deny'
LISTENER = (CASE_DIR / 'listener.py').read_text()


class Unrun(Exception):
    pass


def now():
    return time.time()


def sh(instance, command, timeout=60, stdin_text=None):
    try:
        return subprocess.run(['incus', 'exec', instance, '--', 'sh', '-c', command], capture_output=True, text=True, timeout=timeout,
                              input=stdin_text, stdin=None if stdin_text is not None else subprocess.DEVNULL)
    except subprocess.TimeoutExpired as error:
        return subprocess.CompletedProcess(['incus', 'exec', instance], 124, error.stdout or '', 'timeout')


def doctor_after_report(label, node_id, bound=75):
    """Doctor reads the Node's last UFW report. The agent reports a change at its next host tick, at most 60 s later,
    so a drift seen right after the command is polled until the report arrives or the bound passes."""
    deadline = time.monotonic() + bound
    while True:
        result = cli(label, 'doctor', '--node=' + str(node_id))
        settled = result['rc'] == 0 and ((result['json'] or {}).get('summary') or {}).get('drift') == 0
        if settled or time.monotonic() >= deadline:
            return result
        time.sleep(5)


def cli(label, *args, timeout=240):
    t0 = now()
    completed = subprocess.run(['/usr/local/bin/orbit-nested', *args, '--json'], capture_output=True, text=True, timeout=timeout,
                               env=CLI_ENV, cwd='/', stdin=subprocess.DEVNULL)
    (RECEIPT / ('cli-' + label + '.json')).write_text(completed.stdout)
    (RECEIPT / ('cli-' + label + '.stderr')).write_text(completed.stderr)
    try:
        parsed = json.loads(completed.stdout)
    except ValueError:
        parsed = None
    return {'rc': completed.returncode, 'json': parsed, 'secs': round(now() - t0, 2), 'args': ['orbit', *args, '--json']}


# ---- topology discovery
def discover():
    result_path = os.environ.get('PROOF_RESULT')
    if not result_path or not Path(result_path).exists():
        raise Unrun('PROOF_RESULT is missing; the topology cannot be identified')
    nodes = json.loads(Path(result_path).read_text())['topology']['construction']['nodes']
    listed = cli('node-list', 'node:list')
    if listed['rc'] or not listed['json']:
        raise Unrun('node:list failed: ' + str(listed['rc']))
    ids = {n['name']: n['id'] for n in listed['json']['nodes']}
    topology = {}
    for name in ('gateway', 'app-dev', 'app-prod'):
        if name not in nodes or name not in ids:
            raise Unrun('node missing from the topology: ' + name)
        instance = nodes[name]['instance']
        addrs = sh(instance, 'ip -4 -o addr show').stdout
        bridge = nodes[name]['incus_address']
        if bridge not in addrs:
            raise Unrun(f'{name}: bridge address {bridge} not found on {instance}')
        wg = sh(instance, 'ip -4 -o addr show dev orbit').stdout.split()
        topology[name] = {'instance': instance, 'node_id': ids[name], 'bridge': bridge,
                          'wireguard': wg[3].split('/')[0] if len(wg) > 3 else None}
    return topology


def used_source(topology, name):
    """The source address a probe from `name` to app-dev really uses, from the kernel route."""
    out = sh(topology[name]['instance'], 'ip -4 route get ' + topology['app-dev']['bridge']).stdout.split()
    return out[out.index('src') + 1] if 'src' in out else None


# ---- ufw
UFW_ROW = re.compile(r'^\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)(?: IN| OUT| FWD)?\s{2,}(.+?)(?:\s+#\s*(.*))?$')


def ufw_numbered(target):
    text = sh(target['instance'], 'ufw status numbered').stdout
    rows = []
    for line in text.splitlines():
        m = UFW_ROW.match(line.strip())
        if m:
            rows.append({'n': int(m[1]), 'to': m[2].strip(), 'action': m[3], 'from': m[4].strip(), 'comment': (m[5] or '').strip()})
    return text, rows


def ufw_plain(target):
    return '\n'.join(line.rstrip() for line in sh(target['instance'], 'ufw status').stdout.splitlines())


def v4(rows):
    return [r for r in rows if '(v6)' not in r['to'] and '(v6)' not in r['from']]


def probe(topology, source, port, wait=3):
    """Connect from `source` node to app-dev:port. Returns ('connected'|'blocked'|'refused', rc)."""
    r = sh(topology[source]['instance'], f"timeout {wait} bash -c 'echo > /dev/tcp/{topology['app-dev']['bridge']}/{port}'", timeout=wait + 20)
    if r.returncode == 0:
        return 'connected', 0
    return ('blocked' if r.returncode == 124 else 'refused'), r.returncode


def free_ports(target):
    for _ in range(50):
        port = random.randint(47000, 47900)
        used = sh(target['instance'], 'ss -Htln').stdout
        if f':{port} ' not in used and f':{port + 1} ' not in used:
            return port, port + 1
    raise Unrun('no free test port pair on app-dev')


def run_phase(topology, negative):
    phase = {'name': 'negative' if negative else 'positive', 'assertions': [], 'timings': {}, 'setup': {}, 'cleanup': {}, 'status': 'unrun'}
    dev = topology['app-dev']
    state = {'allow_started': False, 'deny_inserted': False, 'listeners': []}
    t = phase['timings']

    def check(name, ok, observed, expected):
        phase['assertions'].append({'name': name, 'passed': bool(ok), 'expected': expected, 'observed': observed})

    def timed(key, t0):
        t[key] = round(now() - t0, 2)

    gw_src = used_source(topology, 'gateway')
    prod_src = used_source(topology, 'app-prod')
    phase['setup']['source_addresses'] = {'gateway': gw_src, 'app-prod': prod_src, 'target': dev['bridge'],
                                          'path': 'bridge (enp5s0); the nested Gateway and app-prod reach app-dev over the Incus bridge, not WireGuard'}
    baseline = {}
    try:
        t0 = now()
        if not gw_src or not prod_src or gw_src == prod_src:
            raise Unrun(f'probe sources not distinct or unknown: gateway={gw_src} app-prod={prod_src}')
        port, control = free_ports(dev)
        phase['port'], phase['control_port'] = port, control
        sh(dev['instance'], 'cat > /tmp/proof-listen.py', stdin_text=LISTENER)
        for p in (port, control):
            log = f'/tmp/proof-listen-{p}.log'
            # The listener detaches only after it listens, and prints its pid: no wait is needed.
            r = sh(dev['instance'], f'rm -f {log}; python3 /tmp/proof-listen.py {p} {log}')
            state['listeners'].append((p, r.stdout.strip()))
        local = [sh(dev['instance'], f"timeout 3 bash -c 'echo > /dev/tcp/127.0.0.1/{p}'").returncode for p in (port, control)]
        phase['setup']['local_listener_rc'] = local
        if local != [0, 0]:
            raise Unrun(f'listeners on app-dev are not healthy locally: {local}')
        reach = {n: probe(topology, n, control, 3)[0] for n in ('gateway', 'app-prod')}
        phase['setup']['control_reach_before'] = reach
        if set(reach.values()) != {'connected'}:
            raise Unrun(f'control port not reachable from both sources: {reach}')
        text, rows = ufw_numbered(dev)
        baseline['ufw_plain'] = ufw_plain(dev)
        baseline['ufw_numbered'] = text
        before = cli('firewall-list-before', 'firewall:list', '--node=' + str(dev['node_id']))
        baseline['rules'] = (before['json'] or {}).get('rules')
        doc = cli('doctor-before', 'doctor', '--node=' + str(dev['node_id']))
        baseline['doctor_drift'] = ((doc['json'] or {}).get('summary') or {}).get('drift')
        phase['setup']['baseline'] = {'rules': baseline['rules'], 'doctor_drift': baseline['doctor_drift'], 'ufw': text}
        net = [r['n'] for r in v4(rows) if r['comment'] == 'proof-network']
        position = net[0] if net else 1
        phase['setup']['deny_insert_position'] = position
        ins = sh(dev['instance'], f"ufw insert {position} deny proto tcp from any to any port {port} comment '{DENY_COMMENT}'")
        state['deny_inserted'] = True
        if ins.returncode:
            raise Unrun('test deny insert failed: ' + ins.stdout + ins.stderr)
        _, rows = ufw_numbered(dev)
        deny_rows = [r for r in v4(rows) if r['comment'] == DENY_COMMENT]
        pn = [r['n'] for r in v4(rows) if r['comment'] == 'proof-network']
        phase['setup']['ufw_with_deny'] = ufw_numbered(dev)[0]
        if len(deny_rows) != 1 or (pn and deny_rows[0]['n'] > pn[0]):
            raise Unrun(f'test deny is not above proof-network: deny={deny_rows} proof-network={pn}')
        blocked = {n: probe(topology, n, port, 3) for n in ('gateway', 'app-prod')}
        phase['setup']['blocked_before'] = {n: v[0] for n, v in blocked.items()}
        if any(v[0] != 'blocked' for v in blocked.values()):
            raise Unrun(f'test port was not blocked before the command (case is UNRUN, not a pass): {blocked}')
        timed('setup', t0)

        # ---- action
        t0 = now()
        state['allow_started'] = True
        action = cli('allow', 'firewall:allow', str(port), '--node=' + str(dev['node_id']), '--from=' + gw_src, '--protocol=tcp')
        timed('cli_allow', t0)
        op = action['json'] or {}
        phase['operation'] = {'id': op.get('id'), 'kind': op.get('kind'), 'status': op.get('status'), 'error_code': op.get('error_code'),
                              'request_id': op.get('request_id'), 'cli_rc': action['rc'], 'secs': action['secs']}
        phase['status'] = 'ran'
        if negative:
            _, rows = ufw_numbered(dev)
            mine = [r for r in v4(rows) if r['to'] == f'{port}/tcp' and r['action'] == 'ALLOW' and r['from'] == gw_src]
            phase['mutation'] = {'deleted_managed_ufw_entries': [r['n'] for r in mine]}
            for r in sorted(mine, key=lambda r: -r['n']):
                sh(dev['instance'], f"ufw --force delete {r['n']}")

        # ---- assertions
        t0 = now()
        check('CLI succeeded with a completed Operation', action['rc'] == 0 and op.get('status') == 'succeeded' and op.get('id') and op.get('request_id'),
              phase['operation'], 'exit 0, Operation succeeded, Operation id and request id reported')
        gw_probe = probe(topology, 'gateway', port, 3)
        prod_probe = probe(topology, 'app-prod', port, 3)
        check('gateway source connects to the test port', gw_probe[0] == 'connected', {'source': gw_src, 'result': gw_probe[0], 'rc': gw_probe[1]}, 'connected')
        check('app-prod source stays blocked on the test port', prod_probe[0] == 'blocked', {'source': prod_src, 'result': prod_probe[0], 'rc': prod_probe[1]}, 'blocked (timeout)')
        listed = cli('firewall-list-after', 'firewall:list', '--node=' + str(dev['node_id']))
        rules = (listed['json'] or {}).get('rules') or []
        mine = [r for r in rules if str(r.get('port')) == str(port) and r.get('action') == 'allow' and r.get('protocol') == 'tcp'
                and r.get('source') == gw_src and r.get('status') == 'active']
        check('firewall:list has exactly one matching active rule', len(mine) == 1, {'matching': mine, 'all_rules': rules}, 'exactly 1 active allow tcp %s from %s' % (port, gw_src))
        text, rows = ufw_numbered(dev)
        phase['ufw_after_command'] = text
        entries = [r for r in v4(rows) if r['to'] == f'{port}/tcp' and r['action'] == 'ALLOW' and r['from'] == gw_src]
        denies = [r for r in v4(rows) if r['comment'] == DENY_COMMENT]
        check('exactly one matching UFW entry', len(entries) == 1, entries, f'one ALLOW {port}/tcp from {gw_src}')
        order_ok = len(entries) == 1 and len(denies) == 1 and entries[0]['n'] < denies[0]['n']
        check('UFW entry is ordered above the test deny', order_ok, {'allow': entries, 'test_deny': denies}, 'allow rule number < test deny rule number')
        ctl = {n: probe(topology, n, control, 3)[0] for n in ('gateway', 'app-prod')}
        check('control port still works from both sources', set(ctl.values()) == {'connected'}, ctl, 'both connected')
        unrelated = [r for r in rows if str(port) not in r['to'] and r['comment'] != DENY_COMMENT]
        _, base_rows = None, [UFW_ROW.match(line.strip()) for line in baseline['ufw_numbered'].splitlines()]
        base_set = sorted((m[2].strip(), m[3], m[4].strip(), (m[5] or '').strip()) for m in base_rows if m)
        now_set = sorted((r['to'], r['action'], r['from'], r['comment']) for r in unrelated)
        check('unrelated UFW entries unchanged', now_set == base_set, {'before': base_set, 'now': now_set}, 'same unrelated entries as the baseline')
        doctor = cli('doctor-after', 'doctor', '--node=' + str(dev['node_id'])) if negative else doctor_after_report('doctor-after', dev['node_id'])
        summary = (doctor['json'] or {}).get('summary') or {}
        check('doctor --node reports drift 0', doctor['rc'] == 0 and summary.get('drift') == 0, {'rc': doctor['rc'], 'summary': summary}, 'drift 0')
        log = sh(dev['instance'], f'cat /tmp/proof-listen-{port}.log').stdout.split()
        phase['listener_peers_seen'] = sorted(set(log[1::2]))
        timed('assertions', t0)
    except Unrun as error:
        phase['status'] = 'unrun'
        phase['unrun_reason'] = str(error)
    except Exception as error:  # a harness error is not a pass
        phase['status'] = 'error'
        phase['error'] = f'{type(error).__name__}: {error}'
    finally:
        t0 = now()
        c = phase['cleanup']
        try:
            if state['allow_started']:
                removal = cli('remove' if not negative else 'remove-negative', 'firewall:remove', str(phase.get('port')), '--node=' + str(dev['node_id']),
                              '--from=' + str(gw_src), '--protocol=tcp', '--yes')
                rj = removal['json'] or {}
                c['firewall_remove'] = {'rc': removal['rc'], 'operation': rj.get('id'), 'status': rj.get('status'), 'error_code': rj.get('error_code')}
            if state['deny_inserted']:
                for _ in range(6):
                    _, rows = ufw_numbered(dev)
                    left = sorted((r['n'] for r in rows if r['comment'] == DENY_COMMENT), reverse=True)
                    if not left:
                        break
                    sh(dev['instance'], f'ufw --force delete {left[0]}')
            for p, pid in state['listeners']:
                # The bracket keeps pkill from matching its own shell; rm runs in a separate call.
                sh(dev['instance'], f'kill {pid} 2>/dev/null; pkill -f "[p]roof-listen.py {p} " 2>/dev/null; true')
                sh(dev['instance'], f'rm -f /tmp/proof-listen-{p}.log')
            if state['listeners']:
                sh(dev['instance'], 'rm -f /tmp/proof-listen.py')
            if baseline:
                after = ufw_plain(dev)
                final = cli('firewall-list-final' + ('-neg' if negative else ''), 'firewall:list', '--node=' + str(dev['node_id']))
                listeners = sh(dev['instance'], 'ss -Htln').stdout
                c['ufw_back_to_baseline'] = after == baseline['ufw_plain']
                c['rules_back_to_baseline'] = (final['json'] or {}).get('rules') == baseline['rules']
                c['listeners_gone'] = all(f':{p} ' not in listeners for p, _ in state['listeners'])
                if not c['ufw_back_to_baseline']:
                    c['ufw_after'] = after
                if not c['rules_back_to_baseline']:
                    c['rules_after'] = (final['json'] or {}).get('rules')
                c['clean'] = all(c.get(k) for k in ('ufw_back_to_baseline', 'rules_back_to_baseline', 'listeners_gone')) and \
                    (not state['allow_started'] or c.get('firewall_remove', {}).get('rc') == 0)
            else:
                c['clean'] = not (state['deny_inserted'] or state['allow_started'])
        except BaseException as error:
            c['clean'] = False
            c['error'] = f'{type(error).__name__}: {error}'
        timed('cleanup', t0)
    return phase


def finish(topology, phases, exit_reason=None):
    pos = phases[0] if phases else None
    neg = phases[1] if len(phases) > 1 else None
    commit = subprocess.run(['git', '-C', str(SOURCE), 'rev-parse', 'HEAD', 'HEAD^{tree}'], capture_output=True, text=True).stdout.split()
    dirty = bool(subprocess.run(['git', '-C', str(SOURCE), 'status', '--porcelain', '--', 'swamp', 'apps', 'packages'], capture_output=True, text=True).stdout.strip())
    if pos is None or pos['status'] in ('unrun',):
        verdict = 'UNRUN'
    elif pos['status'] == 'error':
        verdict = 'FAIL'
    else:
        verdict = 'PASS' if all(a['passed'] for a in pos['assertions']) and pos['cleanup'].get('clean') else 'FAIL'
    negative = {'confirmed': False, 'reason': 'not run'}
    if neg:
        failed = [a for a in neg['assertions'] if not a['passed']]
        traffic = [a for a in failed if a['name'] == 'gateway source connects to the test port']
        negative = {'confirmed': bool(traffic) and neg['status'] == 'ran', 'failed_assertions': [a['name'] for a in failed],
                    'reason': 'the traffic assertion failed and named itself' if traffic else (neg.get('unrun_reason') or neg.get('error') or 'traffic assertion did not fail'),
                    'cleanup_clean': neg['cleanup'].get('clean')}
    receipt = {'case': CASE, 'verdict': verdict, 'negative_check': negative, 'commit': commit[0] if commit else None,
               'tree': commit[1] if len(commit) > 1 else None, 'source_dirty': dirty, 'topology_label': os.environ.get('PROOF_TOPOLOGY', 'ready3'),
               'generation': os.environ.get('PROOF_GENERATION'), 'topology': topology, 'cli': '/usr/local/bin/orbit-nested', 'phases': phases,
               'started_utc': UTC, 'total_secs': round(now() - STARTED, 1), 'exit_reason': exit_reason}
    (RECEIPT / 'receipt.json').write_text(json.dumps(receipt, indent=2, default=str))
    lines = [f'{CASE} {verdict}  receipt={RECEIPT}']
    for ph in phases:
        lines.append(f"[{ph['name']}] status={ph['status']} clean={ph['cleanup'].get('clean')} op={ph.get('operation', {}).get('id')}")
        for a in ph['assertions']:
            lines.append(f"  {'PASS' if a['passed'] else 'FAIL'}  {a['name']}")
        if ph.get('unrun_reason'):
            lines.append('  UNRUN: ' + ph['unrun_reason'])
        if ph.get('error'):
            lines.append('  ERROR: ' + ph['error'])
    lines.append('negative_check: ' + json.dumps(negative))
    (RECEIPT / 'summary.txt').write_text('\n'.join(lines) + '\n')
    print('\n'.join(lines))
    if verdict == 'UNRUN':
        return 2
    return 0 if verdict == 'PASS' and negative['confirmed'] and negative.get('cleanup_clean') else 1


def interrupted(signum, frame):
    raise SystemExit(128 + signum)


for sig in (signal.SIGTERM, signal.SIGHUP, signal.SIGINT):
    signal.signal(sig, interrupted)

phases = []
topology = {}
try:
    topology = discover()
    phases.append(run_phase(topology, False))
    if phases[0]['status'] == 'ran':
        phases.append(run_phase(topology, True))
    code = finish(topology, phases)
except Unrun as error:
    print('UNRUN:', error)
    (RECEIPT / 'receipt.json').write_text(json.dumps({'case': CASE, 'verdict': 'UNRUN', 'reason': str(error)}))
    code = 2
sys.exit(code)
