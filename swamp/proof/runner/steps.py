"""The steps of a case. Each step function takes the Run and its resolved arguments and returns a list of failures.

A step that cannot run raises `Infra`. A failure of a `setup` step makes the case `unrun`; in `act` and `expect` it fails the case.
"""
import json
import math
import random
import re
import shlex
import sys
import time
from pathlib import Path

import fleet as fleet_module
import spec

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
STDERR_TAIL = 160   # the characters a failed CLI call keeps of its stderr
# The Gateway counts an agent as offline after 15 s without an event.
OFFLINE_SECONDS = 20
CLI_EXPECT = {'exit', 'status', 'code', 'skipped', 'request', 'human', 'steps', 'json'}
LIST_ROWS = {'firewall:list': 'rules'}
# The parts of a list answer that `record` reads, per Node: `rules` and `managed` are lists, `unmatched` is the list under `live`,
# and `live` is the one object that holds the state of the report.
RECORD_SECTIONS = ('rules', 'managed', 'unmatched', 'live')
BASELINE_PROVIDERS = ('ufw', 'firewall_records', 'operations', 'listeners', 'node_records')


class Infra(Exception):
    """A step could not run: the case fails with cause infrastructure."""


class NotBuilt(Infra):
    pass


class Unrun(Exception):
    """A precondition did not hold: the case is unrun."""


class Failed(Exception):
    """An assertion failed and dependent steps cannot continue."""


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


def operation_ids(run, cli_args=None):
    """Audit the calls' actual write scopes. Unknown or cross-Node calls are exclusive and use an unfiltered audit."""
    calls = [cli_args] if cli_args is not None else [step.args for step in run.cli_steps]
    scopes = [spec.cli_scope(args, run.fleet.nodes) for args in calls]
    queries = {(kind, target_type, run.fleet.nodes[node]['node_id']) for kind, target_type, node in scopes} if all(scopes) else set()
    case_scopes = [spec.cli_scope(step.args) for step in run.cli_steps]
    if not scopes or any(scope is None or scope[2] != run.target for scope in scopes + case_scopes):
        queries = {(None, None, None)}
    ids = set()
    for query in sorted(queries):
        result = run.keep(run.fleet.operations(*query), 'json')
        document = result['json']
        if result['rc'] or not isinstance(document, dict) or not isinstance(document.get('data'), list):
            raise Infra('operation:list failed or returned no Operations list')
        rows = document['data']
        if any(not isinstance(row, dict) or type(row.get('id')) is not int or row['id'] < 1 for row in rows):
            raise Infra('operation:list returned an invalid Operation ID')
        ids.update(row['id'] for row in rows)
    return ids


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
    if ('json' in expect or args.get('save')) and 'json' not in forms:
        raise Infra('cli structured expectations and save need the JSON form')
    argv = [str(item) for item in args['run']]
    # The default is the candidate CLI of the outer VM, which acts for the fleet as its operator; `gateway` is that same caller.
    caller = args.get('as')
    caller = None if caller in (None, 'gateway') else caller
    if caller and caller not in run.fleet.nodes:
        raise Infra(f'cli as {caller}: not a Node of the fleet')
    if caller and script is not None:
        raise NotBuilt('cli as with tty is not built')
    before = max(operation_ids(run, args), default=0) if 'request' in expect else None
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
        found = check_result(run, label, form, forms, result, expect)
        if found:
            run.failed_calls.append(failed_call(argv, result))
        failures.extend(found)
        if form == 'json':
            for path, name in (args.get('save') or {}).items():
                run.names[name] = json_value(result['json'], path)
    if before is not None and (created := {number for number in operation_ids(run, args) if number > before}):
        failures.append(f'cli {argv[0]}: created Operation {sorted(created)}, expected no request')
    return failures


def error_code(document):
    """The error code of a CLI JSON document: an Operation's `error_code` or a refusal's `error.code`."""
    error = document.get('error')
    return document.get('error_code') or (error.get('code') if isinstance(error, dict) else None)


def failed_call(argv, result):
    """What a verdict keeps of a CLI call that did not do what the case expects: its command, exit code, error code and the end of
    its stderr on one line. A refusal before admission has no Operation, so this is all that names its cause."""
    document = result.get('json') if isinstance(result.get('json'), dict) else {}
    stderr = ' '.join(terminal.plain(result.get('stderr') or '').split())
    return {'run': argv[0], 'exit': result['rc'], 'code': error_code(document), 'stderr': stderr[-STDERR_TAIL:]}


def check_result(run, label, form, forms, result, expect):
    failures = []
    if 'exit' in expect and result['rc'] != expect['exit']:
        failures.append(f"{label}: exit {result['rc']}, expected {expect['exit']}")
    if form == 'json':
        document = result['json'] if isinstance(result['json'], dict) else {}
        operation = {}
        if 'status' in expect and document.get('status') != expect['status']:
            failures.append(f"{label}: status {document.get('status')}, expected {expect['status']}" + ('' if document else ' (no JSON)'))
        if 'code' in expect:
            code = error_code(document)
            if code != expect['code']:
                failures.append(f"{label}: code {code}, expected {expect['code']}")
        if 'skipped' in expect and expect['skipped'] not in skip_reasons(document):
            failures.append(f"{label}: not skipped with {expect['skipped']}")
        if 'steps' in expect:
            step_failures, operation = check_operation_steps(run, label, document, expect['steps'])
            failures.extend(step_failures)
        failures.extend(check_json(document, expect.get('json') or []))
        return operation_failures(failures, {**document, **operation})
    # The human form has no JSON: the code and the status are read from the JSON form when the call has one.
    text = terminal.plain(result['stdout'] + result['stderr'])
    for wanted in [expect['human']] if isinstance(expect.get('human'), str) else expect.get('human') or []:
        if wanted not in text:
            failures.append(f'{label}: the output lacks {wanted!r}')
    if 'json' not in forms and 'code' in expect and expect['code'] not in text:
        failures.append(f"{label}: the output lacks the code {expect['code']}")
    found = re.search(r'Operation:\s*(\d+)', text)
    document = {}
    if found and (failures or ('json' not in forms and 'status' in expect)):
        document = run.cli(['operation:show', found[1]])['json'] or {}
    if 'json' not in forms and 'status' in expect:
        status = document.get('status')
        if status != expect['status']:
            failures.append(f"{label}: status {status}, expected {expect['status']}" + ('' if found else ' (no Operation in the output)'))
    return operation_failures(failures, document)


def operation_failures(failures, document):
    """Keep public Operation diagnostics in verdicts too; private error details stay in the VM receipt."""
    fields = ', '.join(f'{key}={document[key]!r}' for key in ('failed_step', 'error_code')
                       if isinstance(document.get(key), str) and document[key])
    return [f'{message} ({fields})' for message in failures] if fields else failures


def json_value(document, path):
    """A dotted path, with optional array index or unique row selection: nodes[id=7].roles. Missing is not null."""
    value = document
    for part in path.split('.'):
        match = re.fullmatch(r'([^\[\]]+)(?:\[(?:([a-z_][a-z0-9_]*)=)?([a-zA-Z0-9_-]+)\])?', part)
        if not match or not isinstance(value, dict) or match[1] not in value:
            raise Infra(f'JSON path {path}: missing {part}')
        value = value[match[1]]
        if match[3] is not None:
            if not isinstance(value, list):
                raise Infra(f'JSON path {path}: {part} is not a list')
            if match[2] is not None:
                expected = int(match[3]) if match[3].isdigit() else match[3]
                selected = [row for row in value if isinstance(row, dict) and row.get(match[2]) == expected]
            elif match[3].isdigit():
                selected = value[int(match[3]):int(match[3]) + 1]
            else:
                raise Infra(f'JSON path {path}: {part} needs an index or field selector')
            if len(selected) != 1:
                raise Infra(f'JSON path {path}: {part} selects {len(selected)} rows')
            value = selected[0]
    return value


def check_json(document, assertions):
    if not isinstance(assertions, list) or any(not isinstance(item, dict) or not isinstance(item.get('path'), str)
                                               or set(item) not in ({'path', 'equals'}, {'path', 'absent'}, {'path', 'at_most'})
                                               or ('absent' in item and item['absent'] is not True)
                                               or ('at_most' in item and not finite_number(item['at_most'])) for item in assertions):
        raise Infra('JSON assertions need path and equals, absent: true, or a finite numeric at_most')
    failures = []
    for assertion in assertions:
        try:
            actual = json_value(document, assertion['path'])
        except Infra as error:
            if not assertion.get('absent'):
                failures.append(str(error))
            continue
        if assertion.get('absent'):
            failures.append(f"JSON path {assertion['path']}: expected absent")
        elif 'at_most' in assertion:
            if not finite_number(actual) or actual > assertion['at_most']:
                failures.append(f"JSON path {assertion['path']}: {actual!r}, expected a number at most {assertion['at_most']!r}")
        elif actual != assertion['equals']:
            failures.append(f"JSON path {assertion['path']}: {actual!r}, expected {assertion['equals']!r}")
    return failures


def finite_number(value):
    return type(value) is int or (type(value) is float and math.isfinite(value))


def check_operation_steps(run, label, document, wanted):
    operation_id = document.get('id')
    if not operation_id:
        return [f'{label}: no Operation ID for steps'], {}
    result = run.cli(['operation:show', str(operation_id)])
    if result['rc'] != 0:
        return [f'{label}: could not read Operation steps'], {}
    operation = result['json'] or {}
    rows = operation.get('steps')
    if not isinstance(rows, list) or any(not isinstance(row, dict) or not isinstance(row.get('name'), str) or not isinstance(row.get('status'), str) for row in rows):
        return [f'{label}: Operation step data is missing or invalid'], operation
    pattern = re.compile(re.escape(wanted['name']) + r'(?:-([0-9]+)-\1)?')
    count = sum(bool(pattern.fullmatch(row['name'])) and row['status'] in ('running', 'succeeded', 'failed') for row in rows)
    if count != wanted['count']:
        return [f"{label}: ran {count} steps named {wanted['name']}, expected {wanted['count']}"], operation
    return [], operation


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


def service(run, args):
    node = nodes_of(run, 'service', args['on'])[0]
    if args['state'] not in ('active', 'inactive', 'stop'):
        raise Infra('service state is active, inactive or stop')
    command = ['systemctl', 'stop' if args['state'] == 'stop' else 'is-active', args['name']]
    done = run.fleet.sh(node, shlex.join(command))
    run.evidence_text('service-' + args['name'] + '.txt', done.stdout + done.stderr)
    if args['state'] == 'stop':
        return [] if done.returncode == 0 else [f'service {node}: stop failed: {done.stderr}']
    return [] if done.stdout.strip() == args['state'] else [f'service {node} {args["name"]}: {done.stdout.strip()}, expected {args["state"]}']


def package(run, args):
    node = nodes_of(run, 'package', args['on'])[0]
    for name in args['any']:
        done = run.fleet.sh(node, shlex.join(['dpkg-query', '-W', '-f=${db:Status-Status}', name]))
        if done.returncode == 0 and done.stdout.strip() == 'installed':
            return []
    return [f'package {node}: none of {args["any"]} is installed']


def ansible_changes(run, args):
    command = ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit', 'ORBIT_HOME=/home/orbit/.orbit',
               'php', fleet_module.NODE_CHECKOUT + '/tools/swamp-proof-guest/ansible-recaps.php']
    done = run.fleet.sh('gateway', shlex.join(command), timeout=120, stdin_text=json.dumps(args))
    if done.returncode:
        raise Infra(f'Ansible recap read: {done.stderr[-500:]}')
    try:
        document = json.loads(done.stdout)
    except ValueError as error:
        raise Infra('Ansible recap read returned no JSON') from error
    run.evidence_text('ansible-recaps.json', json.dumps(document, indent=2))
    return check_ansible_changes(document, args)


def check_ansible_changes(document, args):
    failures = []
    for phase in args['phases']:
        observed = document.get(phase, {})
        hosts = observed.get('hosts', [])
        if observed.get('status') != 'succeeded' or not hosts:
            failures.append(f'Ansible {phase}: missing successful phase recap')
        for host in hosts:
            if host.get('changed') != args['changed'] or host.get('failed') != 0 or host.get('unreachable') != 0:
                failures.append(f'Ansible {phase}: {host}')
    return failures


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


def recorded_guest(run, payload):
    """Test support on the mounted candidate Gateway, as its managed user. Never a production endpoint."""
    disabled = 'curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,exec,shell_exec,passthru,system,proc_open,popen'
    command = ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit', 'ORBIT_HOME=/home/orbit/.orbit',
               'php', '-d', 'disable_functions=' + disabled, fleet_module.NODE_CHECKOUT + '/tools/swamp-proof-guest/recorded-read.php']
    done = run.fleet.sh('gateway', shlex.join(command), stdin_text=json.dumps(payload))
    if done.returncode:
        raise Infra(f'recorded Gateway support: exit {done.returncode}: {(done.stderr or done.stdout)[-500:]}')
    try:
        return json.loads(done.stdout)
    except ValueError as error:
        raise Infra('recorded Gateway support returned no JSON') from error


def executor_guest(run, payload):
    """The executor proof signer on the nested Gateway, as its managed user (tools/swamp-proof-guest/executor-order.php)."""
    command = ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit', 'ORBIT_HOME=/home/orbit/.orbit',
               'php', fleet_module.NODE_CHECKOUT + '/tools/swamp-proof-guest/executor-order.php']
    done = run.fleet.sh('gateway', shlex.join(command), stdin_text=json.dumps(payload))
    if done.returncode:
        raise Infra(f'executor proof signer: exit {done.returncode}: {(done.stderr or done.stdout)[-500:]}')
    try:
        return json.loads(done.stdout)
    except ValueError as error:
        raise Infra('executor proof signer returned no JSON') from error


def executor_key(run, args):
    """Make the proof-only key on the nested Gateway and pin its public half there (docs/reference/executor.md#proof-key). A new pin
    takes effect for the next Operation: the Gateway's config is cleared and Horizon restarted."""
    key = executor_guest(run, {'mode': 'key'})
    run.evidence_text('executor-proof-public-key.txt', key['public_key'] + '\n')
    if key['changed']:
        clear = ['runuser', '-u', 'orbit', '--', 'php', fleet_module.NODE_CHECKOUT + '/apps/gateway/artisan', 'config:clear']
        for command in (shlex.join(clear), 'systemctl restart orbit-gateway-horizon.service'):
            done = run.fleet.sh('gateway', command, timeout=120)
            if done.returncode:
                raise Infra(f'executor proof key: {command}: exit {done.returncode}: {(done.stderr or done.stdout)[-300:]}')
    return []


RELAY = '/usr/local/libexec/orbit-executor'
# How each caller reaches the executor's socket on the Node. Only `relay` is an allowed caller from a shell: the observer is the other.
EXECUTOR_CALLERS = {
    'relay': f'sudo -n -u orbit-executor-relay {RELAY} relay',
    'other_user': f'runuser -u nobody -- {RELAY} relay',
    'root_outside_observer': f'{RELAY} relay',
    'relay_other_program': ("runuser -u orbit-executor-relay -- python3 -c 'import socket, sys; s = socket.socket(socket.AF_UNIX); "
                            's.connect("/run/orbit-executor.sock"); s.sendall(sys.stdin.buffer.read() + b"\\n"); s.shutdown(socket.SHUT_WR); '
                            "sys.stdout.write(s.recv(65536).decode())'"),
}


EXECUTOR_JOURNAL = '/var/lib/orbit/executor/journal'
# A proof.noop finishes in milliseconds once the observer hands it over: after the last publish the step waits this long, once,
# and then reads the executor's journal and log a single time.
EXECUTOR_RESULT_SECONDS = 3


def executor_refusals(run, node, since, refusal):
    """The executor's log since `since`, and how many of its lines hold `refusal`."""
    log = run.fleet.sh(node, shlex.join(['journalctl', '-u', 'orbit-executor.service', '--since', since, '--no-pager', '-o', 'cat'])).stdout
    return log, len([line for line in log.splitlines() if refusal in line])


def executor_done(run, node):
    """The executor journal's `done` records, one per action that ran."""
    records = (json_line(line) for line in run.fleet.sh(node, shlex.join(['cat', EXECUTOR_JOURNAL])).stdout.splitlines())
    return [record for record in records if record.get('record') == 'done']


def json_line(line):
    try:
        value = json.loads(line)
    except ValueError:
        return {}
    return value if isinstance(value, dict) else {}


# The Gateway signs an order to expire 5 s after it signs it: a replay must reach the executor before that.
EXECUTOR_ORDER_EXPIRY_MS = 5000


def executor_publish(run, args):
    """Publish signed proof.noop orders as `executor-order` on the Node's channel through the nested Gateway's own signer and
    publisher, so they reach the executor only through the observer (ADR 0266 slice 2). `tamper` makes one order security test's
    order. `replay: true` publishes each order twice, the exact same wire, before the one wait, so the second copy arrives inside
    the first one's expiry; its action must then have run exactly once. After one wait of EXECUTOR_RESULT_SECONDS, `done: true`
    needs a `done` journal record of every first copy and saves the latency from the Gateway's publish to the executor's result as
    evidence; `refused: <code>` needs each order, or each replayed copy, refused on the observer path with that code. A replay's
    refusal may carry the first result or not, as the copies race: the proof checks the security property, not the timing."""
    node = nodes_of(run, 'executor_publish', args['on'])[0]
    # `operation` only names the evidence: the Gateway's proof support gives each order its own numeric Operation id.
    payload = {'mode': 'publish', 'node': run.fleet.nodes[node]['node_id'], 'count': args.get('count', 1)}
    payload.update({key: args[key] for key in ('tamper', 'replay') if key in args})
    expect = args['expect']
    refusal = 'from Observer: ' + expect.get('refused', '')
    since = run.fleet.sh(node, 'date -u +%Y-%m-%dT%H:%M:%S').stdout.strip()
    # `since` has whole seconds, so an earlier step's refusal can fall inside it: count only the refusals after this publish.
    before = executor_refusals(run, node, since, refusal)[1] if 'refused' in expect else 0
    orders = executor_guest(run, payload)['orders']
    firsts = [order for order in orders if not order.get('replay')]
    replays = [order for order in orders if order.get('replay')]
    run.sleep(EXECUTOR_RESULT_SECONDS)
    failures = [f'executor {node}: replay of {order["operation"]} published {order["published_ms"] - first["published_ms"]} ms after it, '
                f'past the {EXECUTOR_ORDER_EXPIRY_MS} ms expiry'
                for first, order in zip(firsts, replays) if order['published_ms'] - first['published_ms'] >= EXECUTOR_ORDER_EXPIRY_MS]
    if 'refused' in expect:
        log, count = executor_refusals(run, node, since, refusal)
        run.evidence_text(f'executor-ws-{args["operation"]}.txt', log)
        wanted = len(replays) if replays else len(orders)
        failures += [] if count - before >= wanted else [
            f'executor {node} over the WebSocket: {wanted - (count - before)} of {wanted} orders not refused with "{refusal}" within {EXECUTOR_RESULT_SECONDS} s']
    records = executor_done(run, node) if expect.get('done') or replays else []
    runs = [record.get('operation') for record in records]
    failures += [f'executor {node}: Operation {order["operation"]} ran {runs.count(order["operation"])} times for an order and its replay, not once'
                 for order in replays if runs.count(order['operation']) != 1]
    if expect.get('done'):
        failures += executor_latency(run, node, args['operation'], firsts, records)
    return failures


def executor_latency(run, node, label, orders, records):
    """Every order has a `done` journal record; the latency from the Gateway's publish to that record goes to the evidence."""
    done = {record.get('nonce'): record for record in records}
    found = [(order, done[order['nonce']]) for order in orders if order['nonce'] in done]
    if len(found) < len(orders):
        return [f'executor {node} over the WebSocket: {len(orders) - len(found)} of {len(orders)} orders have no done record within {EXECUTOR_RESULT_SECONDS} s']
    summary = latency_summary([record['at_ms'] - order['published_ms'] for order, record in found])
    summary['statuses'] = sorted({journal_status(record) for _, record in found})
    run.evidence_text(f'executor-ws-{label}.json', json.dumps(summary, indent=2) + '\n')
    return [] if summary['statuses'] == ['done'] else [f'executor {node}: results {summary["statuses"]}']


def journal_status(record):
    """How a journal `done` record's action ended: the executor nests it as `outcome.status`."""
    outcome = record.get('outcome')
    return outcome.get('status') if isinstance(outcome, dict) else None


def latency_summary(values):
    """p50, p95 and max of a list of latencies in milliseconds, with the sorted list."""
    latencies = sorted(values)
    return {'count': len(latencies), 'p50_ms': latencies[len(latencies) // 2],
            'p95_ms': latencies[min(len(latencies) - 1, (len(latencies) * 95) // 100)], 'max_ms': latencies[-1], 'latencies_ms': latencies}


def executor_runs(run, node, operation):
    """How many times the executor ran the action of one Operation: its `done` journal records."""
    return [record.get('operation') for record in executor_done(run, node)].count(operation)


def executor_round_trip(run, args):
    """`count` round trips on the waiting request's path (ADR 0266 slice 3), one after another, through the nested Gateway's proof
    support: each records an `executor.proof` Operation, publishes its signed order as `executor-order`, and waits for the result
    with ExecutorResultWait within ORBIT_EXECUTOR_WAIT_MS. Every trip needs an observed `accepted` before `done`, the same
    result recorded on its Operation by the listener, and one run in the executor's journal. The latency from publish to the result in the waiter
    goes to the evidence as p50, p95 and max."""
    node = nodes_of(run, 'executor_round_trip', args['on'])[0]
    answer = executor_guest(run, {'mode': 'round_trip', 'node': run.fleet.nodes[node]['node_id'], 'count': args.get('count', 1)})
    trips = answer['round_trips']
    runs = [record.get('operation') for record in executor_done(run, node)]
    summary = latency_summary([trip['latency_ms'] for trip in trips])
    accepted = [trip['accepted_ms'] for trip in trips if trip['accepted_ms'] is not None]
    summary.update({'wait_bound_ms': answer['wait_ms'], 'accepted_ms': latency_summary(accepted) if accepted else None,
                    'statuses': sorted({trip['status'] for trip in trips})})
    run.evidence_text(f'executor-round-trip-{args["operation"]}.json', json.dumps({'summary': summary, 'round_trips': trips}, indent=2) + '\n')
    failures = [] if len(trips) == args.get('count', 1) else [f'executor {node}: {len(trips)} round trips, expected {args.get("count", 1)}']
    for trip in trips:
        operation = trip['operation']
        line = trip['line'] or {}
        if trip['status'] != 'done' or line.get('repeat') is not False:
            failures.append(f'executor {node}: Operation {operation} waited {trip["status"]}, line {line}')
        elif trip['accepted_ms'] is None:
            failures.append(f'executor {node}: Operation {operation} completed without prior acceptance')
        if trip['recorded'] != trip['line']:
            failures.append(f'executor {node}: Operation {operation} recorded {trip["recorded"]}, the waiter got {trip["line"]}')
        if runs.count(operation) != 1:
            failures.append(f'executor {node}: Operation {operation} ran {runs.count(operation)} times, not once')
    return failures


def first_result(line):
    """A result line without what a repeat changes: the `repeat` mark and the Node's clock."""
    return {key: value for key, value in (line or {}).items() if key not in ('repeat', 'node_clock_ms')}


def executor_power(run, args):
    """One signed sleep/discard on a durable proof Operation, with an independent read of its on-Node receipt.
    A real clone fixture with T3/Vite is required by the case; it is not created or reset by this step."""
    node = nodes_of(run, 'executor_power', args['on'])[0]
    action = args.get('action', 'sleep')
    answer = executor_guest(run, {'mode': 'power', 'node': run.fleet.nodes[node]['node_id'],
                                 'instance': args['instance'], 'action': action})
    line = answer.get('line') or {}
    operation = answer['operation']
    failures = []
    if answer.get('status') != 'done' or answer.get('operation_status') != 'succeeded' or line.get('status') != 'done' or answer.get('recorded') != line:
        failures.append(f'executor {node}: power Operation {operation} did not complete and record its result')
    if line.get('action') != action or line.get('target') != str(answer['instance']):
        failures.append(f'executor {node}: power result has the wrong action or Instance')
    if executor_runs(run, node, operation) != 1:
        failures.append(f'executor {node}: power action must have exactly one done journal record')
    instance = int(answer['instance'])
    base = f'/var/lib/orbit/executor/power/{instance}'
    if action == 'sleep':
        read = run.fleet.sh(node, f'cat {base}/receipt.json')
        if read.returncode:
            raise Infra('executor power: no readable sleep receipt')
        receipt = json.loads(read.stdout)
        answer['receipt'] = receipt
        if receipt.get('sleep') != 'frozen' or receipt.get('plan') != 'ready' or receipt.get('residual_anon_bytes', 1 << 63) > 32 << 20:
            failures.append(f'executor {node}: clone did not hibernate with a complete plan within the residual policy')
        if answer.get('instance_name') == 'default':
            failures.append('executor power: a default Instance does not establish the real-clone proof')
        check = run.fleet.sh(node, f'test -s {base}/plan.json && test -s {base}/limits.json && test -f /data/caddy/orbit/hibernation/app-instance-{instance}.asleep')
        if check.returncode:
            failures.append('executor power: committed plan, limits record or asleep marker missing')
    else:
        check = run.fleet.sh(node, f'test ! -e {base}/plan.json && test ! -e {base}/limits.json')
        if check.returncode:
            failures.append('executor power: discard left a plan or limits record')
    run.evidence_text(f'executor-power-{args["operation"]}.json', json.dumps(answer, indent=2) + '\n')
    return failures


def executor_lost_result(run, args):
    """A lost result recovered by a repeat (ADR 0266 slice 3). The proof support runs one `executor.proof` order, drops its hand-off
    from Valkey, and shows that a new waiting request then times out. It then signs a new order, with a new nonce, for the same
    Operation. The executor must answer `repeat` with the first result, the waiter must get it, the Operation must keep the first
    result, and the executor's journal must show one run."""
    node = nodes_of(run, 'executor_lost_result', args['on'])[0]
    answer = executor_guest(run, {'mode': 'lost_result', 'node': run.fleet.nodes[node]['node_id']})
    run.evidence_text(f'executor-lost-result-{args["operation"]}.json', json.dumps(answer, indent=2) + '\n')
    operation, recorded, recovered = answer['operation'], answer['recorded'] or {}, answer['recovered']
    checks = [
        (answer['dropped']['status'] == 'done' and recorded.get('status') == 'done' and recorded.get('repeat') is False,
         f'the first order: waited {answer["dropped"]["status"]}, recorded {recorded}'),
        (answer['lost']['status'] == 'timeout', f'the waiter after the dropped hand-off got {answer["lost"]["status"]}, not timeout'),
        (answer['repeat_nonce'] != answer['first_nonce'], 'the repeat reused the first nonce'),
        (recovered['status'] == 'done' and (recovered['line'] or {}).get('repeat') is True,
         f'the repeat: waited {recovered["status"]}, line {recovered["line"]}'),
        (first_result(recovered['line']) == first_result(recorded), f'the repeat {recovered["line"]} is not the first result {recorded}'),
        (answer['status'] == 'succeeded', f'the Operation ended {answer["status"]}'),
    ]
    failures = [f'executor {node}: Operation {operation}: {message}' for passed, message in checks if not passed]
    runs = executor_runs(run, node, operation)
    return failures + ([] if runs == 1 else [f'executor {node}: Operation {operation} ran {runs} times, not once'])


def executor_order(run, args):
    """Sign one proof.noop order for the Node and hand it to the executor as one caller. `expect` names the result line's fields, or
    `refused_caller` with the executor's logged refusal code (an empty reply), or `connect: denied` for a user the socket's mode
    refuses."""
    node = nodes_of(run, 'executor_order', args['on'])[0]
    node_id = run.fleet.nodes[node]['node_id']
    payload = {'mode': 'order', 'node': node_id, 'operation': args['operation']}
    if 'nonce' in args:
        payload['nonce'] = run.names[args['nonce']]
    signed = executor_guest(run, payload)
    if 'save' in args:
        run.names[args['save']] = signed['nonce']
    caller = args.get('as', 'relay')
    if caller not in EXECUTOR_CALLERS:
        raise Infra(f'executor_order: unknown caller {caller}')
    since = run.fleet.sh(node, 'date -u +%Y-%m-%dT%H:%M:%S').stdout.strip()
    done = run.fleet.sh(node, EXECUTOR_CALLERS[caller], stdin_text=signed['order'])
    run.evidence_text(f'executor-{caller}-{args["operation"]}.txt', done.stdout + done.stderr)
    expect = args['expect']
    if 'connect' in expect:
        refused = done.returncode != 0 and 'ermission denied' in done.stderr
        return [] if refused else [f'executor {node} {caller}: connect not denied: exit {done.returncode} {done.stderr[-200:]}']
    if 'refused_caller' in expect:
        failures = [] if done.stdout.strip() == '' else [f'executor {node} {caller}: a refused caller got a reply: {done.stdout[-200:]}']
        log = run.fleet.sh(node, shlex.join(['journalctl', '-u', 'orbit-executor.service', '--since', since, '--no-pager', '-o', 'cat']))
        if expect['refused_caller'] not in log.stdout:
            failures.append(f'executor {node} {caller}: no {expect["refused_caller"]} in the executor log')
        return failures
    lines = [line for line in done.stdout.splitlines() if line.strip()]
    if done.returncode or len(lines) != 2:
        return [f'executor {node} {caller}: exit {done.returncode}, {len(lines)} lines: {(done.stdout + done.stderr)[-300:]}']
    accepted, result = (json.loads(line) for line in lines)
    failures = [] if accepted == {'status': 'accepted'} else [f'executor {node}: first line {accepted}']
    failures += [f'executor {node} {key}: {result.get(key)!r}, expected {value!r}' for key, value in expect.items() if result.get(key) != value]
    return failures


def node_fixture(run, args):
    if args['kind'] == 'retained' and not all(key in args for key in ('consumer', 'serving')):
        raise Infra('a retained Node fixture needs consumer and serving Nodes')
    payload = {'mode': 'fixture', **{key: value for key, value in args.items() if key != 'as'}}
    for key in ('consumer', 'serving'):
        if key in payload:
            payload[key] = run.fleet.nodes[payload[key]]['node_id']
    value = recorded_guest(run, payload)
    run.names.update({f"{args['as']}.{key}": item for key, item in value.items()})
    return []


def recorded_request(run, args):
    """An audited HTTP-kernel request, with optional request-local agent view and clock for exact boundaries."""
    payload = {'mode': 'request', **{key: value for key, value in args.items() if key != 'expect'}}
    payload['caller'] = run.fleet.nodes[args['caller']]['node_id']
    document = recorded_guest(run, payload)
    run.evidence_text('recorded-request.json', json.dumps(document, indent=2))
    return check_json(document, args['expect'])


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
    if provider == 'node_records':
        return [json.dumps(recorded_guest(run, {'mode': 'snapshot'}), sort_keys=True)]
    # Operations are append-only. The newest matching ID detects additions even when older rows leave the newest page.
    return [str(max(operation_ids(run), default=0))]


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


def clone_fixture(run, args, lane='CloneLane', module='clone_lifecycle'):
    import importlib

    from clone_lifecycle import ProofFailure

    run.clone_lane = getattr(importlib.import_module(module), lane)(run, fleet_module)
    try:
        run.clone_lane.setup(**args)
    except ProofFailure as error:
        return [str(error)]
    return []


def route_fixture(run, args):
    from clone_lifecycle import ProofFailure
    from route_lifecycle import RouteLane

    run.clone_lane = RouteLane(run, fleet_module)
    try:
        run.clone_lane.setup(**args)
    except ProofFailure as error:
        return [str(error)]
    return []


def clone_step(method):
    def step(run, args):
        from clone_lifecycle import ProofFailure, ProofInfrastructure

        try:
            getattr(run.clone_lane, method)(**args)
        except ProofInfrastructure as error:
            raise Infra(str(error)) from None
        except ProofFailure as error:
            raise Failed(str(error)) from None
        return []
    return step


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
    if lane := getattr(run, 'clone_lane', None):
        lane.cleanup()


def unbuilt(name):
    def step(run, args):
        raise NotBuilt(f'step {name} is not built')
    return step


FUNCTIONS = {name: unbuilt(name) for name in spec.STEPS}
FUNCTIONS.update({'fault': fault, 'busy_node': busy_node, 'ipv6_addresses': ipv6_addresses, 'listener': listener, 'ufw_rule': ufw_rule, 'probe': probe, 'cli': cli, 'ufw': ufw, 'record': record, 'doctor': doctor,
                  'service': service, 'package': package, 'ansible_changes': ansible_changes, 'unchanged': unchanged, 'ufw_delete': ufw_delete, 'agent_report': agent_report, 'node_down': node_down,
                  'node_access_remove': node_access_remove, 'node_fixture': node_fixture, 'recorded_request': recorded_request,
                  'executor_key': executor_key, 'executor_order': executor_order,
                  'executor_publish': executor_publish, 'executor_round_trip': executor_round_trip,
                  'executor_lost_result': executor_lost_result, 'executor_power': executor_power,
                  'clone_fixture': clone_fixture, 'clone_create': clone_step('create'), 'clone_destroy': clone_step('destroy'), 'clone_work': clone_step('work'),
                  'clone_compare': clone_step('compare'), 'clone_cleanup': clone_step('cleanup'),
                  'route_fixture': route_fixture, 'route_create': clone_step('route_create'), 'route_destroy': clone_step('route_destroy'),
                  'route_clone_destroy': clone_step('route_clone_destroy'), 'route_compare': clone_step('route_compare'),
                  'update_fixture': lambda run, args: clone_fixture(run, args, 'UpdateLane', 'route_update'),
                  'route_create_tld': clone_step('route_create_tld'), 'route_update': clone_step('route_update'),
                  'update_compare': clone_step('update_compare'),
                  'sync_fixture': lambda run, args: clone_fixture(run, args, 'SyncLane'), 'sync_copy': clone_step('copy'),
                  'task_sync': clone_step('sync'), 'sync_compare': clone_step('sync_compare'),
                  'process_fixture': lambda run, args: clone_fixture(run, args, 'ProcessLane', 'process_control'),
                  'host_instance': clone_step('host_instance'), 'process_create': clone_step('process'), 'schedule_create': clone_step('schedule'),
                  'process_control': clone_step('start_stop'), 'process_replay': clone_step('replay'), 'process_retry': clone_step('retry'),
                  'schedule_enable': clone_step('enable'), 'process_refused': clone_step('refused'),
                  'process_compare': clone_step('compare'), 'process_cleanup': clone_step('cleanup')})
