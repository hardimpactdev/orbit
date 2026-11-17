"""Root's rename of the app user and its group in an older container (docs/reference/development-containers.md#the-app-users-name).

An older image made the user `orbit` (uid 1000) with the group `orbit` (gid 1000), and the group `work` (gid 2760). The app user is now
`web`, uid 1000, with the primary group `web`, gid 2760 (ADR 0268, second amendment). Only the names change; every number but the group
1000 stays. The container phase runs this as root as soon as a container runs ("Name the app user web").

Each step checks its own result first, so a run that stopped half way finishes at the next one, and a renamed container changes nothing:

1. Stop each unit whose file under /etc/systemd/system names the old user, group or home, and its timer. Those are what uid 1000 runs:
   `usermod` refuses to rename a user with a process, and names it.
2. `usermod -l web -d /home/web -m orbit`, `groupmod -n web work`, `usermod -g web web`.
3. Give each entry with gid 1000 in the workspace and in /home/web the group `web`, without following a link, then remove the group 1000.
4. Write `User=web`, `Group=web` and `/home/web` in those unit files, reload systemd and start again each unit that step 1 stopped,
   and each enabled one, which an earlier run may have left stopped.

It prints one JSON object: `changed`, and the `steps` it ran. Arguments: the workspace, and optionally the root of the container's files
(`/` when absent), which the test sets; the commands always name the container's own paths.
"""
import json
import os
import re
import subprocess
import sys

USER, GROUP, HOME = 'web', 'web', '/home/web'
OLD_USER, OLD_GROUP, OLD_HOME = 'orbit', 'work', '/home/orbit'
UID, GID, OLD_GID = 1000, 2760, 1000
UNITS = '/etc/systemd/system'
# A line of a unit file that names the old account: its user or group, or a path below the old home.
OLD_LINE = re.compile(r'^(User=orbit|Group=(?:orbit|work))\s*$|/home/orbit(?=[/\s"\']|$)', re.MULTILINE)


def run(*argv, check=True):
    return subprocess.run(list(argv), capture_output=True, text=True, check=check)


def entries(root, name):
    """name -> fields of /etc/passwd or /etc/group."""
    with open(f'{root}etc/{name}', encoding='utf-8') as table:
        return {fields[0]: fields for fields in (line.rstrip('\n').split(':') for line in table) if len(fields) > 2}


def unit_files(root):
    """The unit files and drop-ins that name the old account, by the unit they belong to."""
    found, folder = {}, f'{root}{UNITS.lstrip("/")}'
    for name in sorted(os.listdir(folder)):
        path = os.path.join(folder, name)
        if name.endswith('.d') and os.path.isdir(path) and not os.path.islink(path):
            paths = [os.path.join(path, part) for part in sorted(os.listdir(path)) if part.endswith('.conf')]
            unit = name[:-2]
        else:
            paths, unit = [path], name
        for file in paths:
            if os.path.isfile(file) and not os.path.islink(file):
                with open(file, encoding='utf-8') as unit_file:
                    if OLD_LINE.search(unit_file.read()):
                        found.setdefault(unit, []).append(file)
    return found


def rewrite(file):
    with open(file, encoding='utf-8') as unit_file:
        text = unit_file.read()
    text = re.sub(r'^User=orbit\s*$', f'User={USER}', text, flags=re.MULTILINE)
    text = re.sub(r'^Group=(?:orbit|work)\s*$', f'Group={GROUP}', text, flags=re.MULTILINE)
    text = re.sub(r'/home/orbit(?=[/\s"\']|$)', HOME, text, flags=re.MULTILINE)
    status = os.stat(file)
    temporary = f'{file}.orbit-web'
    with open(temporary, 'w', encoding='utf-8') as unit_file:
        unit_file.write(text)
    os.chmod(temporary, status.st_mode & 0o7777)
    os.replace(temporary, file)


def states(units):
    """unit -> (ActiveState, UnitFileState), as systemd reports them."""
    if not units:
        return {}
    shown = run('systemctl', 'show', '--property=Id,ActiveState,UnitFileState', '--', *units).stdout
    found = {}
    for block in shown.strip().split('\n\n'):
        values = dict(line.split('=', 1) for line in block.splitlines() if '=' in line)
        if 'Id' in values:
            found[values['Id']] = (values.get('ActiveState', ''), values.get('UnitFileState', ''))
    return found


def main(workspace, root='/'):
    root = root.rstrip('/') + '/'
    users, groups, units = entries(root, 'passwd'), entries(root, 'group'), unit_files(root)
    old_user = OLD_USER in users and users[OLD_USER][2] == str(UID)
    old_group = OLD_GROUP in groups and groups[OLD_GROUP][2] == str(GID)
    primary = USER in users and users[USER][3] != str(GID)
    gid_1000 = next((name for name, fields in groups.items() if fields[2] == str(OLD_GID)), None)
    if not (old_user or old_group or primary or gid_1000 or units):
        print(json.dumps({'changed': False, 'steps': []}))
        return
    steps = []
    # The units and their timers: a timer that fires while the user is renamed would start a run under the old name.
    named = sorted(set(units) | {unit[:-len('.service')] + '.timer' for unit in units if unit.endswith('.service')
                                 and os.path.exists(f'{root}{UNITS.lstrip("/")}/{unit[:-len(".service")]}.timer')})
    found = states(named)
    stopped = [unit for unit in named if found.get(unit, ('', ''))[0] in ('active', 'activating', 'reloading')]
    # A unit that an earlier run stopped and did not start again, or that failed under a name that is gone, starts again when enabled.
    again = [unit for unit in named if unit in stopped or found.get(unit, ('', ''))[1] == 'enabled']
    if stopped:
        run('systemctl', 'stop', '--', *stopped)
        steps.append('stop')
    if old_user:
        # `usermod` refuses a user that still runs a process; it names it, and the next start renames again.
        run('usermod', '--login', USER, '--home', HOME, '--move-home', OLD_USER)
        steps.append('user')
    if old_group:
        run('groupmod', '--new-name', GROUP, OLD_GROUP)
        steps.append('group')
    if old_user or old_group or primary:
        run('usermod', '--gid', GROUP, USER)
        steps.append('primary')
    if gid_1000:
        # `chown -R` follows no link by default, and `-h` changes a link itself, never its target.
        run('chown', '--recursive', '--no-dereference', f'--from=:{OLD_GID}', f':{GID}', '--', workspace, HOME)
        run('groupdel', gid_1000)
        steps.append('regroup')
    if units:
        for files in units.values():
            for file in files:
                rewrite(file)
        run('systemctl', 'daemon-reload')
        steps.append('units')
    if again:
        run('systemctl', 'start', '--', *again)
        steps.append('start')
    print(json.dumps({'changed': True, 'steps': steps}))


if __name__ == '__main__':
    main(*sys.argv[1:])
