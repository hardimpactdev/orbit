"""Domain fixtures and checks ported from runner/steps.py. No runner dependency.

The steps of a case. Each step function takes the Run and its resolved arguments and returns a list of failures.

A step that cannot run raises `Infra`. A failure of a `setup` step makes the case `unrun`; in `act` and `expect` it fails the case.
"""
import json
import random
import re
import shlex
import sys
import time
from pathlib import Path

import fleet as fleet_module

# The prompt driver and the ufw shim loop by nature, so they live with the guest programs, outside the Swamp-shape
# rules for step code.
sys.path.insert(0, str(Path(__file__).resolve().parents[3] / 'tools/swamp-proof-guest'))
import terminal  # noqa: E402

LISTENER = (Path(__file__).resolve().parent / 'listener.py').read_text()
PROBE = (Path(__file__).resolve().parent / 'probe.py').read_text()
UFW_SHIM = (Path(__file__).resolve().parents[3] / 'tools/swamp-proof-guest/ufw-shim.sh').read_text()
UFW_ROW = re.compile(r'^\[\s*(\d+)\]\s+(.+?)\s{2,}(ALLOW|DENY|REJECT|LIMIT)(?: IN| OUT| FWD)?\s{2,}(.+?)(?:\s+#\s*(.*))?$')
PROBE_SECONDS = 3
SHIM_DIR = '/var/tmp/proof-ufw'
IPV6_PREFIX = 'fd42:232:2::'   # a ULA /64 for the proof bridge; the host part is the last octet of the IPv4 address
BUSY_SECONDS = 120
CLEANUP_SECONDS = 60
# The agent reports the firewall at a host tick every 60 s, in a phase the runner cannot know after a restore of the base snapshot.
# A report that follows the change needs at most one tick plus the time to deliver it: wait until this long after `act`.
REPORT_SECONDS = 65
# The Gateway counts an agent as offline after 15 s without an event.
OFFLINE_SECONDS = 20
CLI_EXPECT = {'exit', 'status', 'code', 'skipped', 'request', 'human', 'steps'}
LIST_ROWS = {'firewall:list': 'rules'}
# The parts of a list answer that `record` reads, per Node: `rules` and `managed` are lists, `unmatched` is the list under `live`,
# and `live` is the one object that holds the state of the report.
RECORD_SECTIONS = ('rules', 'managed', 'unmatched', 'live')
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
    """Rows of one family; the rows of IPv6 carry the `(v6)` mark or an IPv6 source. Without a family all rows stay."""
    if family is None:
        return rows
    # ufw marks a line for anywhere with `(v6)`; a line from one IPv6 address shows the address alone.
    marked = [row for row in rows if '(v6)' in row['to'] or '(v6)' in row['from'] or ':' in row['from']]
    return marked if int(family) == 6 else [row for row in rows if row not in marked]


def protocols(args):
    found = args.get('proto', 'tcp')
    found = found if isinstance(found, list) else [found]
    unknown = [item for item in found if item not in ('tcp', 'udp')]
    if unknown:
        raise Infra(f'protocol must be tcp or udp, not {unknown}')
    return found


def family_of(args, step, allowed=(4, 6)):
    family = int(args.get('family', 4))
    if family not in allowed:
        raise NotBuilt(f'{step}: family {family} is not built')
    return family


def listener(run, args):
    node, family = args['on'], family_of(args, 'listener')
    block = args.get('block')
    size = int(block.get('size', 0)) if block else 0
    if block and not (block.get('as') and 2 <= size <= 50):
        raise Infra('listener block needs as and a size of 2 to 50')
    used = run.fleet.sh(node, 'ss -Htlun').stdout
    avoid = {int(value) for value in run.names.values() if str(value).isdigit()}
    ports = {}
    for name in args.get('ports') or []:
        ports[name] = free_run(used, avoid, 1)
        avoid.add(ports[name])
    numbers = list(ports.values())
    if block:
        low = free_run(used, avoid, size)
        numbers += range(low, low + size)
    for proto in protocols(args):
        log = f'/tmp/proof-listen-{numbers[0]}-{proto}.log'
        run.fleet.sh(node, 'cat > /tmp/proof-listen.py', stdin_text=LISTENER)
        # The listener detaches only after every port listens, and prints its pid: no wait is needed.
        started = run.fleet.sh(node, f"rm -f {log}; python3 /tmp/proof-listen.py {log} {proto} {family} {' '.join(map(str, numbers))}")
        if started.returncode:
            raise Infra(f'listener on {node} did not start: {started.stderr.strip()[-200:]}')
        refused = local_refusals(run, node, proto, family, numbers)
        if refused:
            return [f'listener on {node} does not accept {proto} connections locally on port {" ".join(refused)}']
    run.names.update({name: str(port) for name, port in ports.items()})
    if block:
        run.names.update({f"{block['as']}.lo": str(low), f"{block['as']}.mid": str(low + size // 2),
                          f"{block['as']}.hi": str(low + size - 1), f"{block['as']}.spec": f'{low}:{low + size - 1}'})
    return []


def free_run(used, avoid, count):
    """The first of `count` consecutive ports that nothing listens on (`used` is `ss` output) and the case does not hold."""
    for _ in range(50):
        low = random.randint(47000, 47900 - count)
        if not any(f':{port} ' in used or port in avoid for port in range(low, low + count)):
            return low
    raise Infra('no free test ports')


def local_refusals(run, node, proto, family, numbers):
    """The ports of a new listener that do not answer on the Node itself, found in one call."""
    address = '::1' if family == 6 else '127.0.0.1'
    check = f'python3 -c {shlex.quote(PROBE)} {address} "$port" {proto} {family} 3'
    sockets = set(run.fleet.hosts[node].socket.get_listening_sockets())
    bind = '::' if family == 6 else '0.0.0.0'
    missing = [str(port) for port in numbers if f'{proto}://{bind}:{port}' not in sockets]
    if missing:
        return missing
    return run.fleet.sh(node, f"for port in {' '.join(map(str, numbers))}; do {check} || echo $port; done").stdout.split()


def ufw_rule(run, args):
    family_of(args, 'ufw_rule', (4,))
    node, port, proto = args['on'], args['port'], protocols(args)[0]
    name = args.get('as') or 'rule'
    comment = args.get('comment') or 'proof:' + name
    if "'" in comment:
        raise Infra('ufw_rule comment holds no quote')
    source = args.get('from', 'any')
    anchor = args.get('above') or args.get('below')
    if anchor in run.rules and proto not in run.rules[anchor]:
        raise Unrun(f'ufw_rule {name}: anchor {anchor} has no {proto} rule')
    anchor_rule = run.rules[anchor][proto] if anchor in run.rules else {'comment': anchor}
    mark = anchor_rule['comment']
    position = None
    if anchor:
        found = [row['n'] for row in family_rows(ufw_rows(run, node)[1], 4) if matches(row, anchor_rule)]
        if not found:
            raise Unrun(f'ufw_rule {name}: no row to place it relative to: {anchor}')
        position = found[0] if args.get('above') else found[0] + 1
    words = f"{args['action']} proto {proto} from {source} to any port {port} comment '{comment}'"
    inserted = run.fleet.sh(node, f'ufw insert {position} {words}' if position else f'ufw {words}')
    if inserted.returncode:
        return [f'ufw_rule {name} failed: {(inserted.stdout + inserted.stderr).strip()[-200:]}']
    run.rules.setdefault(name, {})[proto] = {'to': f'{port}/{proto}', 'action': args['action'].upper(), 'from': 'Anywhere' if source == 'any' else source, 'comment': comment}
    rows = family_rows(ufw_rows(run, node)[1], 4)
    mine = [row['n'] for row in rows if row['comment'] == comment and row['to'] == f'{port}/{proto}']  # one name may stand for a row per protocol
    if len(mine) != 1:
        return [f'ufw_rule {name}: expected one row with the comment, found {len(mine)}']
    if anchor:
        other = [row['n'] for row in rows if matches(row, anchor_rule)]
        if not other or (mine[0] > other[0]) == bool(args.get('above')):
            return [f'ufw_rule {name} is not {"above" if args.get("above") else "below"} {anchor}']
        if args.get('above') and source == 'any':
            return place_v6_line(run, node, {**args, 'proto': proto, 'comment': comment}, mark)
    return []


def place_v6_line(run, node, rule, mark):
    """A rule for both families gets an IPv6 line, and ufw puts it at the end of the IPv6 rules, below the IPv6 allowance for the proof
    network. Move it above the IPv6 line of the anchor, as the IPv4 line is."""
    rows = ufw_rows(run, node)[1]
    v4 = family_rows(rows, 4)
    v6 = [row for row in rows if row not in v4]
    anchor = [row['n'] for row in v6 if row['comment'] == mark]
    mine = [row['n'] for row in v6 if row['comment'] == rule['comment'] and row['to'] == f"{rule['port']}/{rule['proto']} (v6)"]
    if not anchor or not mine or mine[0] < anchor[0]:
        return []
    deleted = run.fleet.sh(node, f'ufw --force delete {mine[0]}')
    words = f"{rule['action']} proto {rule['proto']} from ::/0 to ::/0 port {rule['port']} comment '{rule['comment']}'"
    inserted = run.fleet.sh(node, f'ufw insert {anchor[0]} {words}') if not deleted.returncode else deleted
    if inserted.returncode:
        return [f"ufw_rule {rule.get('as') or 'rule'}: the IPv6 line did not move above {mark}: {(inserted.stdout + inserted.stderr).strip()[-200:]}"]
    return []


def probe_result(run, source, target, args):
    family, proto = family_of(args, 'probe'), protocols(args)[0]
    address = run.fleet.nodes[target]['bridge6' if family == 6 else 'bridge']
    if proto == 'tcp' and family == 4:
        result = run.fleet.sh(source, f"timeout {PROBE_SECONDS} bash -c 'echo > /dev/tcp/{address}/{args['port']}'", timeout=PROBE_SECONDS + 20)
    else:
        result = run.fleet.sh(source, f"python3 -c {shlex.quote(PROBE)} {address} {args['port']} {proto} {family} {PROBE_SECONDS}", timeout=PROBE_SECONDS + 20)
    if result.returncode == 0:
        return 'connected'
    return 'blocked' if result.returncode == 124 else 'refused'


def probe(run, args):
    family = family_of(args, 'probe')
    if family == 6 and 'bridge6' not in run.fleet.nodes[args['to']]:
        raise Infra(f"probe over IPv6: {args['to']} has no IPv6 address; the batch needs ipv6_addresses")
    observed = probe_result(run, args['from'], args['to'], args)
    expected = {'connect': 'connected', 'blocked': 'blocked'}.get(args['expect'])
    if expected is None:
        raise Infra(f"probe expect is connect or blocked, not {args['expect']}")
    if observed == expected:
        return []
    return [f"probe{' ' + args['id'] if args.get('id') else ''}: {args['from']} to {args['port']}/{args.get('proto', 'tcp')}{' (v6)' if family == 6 else ''} {observed}, expected {args['expect']}"]


def operation_ids(run):
    return {item.get('id') for item in (run.cli(['operation:list'])['json'] or {}).get('operations', [])}


def skip_reasons(node):
    """The reason codes a JSON document gives under a key that names a skip, such as `skipped`."""
    if isinstance(node, dict):
        for key, value in node.items():
            if 'skip' in key.lower():
                yield from (item for item in (value if isinstance(value, list) else [value]) if isinstance(item, str))
            yield from skip_reasons(value)
    elif isinstance(node, list):
        for item in node:
            yield from skip_reasons(item)


def cli(run, args):
    expect = args.get('expect') or {}
    unknown = set(expect) - CLI_EXPECT
    if unknown:
        raise Infra(f'cli expect {sorted(unknown)} is unknown')
    script = args.get('tty')
    if script is not None and args.get('forms'):
        raise Infra('cli tty runs in the human form: do not set forms')
    forms = ['human'] if script is not None else args.get('forms') or ['json']
    if not set(forms) <= {'json', 'human'}:
        raise Infra(f'cli forms are json and human, not {forms}')
    if expect.get('request', 'none') != 'none':
        raise Infra('cli expect request is none')
    if 'skipped' in expect and 'json' not in forms:
        raise Infra('cli expect skipped is read from the JSON form: add json to forms')
    if 'steps' in expect:
        wanted = expect['steps']
        if not isinstance(wanted, dict) or set(wanted) != {'name', 'count'} or not isinstance(wanted['name'], str) or not wanted['name'].strip() or type(wanted['count']) is not int or wanted['count'] < 0:
            raise Infra('cli expect steps needs a name and a nonnegative integer count')
        if 'json' not in forms:
            raise Infra('cli expect steps is read from the JSON form: add json to forms')
    argv = [str(item) for item in args['run']]
    # The default is the candidate CLI of the outer VM, which acts for the fleet as its operator; `gateway` is that same caller.
    caller = args.get('as')
    caller = None if caller in (None, 'gateway') else caller
    if caller and caller not in run.fleet.nodes:
        raise Infra(f'cli as {caller}: not a Node of the fleet')
    if caller and script is not None:
        raise NotBuilt('cli as with tty is not built')
    before = operation_ids(run) if 'request' in expect else None
    failures = []
    for form in forms:
        label = f'cli {argv[0]}' + ('' if len(forms) == 1 and script is None else f' ({"tty" if script is not None else form})')
        try:
            result = run.cli_tty(argv, script) if script is not None else run.cli(argv, form, caller)
        except terminal.TerminalError as error:  # the command did not prompt as the case expects: a finding, not a broken runner
            failures.append(f'{label}: {error}')
            continue
        except fleet_module.FleetError as error:  # the tested CLI could not be made ready on the Node: the fleet, not the command
            raise Infra(str(error)) from error
        failures.extend(check_result(run, label, form, forms, result, expect))
    if before is not None and (created := operation_ids(run) - before):
        failures.append(f'cli {argv[0]}: created Operation {sorted(created)}, expected no request')
    return failures


def check_result(run, label, form, forms, result, expect):
    failures = []
    if 'exit' in expect and result['rc'] != expect['exit']:
        failures.append(f"{label}: exit {result['rc']}, expected {expect['exit']}")
    if form == 'json':
        document = result['json'] if isinstance(result['json'], dict) else {}
        if 'status' in expect and document.get('status') != expect['status']:
            failures.append(f"{label}: status {document.get('status')}, expected {expect['status']}" + ('' if document else ' (no JSON)'))
        if 'code' in expect:
            code = document.get('error_code') or (document.get('error') or {}).get('code')
            if code != expect['code']:
                failures.append(f"{label}: code {code}, expected {expect['code']}")
        if 'skipped' in expect and expect['skipped'] not in skip_reasons(document):
            failures.append(f"{label}: not skipped with {expect['skipped']}")
        if 'steps' in expect:
            failures.extend(check_operation_steps(run, label, document, expect['steps']))
        return failures
    # The human form has no JSON: the code and the status are read from the JSON form when the call has one.
    text = terminal.plain(result['stdout'] + result['stderr'])
    for wanted in [expect['human']] if isinstance(expect.get('human'), str) else expect.get('human') or []:
        if wanted not in text:
            failures.append(f'{label}: the output lacks {wanted!r}')
    if 'json' not in forms:
        if 'code' in expect and expect['code'] not in text:
            failures.append(f"{label}: the output lacks the code {expect['code']}")
        if 'status' in expect:
            found = re.search(r'Operation:\s*(\d+)', text)
            status = (run.cli(['operation:show', found[1]])['json'] or {}).get('status') if found else None
            if status != expect['status']:
                failures.append(f"{label}: status {status}, expected {expect['status']}" + ('' if found else ' (no Operation in the output)'))
    return failures


def check_operation_steps(run, label, document, wanted):
    operation_id = document.get('id')
    if not operation_id:
        return [f'{label}: no Operation ID for steps']
    result = run.cli(['operation:show', str(operation_id)])
    if result['rc'] != 0:
        return [f'{label}: could not read Operation steps']
    rows = (result['json'] or {}).get('steps')
    if not isinstance(rows, list) or any(not isinstance(row, dict) or not isinstance(row.get('name'), str) or not isinstance(row.get('status'), str) for row in rows):
        return [f'{label}: Operation step data is missing or invalid']
    pattern = re.compile(re.escape(wanted['name']) + r'(?:-([0-9]+)-\1)?')
    count = sum(bool(pattern.fullmatch(row['name'])) and row['status'] in ('running', 'succeeded', 'failed') for row in rows)
    if count != wanted['count']:
        return [f"{label}: ran {count} steps named {wanted['name']}, expected {wanted['count']}"]
    return []


def matches(row, wanted):
    """Every key of `wanted` equals the row's value; a list as the wanted value matches any of its items. The word `present` matches
    any value that is not null."""
    return all(row.get(key) is not None if value == 'present' else str(row.get(key)) in (map(str, value) if isinstance(value, list) else [str(value)])
               for key, value in wanted.items())


def ufw(run, args):
    node = args['on']
    text, rows = ufw_rows(run, node)
    rows = family_rows(rows, args.get('family'))
    run.evidence_text(f'ufw-{node}-{int(time.time())}.txt', text)
    for family in [args['family']] if args.get('family') else [4, 6]:
        kernel = run.fleet.hosts[node].iptables.rules(version=int(family))
        run.evidence_text(f'iptables-{node}-v{family}.txt', '\n'.join(kernel))

    def wanted(item):
        if 'ref' in item:
            bindings = run.rules.get(item['ref'])
            if bindings is None:
                raise Infra(f"ufw ref {item['ref']} was not bound by a ufw_rule")
            reference = bindings[next(reversed(bindings))]
            found = {key: reference[key] for key in ('to', 'action', 'from', 'comment')}
            if args.get('family') is not None and int(args['family']) == 6:  # the IPv6 line of a rule for both families
                found.update(to=found['to'] + ' (v6)', **{'from': found['from'] + ' (v6)'} if found['from'] == 'Anywhere' else {})
            return found
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


def record_rows(run, command, node=None, options=(), section='rules'):
    """The rows of one part of the answer of a list command, each with the name of its Node. Without `node` the list covers every Node."""
    key = LIST_ROWS.get(command)
    if key is None:
        raise NotBuilt(f'record of {command} is not built')
    if section not in RECORD_SECTIONS:
        raise Infra(f'record section is one of {RECORD_SECTIONS}, not {section}')
    words = [command, *map(str, options)] + (['--node=' + str(run.fleet.nodes[node]['node_id'])] if node else [])
    result = run.cli(words)
    if result['rc'] or not isinstance(result['json'], dict):
        raise Infra(f"{command} failed: exit {result['rc']}")
    # `firewall:list` answers per Node: {"nodes": [{"node_id": 1, "node": "app-dev", "rules": [...], "live": {..., "unmatched": [...]}}]}.
    rows = []
    for item in result['json'].get('nodes') or []:
        live = item.get('live') or {}
        part = {'rules': item.get(key), 'managed': item.get('managed'), 'unmatched': live.get('unmatched'), 'live': [live] if live else []}[section]
        rows.extend({'node': item.get('node'), **row} for row in part or [])
    return rows


def in_order(label, rows, wanted):
    """Each wanted row has a match, and the first match of each comes after the match of the one before it."""
    failures, last = [], -1
    for item in wanted:
        places = [index for index, row in enumerate(rows) if matches(row, item)]
        if not places:
            failures.append(f'{label}: no row matches {json.dumps(item, sort_keys=True)}')
        elif later := [index for index in places if index > last]:
            last = later[0]
        else:
            failures.append(f'{label}: {json.dumps(item, sort_keys=True)} comes before a row that must come first')
    return failures


def record(run, args):
    if not {'count', 'min', 'order'} & set(args):
        raise Infra('record needs count, min or order')
    node, section, where = args.get('node'), args.get('section', 'rules'), args.get('where') or {}
    found = [row for row in record_rows(run, args['list'], node, args.get('args') or [], section) if matches(row, where)]
    label = f"record {args['list']} {node or 'all Nodes'} {section}"
    failures = []
    if 'count' in args and len(found) != args['count']:
        failures.append(f"{label}: {len(found)} rows match {json.dumps(where, sort_keys=True)}, expected {args['count']}")
    if 'min' in args and len(found) < args['min']:
        failures.append(f"{label}: {len(found)} rows match {json.dumps(where, sort_keys=True)}, expected at least {args['min']}")
    return failures + (in_order(label, found, args['order']) if 'order' in args else [])


def doctor(run, args):
    node = args['node']
    run.sleep(run.acted + REPORT_SECONDS - time.time())
    result = run.cli(['doctor', '--node=' + str(run.fleet.nodes[node]['node_id'])])
    summary = (result['json'] or {}).get('summary') or {}
    if result['rc'] == 0 and summary.get('drift') == args['drift']:
        return []
    return [f"doctor {node}: exit {result['rc']}, drift {summary.get('drift')}, expected {args['drift']}"]


def nodes_of(run, step, value):
    """The Nodes a step names, one name or a list, checked against the fleet."""
    names = value if isinstance(value, list) else [value]
    unknown = [name for name in names if name not in run.fleet.nodes]
    if unknown:
        raise Infra(f'{step}: not Nodes of the fleet: {unknown}')
    return names


def agent_report(run, args):
    """Wait for one report of every agent after the change the case made. The agents report at a tick of 60 s whose phase is not
    known, so the wait is the same as the one of `doctor`; it is one call, whatever the number of Nodes."""
    nodes_of(run, 'agent_report', args['node'])
    run.sleep(REPORT_SECONDS)
    return []


def node_down(run, args):
    """Stop the agent and SSH of a Node, then wait until the Gateway counts the agent as offline. The restore of `base` brings the Node back."""
    node = nodes_of(run, 'node_down', args['node'])
    if len(node) != 1:
        raise Infra('node_down stops one Node')
    stopped = run.fleet.sh(node[0], 'systemctl stop orbit-agent ssh.socket ssh.service')
    if stopped.returncode:
        raise Infra(f'node_down {node[0]}: {stopped.stderr.strip()[-200:]}')
    run.sleep(OFFLINE_SECONDS)
    return []


def node_access_remove(run, args):
    """Remove the access of the consumer Node to the serving Node, with the CLI of the operator."""
    consumer = nodes_of(run, 'node_access_remove', args['consumer'])[0]
    serving = nodes_of(run, 'node_access_remove', args['serving'])[0]
    result = run.cli(['node:access:remove', str(run.fleet.nodes[consumer]['node_id']), str(run.fleet.nodes[serving]['node_id']), '--force'])
    if result['rc']:
        return [f"node_access_remove {args['consumer']} {args['serving']}: exit {result['rc']}"]
    return []


def unchanged_providers(step_args, file_baseline):
    names = step_args.get('what') or [name for name in file_baseline if name != 'operations']
    unknown = [name for name in names if name not in BASELINE_PROVIDERS]
    if unknown:
        raise Infra(f'unknown baseline provider {unknown}')
    return list(names)


def snapshot(run, provider):
    """The state of one provider as a value that compares equal when nothing changed, with the port of each entry for `except`."""
    node = run.target
    if provider == 'ufw':
        return [(row['to'], row['action'], row['from'], row['comment']) for row in ufw_rows(run, node)[1]]
    if provider == 'firewall_records':
        return [json.dumps(row, sort_keys=True) for row in record_rows(run, 'firewall:list', node)]
    if provider == 'listeners':
        return [line.split()[-2] for line in run.fleet.sh(node, 'ss -Htlun').stdout.splitlines() if len(line.split()) > 3]
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


def ipv6_addresses(run, args):
    """Give each Node an IPv6 address on the interface of its bridge address, then check that the Nodes reach each other."""
    nodes = args['nodes']
    unknown = [node for node in nodes if node not in run.fleet.nodes]
    if unknown:
        raise Infra(f'ipv6_addresses: unknown Nodes {unknown}')
    for node in nodes:
        bridge = run.fleet.nodes[node]['bridge']
        address = IPV6_PREFIX + bridge.rsplit('.', 1)[1]
        added = run.fleet.sh(node, f"ip -6 addr replace {address}/64 dev $(ip -4 -o addr show | awk '/ {bridge}\\//{{print $2}}') nodad")
        if added.returncode:
            return [f'ipv6_addresses {node}: {added.stderr.strip()[-200:]}']
        run.fleet.nodes[node]['bridge6'] = address
        # The IPv4 allowance for the proof network has no IPv6 twin: the default policy would block the control port.
        allowed = run.fleet.sh(node, f"ufw allow from {IPV6_PREFIX}/64 comment 'proof-network'")
        if allowed.returncode:
            return [f'ipv6_addresses {node}: ufw: {(allowed.stdout + allowed.stderr).strip()[-200:]}']
    failures = []
    for node in nodes:
        for other in nodes:
            if other != node and run.fleet.sh(node, f"ping -6 -c 1 -W 3 {run.fleet.nodes[other]['bridge6']}").returncode:
                failures.append(f'ipv6_addresses: {node} does not reach {other} over IPv6')
    return failures


def install_shim(run, node):
    """Put the ufw shim in place on the Node, once. The real program is kept as ufw.real."""
    if node in run.shimmed:
        return
    done = run.fleet.sh(node, f'mkdir -p {SHIM_DIR} && [ -e /usr/sbin/ufw.real ] || mv /usr/sbin/ufw /usr/sbin/ufw.real; cat > /usr/sbin/ufw && chmod 755 /usr/sbin/ufw',
                        stdin_text=UFW_SHIM)
    if done.returncode:
        raise Infra(f'the ufw shim could not be installed on {node}: {done.stderr.strip()[-200:]}')
    if not run.fleet.hosts[node].file('/usr/sbin/ufw.real').is_file:
        raise Infra('fault shim did not preserve the real UFW executable')
    run.shimmed.add(node)


def release_lock(run, node):
    """End the lock that `busy_node` holds (its holder leads its own process group): the stalled writes go on."""
    run.fleet.sh(node, f'kill -- -$(cat {SHIM_DIR}/holder.pid) 2>/dev/null; true')


def remove_shim(run, node):
    """Release the lock, drop the state and put the real ufw back."""
    release_lock(run, node)
    run.fleet.sh(node, f'rm -rf {SHIM_DIR}; [ -e /usr/sbin/ufw.real ] && mv -f /usr/sbin/ufw.real /usr/sbin/ufw; true')
    run.shimmed.discard(node)


def fault(run, args):
    if args.get('clear'):
        if set(args) != {'clear'}:
            raise Infra('fault clear takes no other key')
        for node in sorted(run.shimmed):
            remove_shim(run, node)
        return []
    missing = {'on', 'command', 'fail_after'} - set(args)
    if missing:
        raise Infra(f'fault lacks {sorted(missing)}')
    if args['command'] != 'ufw':
        raise NotBuilt(f"fault of {args['command']} is not built: only ufw")
    after = args['fail_after']
    word, count = str(after.get('match', '')), int(after.get('count', 0))
    if not re.fullmatch(r'[a-z-]+', word) or count < 1:
        raise Infra('fault fail_after needs a match of lower-case letters and a count of 1 or more')
    install_shim(run, args['on'])
    run.fleet.sh(args['on'], f"printf 'WORD=%s\\nAFTER=%s\\nSEEN=0\\n' {word} {count} > {SHIM_DIR}/fault")
    return []


def busy_node(run, args):
    """Hold one firewall write on the Node: the shim stalls its ufw writes behind a lock, and a real `firewall:allow` of a spare port
    starts and waits there. The Gateway holds the Node's firewall target for that Operation until `cleanup` releases the lock."""
    node, command = args['on'], args.get('command', 'firewall:allow')
    if command not in ('firewall:allow', 'firewall:deny'):
        raise NotBuilt(f'busy_node of {command} is not built')
    seconds = int(args.get('seconds', BUSY_SECONDS))
    install_shim(run, node)
    holder = f'setsid nohup flock -x {SHIM_DIR}/stall sleep {seconds} >/dev/null 2>&1 </dev/null & echo $! > {SHIM_DIR}/holder.pid'
    run.fleet.sh(node, f'touch {SHIM_DIR}/stall; {holder}')
    port = free_run(run.fleet.sh(node, 'ss -Htlun').stdout, {int(value) for value in run.names.values() if str(value).isdigit()}, 1)
    process = run.fleet.cli_background([command, str(port), '--node=' + str(run.fleet.nodes[node]['node_id']), '--protocol=tcp', '--label=proof-busy'])
    run.background.append(process)
    if not process.started(30):
        return [f'busy_node: the {command} did not start in 30 s']
    return []


def cleanup(run):
    """End what a case started on its own. The held write goes on when its lock ends, and the next case must not meet its Operation:
    wait for the CLI of the held write to end, then put the real ufw back. A failed case also gets a restore of `base`."""
    for node in sorted(run.shimmed):
        release_lock(run, node)
    for process in run.background:
        process.finish(CLEANUP_SECONDS)
    run.background.clear()
    for node in sorted(run.shimmed):
        remove_shim(run, node)

