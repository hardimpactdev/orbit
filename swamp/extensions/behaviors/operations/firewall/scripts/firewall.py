"""Converge one Orbit allow or deny on the target Node.

Lines match by tuple; the comment is only for people. UFW stops at the first match, so each line sits above
Orbit's own managed lines (comment `orbit:<name>`) and above every broader operator line, and below every
narrower one: one address, then a CIDR, then anywhere. Each family is inserted at its own position.
"""
import contextlib
import fcntl
import ipaddress
import json
import os
import re
import stat
import subprocess
import sys

# The Route firewall program shares this lock and refuses any file that is not root-owned 0600.
LOCK = '/run/lock/orbit-firewall.lock'
LOCK_UID = 0
ROW = re.compile(r'\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY)\s+IN\s+(.+?)(?:\s+#\s*(.*))?')


def ufw(args):
    result = subprocess.run(['/usr/sbin/ufw', *args], capture_output=True, text=True, check=False)
    if result.returncode:
        raise RuntimeError('firewall.apply_failed')
    return result.stdout


def inventory():
    text = ufw(['status', 'numbered'])
    if text.splitlines().count('Status: active') != 1:
        raise RuntimeError('firewall.backend_inactive')
    rows = []
    for line in text.splitlines():
        match = ROW.fullmatch(line.strip())
        if match:
            rows.append({'number': int(match[1]), 'to': match[2].strip(), 'action': match[3].lower(),
                         'source': match[4].strip(), 'comment': (match[5] or '').strip()})
    return rows


def lines(rule, protocol):
    """(argument for `from`, `ufw status` row) per family: anywhere is one explicit line per family."""
    to, action = rule['port'] + '/' + protocol, rule['action']
    if rule['source'] == 'any':
        return [('0.0.0.0/0', {'to': to, 'action': action, 'source': 'Anywhere'}),
                ('::/0', {'to': to + ' (v6)', 'action': action, 'source': 'Anywhere (v6)'})]
    six = ipaddress.ip_network(rule['source'], strict=False).version == 6
    return [(rule['source'], {'to': to + (' (v6)' if six else ''), 'action': action, 'source': rule['source']})]


def key(row):
    return row['to'], row['action'], row['source']


def v6(row):
    return '(v6)' in row['to'] or '(v6)' in row['source']


def ports(to):
    """(first, last, protocol or None for both) of a port line; None for interface or destination lines."""
    match = re.fullmatch(r'(\d{1,5})(?::(\d{1,5}))?(?:/(tcp|udp))?(?: \(v6\))?', to)
    return None if not match else (int(match[1]), int(match[2] or match[1]), match[3])


def scope(source):
    text = source.removesuffix(' (v6)')
    if text == 'Anywhere':
        return ipaddress.ip_network('::/0' if source.endswith('(v6)') else '0.0.0.0/0')
    return ipaddress.ip_network(text, strict=False)


def managed(row):
    return row['comment'].startswith('orbit:')


def below(row, other):
    """`row` must sit below `other`: it is a managed line of the same family, or a broader overlapping operator line."""
    if managed(row) or managed(other):
        return managed(row) and not managed(other) and v6(row) == v6(other)
    a, b = ports(row['to']), ports(other['to'])
    if not a or not b or v6(row) != v6(other) or a[1] < b[0] or b[1] < a[0] or None not in (a[2], b[2]) and a[2] != b[2]:
        return False
    wide, narrow = scope(row['source']), scope(other['source'])
    return wide != narrow and narrow.subnet_of(wide)


def ordered(rows, mine):
    for index, row in enumerate(rows):
        if key(row) in mine:
            for position, other in enumerate(rows):
                if (position < index and below(other, row)) or (position > index and below(row, other)):
                    return False
    return True


def present(rows, wanted):
    return wanted <= {key(row) for row in rows}


def command(rule, protocol, source, *tail):
    return [rule['action'], 'in', 'proto', protocol, 'from', source, 'to', 'any', 'port', rule['port'], *tail]


def converge(intent):
    rule, ensure = validated(intent)
    wanted = {key(row) for protocol in rule['protocols'] for _, row in lines(rule, protocol)}
    if ensure == 'absent':
        if not rule['retain']:
            for protocol in rule['protocols']:
                ufw(['--force', 'delete', *command(rule, protocol, rule['source'])])
            if wanted & {key(row) for row in inventory()}:
                raise RuntimeError('firewall.remove_verify_failed')
        return {'backend': 'absent'}
    for protocol in rule['protocols']:
        for source, row in lines(rule, protocol):
            rows = inventory()
            if key(row) in {key(item) for item in rows} and not ordered(rows, {key(row)}):
                ufw(['--force', 'delete', *command(rule, protocol, source)])
                rows = inventory()
            exists = key(row) in {key(item) for item in rows}
            line = None if exists else next((other['number'] for other in rows if below(other, {**row, 'comment': ''})), None)
            ufw([*(['insert', str(line)] if line else []), *command(rule, protocol, source, 'comment', rule['comment'])])
    rows = inventory()
    if not present(rows, wanted) or not ordered(rows, wanted):
        raise RuntimeError('firewall.verify_failed')
    return {'backend': 'active'}


@contextlib.contextmanager
def transaction_lock():
    parent = os.lstat(os.path.dirname(LOCK))
    if not stat.S_ISDIR(parent.st_mode) or parent.st_uid != LOCK_UID or os.path.islink(LOCK):
        raise RuntimeError('firewall.lock_failed')
    descriptor = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != LOCK_UID or stat.S_IMODE(info.st_mode) != 0o600:
            raise RuntimeError('firewall.lock_failed')
        fcntl.flock(descriptor, fcntl.LOCK_EX)
        yield
    finally:
        os.close(descriptor)


def validated(intent):
    rule, ensure = intent['rule'], intent['ensure']
    bounds = rule['port'].split(':')
    if (rule['action'] not in ('allow', 'deny') or ensure not in ('present', 'absent')
            or not re.fullmatch(r'\d{1,5}(?::\d{1,5})?', rule['port']) or not 1 <= int(bounds[0]) <= int(bounds[-1]) <= 65535
            or rule['protocol'] not in ('tcp', 'udp', 'any') or not re.fullmatch(r'orbit(?: [\x20-\x7e]{1,64})?', rule['comment'])
            or "'" in rule['comment'] or '"' in rule['comment'] or not isinstance(rule.get('retain', False), bool)):
        raise RuntimeError('firewall.input_invalid')
    if rule['source'] != 'any':
        ipaddress.ip_network(rule['source'], strict=False)
    return {**rule, 'protocols': ['tcp', 'udp'] if rule['protocol'] == 'any' else [rule['protocol']],
            'retain': rule.get('retain', False)}, ensure


def main():
    intent = json.loads(sys.argv[1])
    # UFW needs one host-level transaction for numbered reads, deletes and insertion.
    with transaction_lock():
        print(json.dumps(converge(intent)))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, TypeError, OSError) as error:
        # Exit 1 is the refusal outcome: stdout carries the public code for the terminal projection.
        print(json.dumps({'error_code': str(error) if isinstance(error, RuntimeError) else 'firewall.input_invalid'}))
        sys.exit(1)
