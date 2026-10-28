"""Shared systemd state and empty-cgroup proof for task monitors. Embedded before transport."""
import subprocess


def unit_state(unit_name):
    properties = ['LoadState', 'ActiveState', 'SubState', 'ControlGroup', 'Description', 'MainPID', 'ExecMainCode', 'ExecMainStatus', 'Job']
    result = subprocess.run(['systemctl', 'show', unit_name, '--no-pager',
                             '--property=' + ','.join(properties)], capture_output=True, text=True)
    if result.returncode != 0:
        return None
    return dict(line.split('=', 1) for line in result.stdout.splitlines() if '=' in line)


def vanished(state):
    # No unit file, inactive, no cgroup and no queued transition together prove no processes remain.
    return (state is not None and state.get('LoadState') == 'not-found' and state.get('ActiveState') == 'inactive'
            and state.get('ControlGroup') == '' and state.get('Job') == '')


def empty_terminal(unit_name, expected_description=None, state=None):
    state = unit_state(unit_name) if state is None else state
    if state is None:
        return None
    if state.get('LoadState') != 'loaded' or (expected_description is not None and state.get('Description') != expected_description):
        return None
    # Job is empty only when the manager has no pending transition. Missing evidence is not proof.
    if state.get('Job') != '':
        return None
    if not ((state.get('ActiveState') == 'active' and state.get('SubState') == 'exited')
            or state.get('ActiveState') in ('inactive', 'failed')):
        return None
    group = state.get('ControlGroup')
    if group:
        if not group.startswith('/') or '..' in group.split('/'):
            return None
        try:
            with open('/sys/fs/cgroup' + group + '/cgroup.events') as source:
                events = dict(line.split() for line in source)
            if events.get('populated') != '0':
                return None
        except FileNotFoundError:
            pass
        except OSError:
            return None
    return state

