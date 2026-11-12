#!/usr/bin/python
"""Bound complete journal entries on the Node, before Ansible transfers its result."""
import datetime
import json
import subprocess

from ansible.module_utils.basic import AnsibleModule

DOCUMENTATION = r'''
module: orbit_schedule_journal
short_description: Read complete Schedule journal entries within a UTF-8 byte budget
version_added: "1.0.0"
description: Stream newest journal entries and stop before transferring more than the byte budget.
options:
  schedule_id:
    description: The admitted Schedule UUID.
    type: str
    required: true
  lines:
    description: Maximum number of journal entries.
    type: int
    required: true
  journalctl:
    description: The journal reader executable.
    type: path
    default: /usr/bin/journalctl
  incus:
    description: The Node's Incus executable.
    type: path
    default: /usr/bin/incus
  container:
    description: The recorded container identity; absent for retained host journal reads.
    type: dict
    suboptions:
      project:
        description: Incus project of the Instance.
        type: str
        required: true
      name:
        description: Recorded Instance container name.
        type: str
        required: true
author: Orbit
'''
EXAMPLES = ''
RETURN = r'''
output:
  description: Complete entries in time order, at most 4 MiB of UTF-8 bytes.
  type: str
  returned: always
truncated:
  description: Whether the byte budget excluded an entry.
  type: bool
  returned: always
'''
LIMIT = 4 * 1024 * 1024
TRANSPORT_LIMIT = 8 * 1024 * 1024 - 16 * 1024
# JSON can escape each message byte as six ASCII bytes; framing adds a fixed allowance.
RECORD_LIMIT = 6 * LIMIT + 1024


def format_entry(record):
    """Use the journal's UTC timestamp and keep every line of one message together."""
    timestamp = datetime.datetime.fromtimestamp(int(record['__REALTIME_TIMESTAMP']) / 1_000_000, datetime.UTC)
    identifier = record.get('SYSLOG_IDENTIFIER') or record.get('_COMM') or 'unknown'
    pid = record.get('SYSLOG_PID') or record.get('_PID')
    if pid:
        identifier += f'[{pid}]'
    message = record.get('MESSAGE') or ''
    if isinstance(message, list):
        message = bytes(message).decode('utf-8', errors='replace')
    message = message.replace('\n', '\n    ')
    return f"{timestamp:%Y-%m-%dT%H:%M:%S%z} {record.get('_HOSTNAME', 'unknown')} {identifier}: {message}\n"


def read_entries(executable, schedule_id, lines, prefix=None):
    argv = (prefix or []) + [executable, f'_SYSTEMD_UNIT=orbit-schedule-{schedule_id}.service', '+',
            f'SYSLOG_IDENTIFIER=orbit-schedule-{schedule_id}', '--lines', str(lines), '--reverse',
            '--output=json', '--all', '--output-fields=__REALTIME_TIMESTAMP,_HOSTNAME,SYSLOG_IDENTIFIER,_COMM,SYSLOG_PID,_PID,MESSAGE',
            '--no-pager', '--quiet']
    entries = []
    size = 0
    transport_size = len(json.dumps(json.dumps({'state': 'active', 'output': '', 'truncated': False},
                                               ensure_ascii=False), ensure_ascii=False).encode('utf-8'))
    truncated = False
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        for raw in iter(lambda: process.stdout.readline(RECORD_LIMIT + 1), b''):
            # One JSON line is one entry, including a multiline message. Never accumulate an unbounded entry.
            if len(raw) > RECORD_LIMIT or not raw.endswith(b'\n'):
                truncated = True
                break
            entry = format_entry(json.loads(raw))
            entry_size = len(entry.encode('utf-8'))
            # The inspection JSON is itself a string in vault.read-secret's JSON response.
            # Subtract the two encodings' quotes; the remaining content costs add across whole entries.
            entry_transport_size = len(json.dumps(json.dumps(entry, ensure_ascii=False), ensure_ascii=False).encode('utf-8')) - 6
            if size + entry_size > LIMIT or transport_size + entry_transport_size > TRANSPORT_LIMIT:
                truncated = True
                break
            entries.append(entry)
            size += entry_size
            transport_size += entry_transport_size
        if not truncated and process.wait(timeout=5) != 0:
            raise ValueError('The Schedule journal could not be read.')
    finally:
        process.stdout.close()
        if process.poll() is None:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=5)
    return ''.join(reversed(entries)), truncated



def container_observation(incus, container, schedule_id):
    """A stopped container is inspected, never started. Keep errors and service output private."""
    raw = subprocess.run([incus, 'query', '--', '/1.0/instances/' + container['name'] + '?project=' + container['project']],
                         check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5)
    if json.loads(raw.stdout)['status'] != 'Running':
        return {'state': 'inactive', 'last_run_at': None, 'last_run_status': None,
                'error_code': 'schedule.target_inactive'}, []
    prefix = [incus, 'exec', '--project', container['project'], '--env', 'LC_ALL=C', '--env', 'TZ=UTC', container['name'], '--']
    timer = subprocess.run(prefix + ['systemctl', 'show', '--property=ActiveState', '--value', '--',
                                    'orbit-schedule-' + schedule_id + '.timer'],
                           check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5)
    raw = subprocess.run(prefix + ['systemctl', 'show', '--property=Result', '--property=ExecMainExitTimestamp',
                                  '--property=ActiveState', '--', 'orbit-schedule-' + schedule_id + '.service'],
                         check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5)
    properties = dict(line.split('=', 1) for line in raw.stdout.decode().splitlines() if '=' in line)
    timestamp = properties.get('ExecMainExitTimestamp', '')
    last_run = None
    if timestamp and timestamp != 'n/a':
        last_run = datetime.datetime.strptime(timestamp, '%a %Y-%m-%d %H:%M:%S %Z').replace(tzinfo=datetime.UTC).isoformat()
    return {'state': timer.stdout.decode().strip() or 'unknown', 'last_run_at': last_run,
            'last_run_status': ('success' if properties.get('Result') == 'success' else 'error') if last_run else None}, prefix

def main():
    module = AnsibleModule(argument_spec={
        'schedule_id': {'type': 'str', 'required': True},
        'lines': {'type': 'int', 'required': True},
        'journalctl': {'type': 'path', 'default': '/usr/bin/journalctl'},
        'incus': {'type': 'path', 'default': '/usr/bin/incus'},
        'container': {'type': 'dict', 'default': None, 'options': {
            'project': {'type': 'str', 'required': True}, 'name': {'type': 'str', 'required': True}}},
    }, supports_check_mode=True)
    try:
        observed, prefix = ({'state': 'active'}, [])
        if module.params['container'] is not None:
            observed, prefix = container_observation(module.params['incus'], module.params['container'], module.params['schedule_id'])
        output, truncated = ('', False) if 'error_code' in observed else read_entries(
            module.params['journalctl'], module.params['schedule_id'], module.params['lines'], prefix)
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        module.fail_json(msg='The Schedule journal could not be read.')
    module.exit_json(changed=False, output=output, truncated=truncated, observation=observed)


if __name__ == '__main__':
    main()
