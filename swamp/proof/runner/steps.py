"""The steps of a case. Each step function takes the Run and its resolved arguments and returns a list of failures.

A step that cannot run raises `Infra`. A failure of a `setup` step makes the case `unrun`; in `act` and `expect` it fails the case.
"""
import json
import random
import re
import time
from pathlib import Path

import spec

LISTENER = (Path(__file__).resolve().parent / 'listener.py').read_text()
UFW_ROW = re.compile(r'^\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)(?: IN| OUT| FWD)?\s{2,}(.+?)(?:\s+#\s*(.*))?$')
PROBE_SECONDS = 3
# The agent reports the firewall at a host tick every 60 s, in a phase the runner cannot know after a restore of the base snapshot.
# A report that follows the change needs at most one tick plus the time to deliver it: wait until this long after `act`.
REPORT_SECONDS = 65
LIST_ROWS = {'firewall:list': 'rules'}
BASELINE_PROVIDERS = ('ufw', 'firewall_records', 'operations', 'listeners')


class Infra(Exception):
    """A step could not run: the case fails with cause infrastructure."""


class NotBuilt(Infra):
    pass


class Unrun(Exception):
    """A precondition did not hold: the case is unrun."""


def ufw_rows(run, node):
    text = run.fleet.sh(node, 'ufw status numbered').stdout
    rows = []
    for line in text.splitlines():
        match = UFW_ROW.match(line.strip())
        if match:
            rows.append({'n': int(match[1]), 'to': match[2].strip(), 'action': match[3], 'from': match[4].strip(), 'comment': (match[5] or '').strip()})
    return text, rows


def family_rows(rows, family):
    """Rows of one family; the rows of IPv6 carry the `(v6)` mark. Without a family all rows stay."""
    if family is None:
        return rows
    marked = [row for row in rows if '(v6)' in row['to'] or '(v6)' in row['from']]
    return marked if int(family) == 6 else [row for row in rows if row not in marked]


def tcp_ipv4(args, step):
    protos = args.get('proto', 'tcp')
    protos = protos if isinstance(protos, list) else [protos]
    if set(protos) != {'tcp'} or int(args.get('family', 4)) != 4:
        raise NotBuilt(f'{step}: only TCP over IPv4 is built')


def listener(run, args):
    tcp_ipv4(args, 'listener')
    if args.get('block'):
        raise NotBuilt('listener block is not built')
    node = args['on']
    for name in args.get('ports') or []:
        port = free_port(run, node)
        run.fleet.sh(node, 'cat > /tmp/proof-listen.py', stdin_text=LISTENER)
        log = f'/tmp/proof-listen-{port}.log'
        # The listener detaches only after it listens, and prints its pid: no wait is needed.
        started = run.fleet.sh(node, f'rm -f {log}; python3 /tmp/proof-listen.py {port} {log}')
        if started.returncode:
            raise Infra(f'listener on {node} did not start: {started.stderr.strip()[-200:]}')
        local = run.fleet.sh(node, f"timeout 3 bash -c 'echo > /dev/tcp/127.0.0.1/{port}'").returncode
        if local:
            return [f'listener {name} on {node}:{port} does not accept connections locally']
        run.names[name] = str(port)
    return []


def free_port(run, node):
    for _ in range(50):
        port = random.randint(47000, 47900)
        used = run.fleet.sh(node, 'ss -Htln').stdout
        if f':{port} ' not in used and f':{port + 1} ' not in used and str(port) not in run.names.values():
            return port
    raise Infra(f'no free test port on {node}')


def ufw_rule(run, args):
    tcp_ipv4(args, 'ufw_rule')
    node, port, proto = args['on'], args['port'], args['proto']
    name = args.get('as') or 'rule'
    comment = 'proof:' + name
    source = args.get('from', 'any')
    anchor = args.get('above') or args.get('below')
    mark = (run.rules[anchor]['comment'] if anchor in run.rules else anchor) if anchor else None
    position = None
    if anchor:
        found = [row['n'] for row in family_rows(ufw_rows(run, node)[1], 4) if row['comment'] == mark]
        if not found:
            raise Unrun(f'ufw_rule {name}: no row to place it relative to: {anchor}')
        position = found[0] if args.get('above') else found[0] + 1
    words = f"{args['action']} proto {proto} from {source} to any port {port} comment '{comment}'"
    inserted = run.fleet.sh(node, f'ufw insert {position} {words}' if position else f'ufw {words}')
    if inserted.returncode:
        return [f'ufw_rule {name} failed: {(inserted.stdout + inserted.stderr).strip()[-200:]}']
    run.rules[name] = {'to': f'{port}/{proto}', 'action': args['action'].upper(), 'from': 'Anywhere' if source == 'any' else source, 'comment': comment}
    rows = family_rows(ufw_rows(run, node)[1], 4)
    mine = [row['n'] for row in rows if row['comment'] == comment]
    if len(mine) != 1:
        return [f'ufw_rule {name}: expected one row with the comment, found {len(mine)}']
    if anchor:
        other = [row['n'] for row in rows if row['comment'] == mark]
        if not other or (mine[0] > other[0]) == bool(args.get('above')):
            return [f'ufw_rule {name} is not {"above" if args.get("above") else "below"} {anchor}']
    return []


def probe_result(run, source, target, port):
    address = run.fleet.nodes[target]['bridge']
    result = run.fleet.sh(source, f"timeout {PROBE_SECONDS} bash -c 'echo > /dev/tcp/{address}/{port}'", timeout=PROBE_SECONDS + 20)
    if result.returncode == 0:
        return 'connected'
    return 'blocked' if result.returncode == 124 else 'refused'


def probe(run, args):
    tcp_ipv4(args, 'probe')
    observed = probe_result(run, args['from'], args['to'], args['port'])
    expected = {'connect': 'connected', 'blocked': 'blocked'}.get(args['expect'])
    if expected is None:
        raise Infra(f"probe expect is connect or blocked, not {args['expect']}")
    if observed == expected:
        return []
    return [f"probe{' ' + args['id'] if args.get('id') else ''}: {args['from']} to {args['port']}/{args.get('proto', 'tcp')} {observed}, expected {args['expect']}"]


def cli(run, args):
    if args.get('forms') or 'tty' in args:
        raise NotBuilt('cli forms and tty are not built')
    expect = args.get('expect') or {}
    unsupported = set(expect) - {'exit', 'status', 'code'}
    if unsupported:
        raise NotBuilt(f'cli expect {sorted(unsupported)} is not built')
    result = run.cli([str(item) for item in args['run']])
    failures = []
    label = 'cli ' + ' '.join(args['run'][:1])
    if 'exit' in expect and result['rc'] != expect['exit']:
        failures.append(f"{label}: exit {result['rc']}, expected {expect['exit']}")
    document = result['json'] if isinstance(result['json'], dict) else {}
    if 'status' in expect and document.get('status') != expect['status']:
        failures.append(f"{label}: status {document.get('status')}, expected {expect['status']}" + ('' if document else ' (no JSON)'))
    if 'code' in expect:
        code = document.get('error_code') or (document.get('error') or {}).get('code')
        if code != expect['code']:
            failures.append(f"{label}: code {code}, expected {expect['code']}")
    return failures


def matches(row, wanted):
    return all(str(row.get(key)) == str(value) for key, value in wanted.items())


def ufw(run, args):
    node = args['on']
    text, rows = ufw_rows(run, node)
    rows = family_rows(rows, args.get('family'))
    run.evidence_text(f'ufw-{node}-{int(time.time())}.txt', text)

    def wanted(item):
        if 'ref' in item:
            reference = run.rules.get(item['ref'])
            if reference is None:
                raise Infra(f"ufw ref {item['ref']} was not bound by a ufw_rule")
            return {key: reference[key] for key in ('to', 'action', 'from', 'comment')}
        return {key: item[key] for key in ('to', 'action', 'from', 'comment') if key in item}

    failures, last = [], 0
    for item in args['rows']:
        found = [row for row in rows if matches(row, wanted(item))]
        label = item.get('ref') or wanted(item)
        if len(found) != 1:
            failures.append(f'ufw {node}: {label} appears {len(found)} times, expected 1')
        elif found[0]['n'] < last:
            failures.append(f'ufw {node}: {label} is out of order (row {found[0]["n"]})')
        else:
            last = found[0]['n']
    for item in args.get('absent') or []:
        if any(matches(row, wanted(item)) for row in rows):
            failures.append(f'ufw {node}: {item.get("ref") or wanted(item)} is present, expected absent')
    return failures


def record_rows(run, command, node):
    key = LIST_ROWS.get(command)
    if key is None:
        raise NotBuilt(f'record of {command} is not built')
    result = run.cli([command, '--node=' + str(run.fleet.nodes[node]['node_id'])])
    if result['rc'] or not isinstance(result['json'], dict):
        raise Infra(f"{command} failed: exit {result['rc']}")
    return result['json'].get(key) or []


def record(run, args):
    rows = record_rows(run, args['list'], args['node'])
    found = [row for row in rows if matches(row, args['where'])]
    if len(found) == args['count']:
        return []
    return [f"record {args['list']} {args['node']}: {len(found)} rows match {json.dumps(args['where'], sort_keys=True)}, expected {args['count']}"]


def doctor(run, args):
    node = args['node']
    run.sleep(run.acted + REPORT_SECONDS - time.time())
    result = run.cli(['doctor', '--node=' + str(run.fleet.nodes[node]['node_id'])])
    summary = (result['json'] or {}).get('summary') or {}
    if result['rc'] == 0 and summary.get('drift') == args['drift']:
        return []
    return [f"doctor {node}: exit {result['rc']}, drift {summary.get('drift')}, expected {args['drift']}"]


def unchanged_providers(step_args, file_baseline):
    names = step_args.get('what') or [name for name in file_baseline if name != 'operations']
    unknown = [name for name in names if name not in BASELINE_PROVIDERS]
    if unknown:
        raise Infra(f'unknown baseline provider {unknown}')
    return list(names)


def snapshot(run, provider):
    """The state of one provider as a value that compares equal when nothing changed, with the port of each entry for `except`."""
    node = run.spec.target
    if provider == 'ufw':
        return [(row['to'], row['action'], row['from'], row['comment']) for row in ufw_rows(run, node)[1]]
    if provider == 'firewall_records':
        return [json.dumps(row, sort_keys=True) for row in record_rows(run, 'firewall:list', node)]
    if provider == 'listeners':
        return [line.split()[3] for line in run.fleet.sh(node, 'ss -Htln').stdout.splitlines() if len(line.split()) > 3]
    result = run.cli(['operation:list'])
    return [str(item.get('id')) for item in (result['json'] or {}).get('operations', [])]


def without_port(provider, entries, port):
    if port is None:
        return sorted(entries)
    if provider == 'ufw':
        return sorted(entry for entry in entries if not re.match(rf'{port}(/|:|$)', entry[0]))
    if provider == 'firewall_records':
        return sorted(entry for entry in entries if str(json.loads(entry).get('port')) != str(port))
    if provider == 'listeners':
        return sorted(entry for entry in entries if not entry.endswith(f':{port}'))
    return sorted(entries)


def unchanged(run, args):
    if args['since'] != 'act':
        raise NotBuilt('unchanged supports since: act only')
    port = (args.get('except') or {}).get('port')
    failures = []
    for provider in unchanged_providers(args, run.spec.baseline):
        before = without_port(provider, run.baseline[provider], port)
        after = without_port(provider, snapshot(run, provider), port)
        if before != after:
            added = [str(item) for item in after if item not in before]
            removed = [str(item) for item in before if item not in after]
            failures.append(f'unchanged {provider}: added {added[:3]}, removed {removed[:3]}')
    return failures


def take_baseline(run, steps):
    """Record the providers that the case's `unchanged` steps compare, at the start of `act`."""
    run.baseline = {}
    for step in steps:
        if step.name == 'unchanged':
            for provider in unchanged_providers(step.args, run.spec.baseline):
                if provider not in run.baseline:
                    run.baseline[provider] = snapshot(run, provider)


def ufw_delete(run, args):
    """Delete the rows of the target that match `to` and `from`, to break an effect on purpose."""
    node = args['on']
    wanted = {'to': args['to'], **({'from': args['from']} if 'from' in args else {})}
    found = [row['n'] for row in family_rows(ufw_rows(run, node)[1], 4) if matches(row, wanted)]
    if not found:
        raise Infra(f'ufw_delete: no row matches {wanted}')
    for number in sorted(found, reverse=True):
        deleted = run.fleet.sh(node, f'ufw --force delete {number}')
        if deleted.returncode:
            raise Infra(f'ufw_delete: ufw failed: {deleted.stderr.strip()[-200:]}')
    return []


def unbuilt(name):
    def step(run, args):
        raise NotBuilt(f'step {name} is not built')
    return step


FUNCTIONS = {name: unbuilt(name) for name in spec.STEPS}
FUNCTIONS.update({'listener': listener, 'ufw_rule': ufw_rule, 'probe': probe, 'cli': cli, 'ufw': ufw, 'record': record, 'doctor': doctor,
                  'unchanged': unchanged, 'ufw_delete': ufw_delete})
