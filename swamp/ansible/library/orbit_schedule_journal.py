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
# JSON can escape each message byte as six ASCII bytes; framing adds a fixed allowance.
RECORD_LIMIT = 6 * LIMIT + 1024


def format_entry(record):
    """Use the journal's UTC timestamp and keep every line of one message together."""
    timestamp = datetime.datetime.fromtimestamp(int(record['__REALTIME_TIMESTAMP']) / 1_000_000, datetime.UTC)
    identifier = record.get('SYSLOG_IDENTIFIER') or record.get('_COMM') or 'unknown'
    pid = record.get('SYSLOG_PID') or record.get('_PID')
    if pid:
        identifier += f'[{pid}]'
    message = record.get('MESSAGE', '')
    if isinstance(message, list):
        message = bytes(message).decode('utf-8', errors='replace')
    message = message.replace('\n', '\n    ')
    return f"{timestamp:%Y-%m-%dT%H:%M:%S%z} {record.get('_HOSTNAME', 'unknown')} {identifier}: {message}\n"


def read_entries(executable, schedule_id, lines):
    argv = [executable, f'_SYSTEMD_UNIT=orbit-schedule-{schedule_id}.service', '+',
            f'SYSLOG_IDENTIFIER=orbit-schedule-{schedule_id}', '--lines', str(lines), '--reverse',
            '--output=json', '--output-fields=__REALTIME_TIMESTAMP,_HOSTNAME,SYSLOG_IDENTIFIER,_COMM,SYSLOG_PID,_PID,MESSAGE',
            '--no-pager', '--quiet']
    entries = []
    size = 0
    truncated = False
    process = subprocess.Popen(argv, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        while True:
            # One JSON line is one entry, including a multiline message. Never accumulate an unbounded entry.
            raw = process.stdout.readline(RECORD_LIMIT + 1)
            if not raw:
                break
            if len(raw) > RECORD_LIMIT or not raw.endswith(b'\n'):
                truncated = True
                break
            entry = format_entry(json.loads(raw))
            entry_size = len(entry.encode('utf-8'))
            if size + entry_size > LIMIT:
                truncated = True
                break
            entries.append(entry)
            size += entry_size
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


def main():
    module = AnsibleModule(argument_spec={
        'schedule_id': {'type': 'str', 'required': True},
        'lines': {'type': 'int', 'required': True},
        'journalctl': {'type': 'path', 'default': '/usr/bin/journalctl'},
    }, supports_check_mode=True)
    try:
        output, truncated = read_entries(module.params['journalctl'], module.params['schedule_id'], module.params['lines'])
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        module.fail_json(msg='The Schedule journal could not be read.')
    module.exit_json(changed=False, output=output, truncated=truncated)


if __name__ == '__main__':
    main()
