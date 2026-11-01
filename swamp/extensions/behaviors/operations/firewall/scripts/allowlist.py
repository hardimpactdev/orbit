"""Converge one comment-owned ordered UFW source allowlist on the target Node."""
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


def command(args):
    result = subprocess.run(['/usr/sbin/ufw', *args], capture_output=True, text=True, check=False)
    if result.returncode:
        raise RuntimeError('firewall.apply_failed')
    return result.stdout


def inventory():
    text = command(['status', 'numbered'])
    if text.splitlines().count('Status: active') != 1:
        raise RuntimeError('firewall.backend_inactive')
    rows = []
    for line in text.splitlines():
        match = re.fullmatch(r'\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY)\s+IN\s+(.+?)(?:\s+#\s*(.*))?', line.strip())
        if match:
            rows.append({'number': int(match[1]), 'to': match[2].strip(), 'action': match[3].lower(),
                         'source': match[4].strip(), 'comment': (match[5] or '').strip()})
        elif re.match(r'^\[\s*\d+\]', line.strip()):
            number = int(re.match(r'^\[\s*(\d+)\]', line.strip())[1])
            rows.append({'number': number, 'comment': line.split('#', 1)[1].strip() if '#' in line else ''})
    return rows


def permit_sources(rule):
    sources = []
    for source in rule['sources']:
        sources.extend(['0.0.0.0/1', '128.0.0.0/1'] if source == '0.0.0.0/0' else [source])
    return sorted(set(sources))


def expected(rule):
    tag = f"orbit:node:{rule['node_id']}:allowlist:{rule['name']}"
    target = rule['port'] + '/' + rule['protocol']
    return [{'to': target, 'action': 'allow', 'source': source, 'comment': tag} for source in permit_sources(rule)] + [
        {'to': target, 'action': 'deny', 'source': 'Anywhere', 'comment': tag},
    ]


def same(row, desired):
    return all(row.get(key) == value for key, value in desired.items())


def owned(rows, tag):
    return [row for row in rows if row['comment'] == tag]


def compliant(rows, desired, tag):
    ipv4 = [row for row in rows if '(v6)' not in row.get('to', '') and '(v6)' not in row.get('source', '')]
    block = owned(ipv4, tag)
    if len(block) != len(desired) or not all(same(row, item) for row, item in zip(block, desired, strict=True)):
        return False
    # Other allowlists protect disjoint ports and can precede this block.
    end = max(row['number'] for row in block)
    def unrelated_block(row):
        if not re.fullmatch(r'orbit:node:\d+:allowlist:[a-z0-9._-]+', row['comment']):
            return False
        match = re.fullmatch(r'(\d{1,5}(?::\d{1,5})?)/(tcp|udp)(?: \(v6\))?', row.get('to', ''))
        if not match:
            return False
        port, protocol = desired[-1]['to'].split('/')
        a, b = list(map(int, match[1].split(':'))), list(map(int, port.split(':')))
        return protocol != match[2] or a[0] > b[-1] or b[0] > a[-1]
    if any(row['number'] < end and row['comment'] != tag and not unrelated_block(row) for row in ipv4):
        return False
    ipv6 = [row for row in rows if '(v6)' in row.get('to', '') or '(v6)' in row.get('source', '')]
    deny6 = {'to': desired[-1]['to'] + ' (v6)', 'action': 'deny', 'source': 'Anywhere (v6)', 'comment': tag}
    block6 = owned(ipv6, tag)
    return (len(block6) == 1 and same(block6[0], deny6)
            and not any(row['number'] < block6[0]['number'] and row['comment'] != tag and not unrelated_block(row) for row in ipv6))


def delete(rows):
    for row in sorted(rows, key=lambda row: row['number'], reverse=True):
        command(['--force', 'delete', str(row['number'])])


def insert(rule, action, source, tag, destination='any'):
    command(['prepend', action, 'in', 'proto', rule['protocol'], 'from', source,
             'to', destination, 'port', rule['port'], 'comment', tag])


def converge(intent):
    rule = intent['rule']
    tag = f"orbit:node:{rule['node_id']}:allowlist:{rule['name']}"
    temporary = tag + ':updating'
    rows = inventory()
    if intent['ensure'] == 'absent':
        delete([row for row in rows if row['comment'] in (tag, temporary)])
        if any(row['comment'] in (tag, temporary) for row in inventory()):
            raise RuntimeError('firewall.remove_verify_failed')
        return {'backend': 'absent'}
    desired = expected(rule)
    tuples = [(item['to'], item['source']) for item in desired]
    tuples.append((desired[-1]['to'] + ' (v6)', 'Anywhere (v6)'))
    if any(row['comment'] not in (tag, temporary) and (row.get('to'), row.get('source')) in tuples for row in rows):
        raise RuntimeError('firewall.rule_collision')
    if compliant(rows, desired, tag) and not owned(rows, temporary):
        return {'backend': 'active'}
    # Keep a deny in front of broad trust throughout the replacement.
    for destination in ['0.0.0.0/1', '128.0.0.0/1', '::/1', '8000::/1']:
        insert(rule, 'deny', 'any', temporary, destination)
    if len(owned(inventory(), temporary)) != 4:
        raise RuntimeError('firewall.verify_failed')
    delete(owned(inventory(), tag))
    for item in reversed(desired):
        insert(rule, item['action'], 'any' if item['source'] == 'Anywhere' else item['source'], tag)
    rows = inventory()
    if not compliant([row for row in rows if row['comment'] != temporary], desired, tag):
        raise RuntimeError('firewall.verify_failed')
    delete(owned(rows, temporary))
    if not compliant(inventory(), desired, tag):
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

def main():
    intent = json.loads(sys.argv[1])
    rule = intent['rule']
    if (not re.fullmatch(r'[a-z0-9](?:[a-z0-9._-]{0,61}[a-z0-9])?', rule['name'])
            or not isinstance(rule['node_id'], int) or rule['node_id'] < 1
            or rule['protocol'] not in ('tcp', 'udp') or rule['action'] != 'allowlist'
            or intent['ensure'] not in ('present', 'absent')):
        raise RuntimeError('firewall.input_invalid')
    bounds = rule['port'].split(':')
    if (not re.fullmatch(r'\d{1,5}(?::\d{1,5})?', rule['port'])
            or not 1 <= int(bounds[0]) <= int(bounds[-1]) <= 65535
            or not 1 <= len(rule['sources']) <= 64):
        raise RuntimeError('firewall.input_invalid')
    for source in rule['sources']:
        if ipaddress.ip_network(source, strict=False).version != 4:
            raise RuntimeError('firewall.input_invalid')
    # UFW needs one host-level transaction for numbered deletes and insertion.
    with transaction_lock():
        print(json.dumps(converge(intent)))


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, OSError) as error:
        code = str(error) if isinstance(error, RuntimeError) else 'firewall.input_invalid'
        print(json.dumps({'error_code': code}), file=sys.stderr)
        sys.exit(1)
