"""Root's rename of the app user in an older container (swamp/ansible/instance_app_user.py, ADR 0268 second amendment).

The program runs against a scratch copy of a container's account tables and unit files. Stand-ins of the account and systemd commands
change those copies as the real ones would and record each call, so each case reads the end state and the order of the calls: a fresh
older container, one already renamed, and ones that a run left half way.
"""
import json
import subprocess
import sys
import tempfile
import textwrap
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PROGRAM = (ANSIBLE / 'instance_app_user.py').read_text()
PHASE = yaml.safe_load((ANSIBLE / 'tasks/instance-container-phase.yml').read_text())

# One stand-in for every command the program runs, by the name it is called with. It edits the copies under ROOT and appends its argv.
STAND_IN = textwrap.dedent('''\
    import json, os, shutil, sys
    root, name, args = os.environ['ROOT'], sys.argv[1], sys.argv[2:]
    with open(f'{root}/calls', 'a') as calls:
        calls.write(json.dumps([name, *args]) + '\\n')
    def table(file):
        with open(f'{root}/etc/{file}') as lines:
            return [line.rstrip('\\n').split(':') for line in lines if line.strip()]
    def save(file, rows):
        with open(f'{root}/etc/{file}', 'w') as lines:
            lines.write(''.join(':'.join(row) + '\\n' for row in rows))
    def state():
        with open(f'{root}/units.json') as units:
            return json.load(units)
    users, groups = table('passwd'), table('group')
    if name == 'usermod' and args[0] == '--login':
        new, home, old = args[1], args[3], args[5]
        for row in users:
            if row[0] == old:
                os.rename(f'{root}{row[5]}', f'{root}{home}')
                row[0], row[5] = new, home
        for row in groups:
            row[3] = ','.join(new if member == old else member for member in row[3].split(',') if member)
        save('passwd', users), save('group', groups)
    elif name == 'usermod' and args[0] == '--gid':
        gid = next(row[2] for row in groups if row[0] == args[1])
        for row in users:
            if row[0] == args[2]:
                row[3] = gid
        save('passwd', users)
    elif name == 'groupmod':
        for row in groups:
            if row[0] == args[2]:
                row[0] = args[1]
        save('group', groups)
    elif name == 'groupdel':
        gid = next(row[2] for row in groups if row[0] == args[0])
        if any(row[3] == gid for row in users):
            sys.exit(f'groupdel: cannot remove the primary group of user')
        save('group', [row for row in groups if row[0] != args[0]])
    elif name == 'systemctl' and args[0] == 'show':
        units = state()
        print('\\n\\n'.join(f'Id={unit}\\nActiveState={units.get(unit, ["inactive", "disabled"])[0]}\\n'
                           f'UnitFileState={units.get(unit, ["inactive", "disabled"])[1]}' for unit in args[3:]))
    elif name == 'systemctl' and args[0] in ('stop', 'start'):
        units = state()
        for unit in args[2:]:
            units[unit][0] = 'inactive' if args[0] == 'stop' else 'active'
        with open(f'{root}/units.json', 'w') as file:
            json.dump(units, file)
    ''')

OLD_UNIT = textwrap.dedent('''\
    [Service]
    Type=simple
    User=orbit
    Group=orbit
    UMask=0007
    WorkingDirectory=/srv/workspace
    Environment=HOME=/home/orbit
    Environment=PATH=/opt/orbit-runtime/current/bin:/usr/bin
    ExecStart=/opt/orbit-runtime/current/php/8.5/bin/frankenphp run
    ''')
T3 = '[Service]\nUser=agent\nGroup=agent\nWorkingDirectory=/home/agent\nEnvironment=HOME=/home/agent\n'
# What a container of an older image has, and what a renamed one has.
OLD_PASSWD = 'root:x:0:0:root:/root:/bin/bash\nagent:x:2750:2750::/home/agent:/bin/bash\norbit:x:1000:1000::/home/orbit:/bin/bash\n'
OLD_GROUP = 'root:x:0:\nagent:x:2750:\norbit:x:1000:\nwork:x:2760:agent,orbit\n'
NEW_PASSWD = 'root:x:0:0:root:/root:/bin/bash\nagent:x:2750:2750::/home/agent:/bin/bash\nweb:x:1000:2760::/home/web:/bin/bash\n'
NEW_GROUP = 'root:x:0:\nagent:x:2750:\nweb:x:2760:agent\n'
# `usermod -l` also renames the user in the member lists, so a renamed container lists `web` in its own primary group too.
SWAPPED_GROUP = 'root:x:0:\nagent:x:2750:\nweb:x:2760:agent,web\n'
NEW_UNIT = OLD_UNIT.replace('User=orbit', 'User=web').replace('Group=orbit', 'Group=web').replace('/home/orbit', '/home/web')


class Container:
    def __init__(self, passwd, group, units, home='orbit'):
        self.folder = tempfile.TemporaryDirectory()
        self.root = Path(self.folder.name)
        for folder in ('etc/systemd/system/orbit-process-5.service.d', 'bin', f'home/{home}', 'home/agent', 'srv/workspace'):
            (self.root / folder).mkdir(parents=True)
        (self.root / 'etc/passwd').write_text(passwd)
        (self.root / 'etc/group').write_text(group)
        (self.root / 'units.json').write_text(json.dumps({unit: list(state) for unit, (_, state) in units.items()}))
        for unit, (content, _) in units.items():
            if content is not None:
                (self.root / 'etc/systemd/system' / unit).write_text(content)
                (self.root / 'etc/systemd/system' / unit).chmod(0o640)
        (self.root / 'stand_in.py').write_text(STAND_IN)
        # Each stand-in is a small shell script that runs the shared program with its own name.
        for name in ('usermod', 'groupmod', 'groupdel', 'systemctl', 'chown'):
            (self.root / 'bin' / name).write_text(f'#!/bin/sh\nexec {sys.executable} -I {self.root}/stand_in.py {name} "$@"\n')
            (self.root / 'bin' / name).chmod(0o755)

    def run(self):
        env = {'PATH': f'{self.root}/bin:/usr/bin:/bin', 'ROOT': str(self.root)}
        done = subprocess.run([sys.executable, '-I', '-c', PROGRAM, '/srv/workspace', str(self.root)], capture_output=True, text=True,
                              env=env, timeout=60, check=False)
        if done.returncode != 0:
            raise AssertionError(done.stderr)
        return json.loads(done.stdout)

    def calls(self):
        path = self.root / 'calls'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def unit(self, name):
        return (self.root / 'etc/systemd/system' / name).read_text()

    def states(self):
        return json.loads((self.root / 'units.json').read_text())

    def close(self):
        self.folder.cleanup()


def older_units():
    return {
        'orbit-frankenphp.service': (OLD_UNIT, ('active', 'enabled')),
        'orbit-process-5.service': ('[Service]\nUser=orbit\nGroup=orbit\nExecStart=/bin/true\n', ('active', 'enabled')),
        'orbit-process-6.service': ('[Service]\nUser=orbit\nGroup=orbit\nExecStart=/bin/true\n', ('inactive', 'disabled')),
        'orbit-schedule-9.service': ('[Service]\nUser=orbit\nGroup=orbit\nEnvironment=HOME=/home/orbit\n', ('inactive', 'static')),
        'orbit-schedule-9.timer': ('[Timer]\nOnCalendar=hourly\n', ('active', 'enabled')),
        't3.service': (T3, ('active', 'enabled')),
    }


class RenameTest(unittest.TestCase):
    def container(self, *args, **kwargs):
        container = Container(*args, **kwargs)
        self.addCleanup(container.close)
        return container

    def test_an_older_container_gets_web_in_the_group_web_and_its_units_run_as_web_again(self):
        container = self.container(OLD_PASSWD, OLD_GROUP, older_units())
        drop_in = container.root / 'etc/systemd/system/orbit-process-5.service.d/10-env.conf'
        drop_in.write_text('[Service]\nEnvironment=COMPOSER_HOME=/home/orbit/.composer\n')

        self.assertEqual(container.run(), {'changed': True, 'steps': ['stop', 'user', 'group', 'primary', 'regroup', 'units', 'start']})

        self.assertEqual((container.root / 'etc/passwd').read_text(), NEW_PASSWD)
        self.assertEqual((container.root / 'etc/group').read_text(), SWAPPED_GROUP)
        self.assertTrue((container.root / 'home/web').is_dir())
        self.assertFalse((container.root / 'home/orbit').exists())
        self.assertEqual(container.unit('orbit-frankenphp.service'), NEW_UNIT)
        self.assertEqual(container.unit('orbit-schedule-9.service'), '[Service]\nUser=web\nGroup=web\nEnvironment=HOME=/home/web\n')
        self.assertEqual(drop_in.read_text(), '[Service]\nEnvironment=COMPOSER_HOME=/home/web/.composer\n')
        self.assertEqual(oct((container.root / 'etc/systemd/system/orbit-frankenphp.service').stat().st_mode & 0o777), '0o640')
        # `agent`'s T3 is not the app's: it keeps running and its file stays as it was.
        self.assertEqual(container.unit('t3.service'), T3)
        calls = container.calls()
        names = [call[:2] for call in calls]
        # The running units and the Schedule's timer stop before the rename and start after it; a stopped, disabled Process stays stopped.
        stop = ['systemctl', 'stop', '--', 'orbit-frankenphp.service', 'orbit-process-5.service', 'orbit-schedule-9.timer']
        self.assertEqual(calls[1], stop)
        self.assertLess(calls.index(stop), names.index(['usermod', '--login']))
        self.assertIn(['usermod', '--login', 'web', '--home', '/home/web', '--move-home', 'orbit'], calls)
        self.assertIn(['groupmod', '--new-name', 'web', 'work'], calls)
        self.assertIn(['usermod', '--gid', 'web', 'web'], calls)
        chown = ['chown', '--recursive', '--no-dereference', '--from=:1000', ':2760', '--', '/srv/workspace', '/home/web']
        self.assertLess(calls.index(chown), calls.index(['groupdel', 'orbit']))
        self.assertLess(calls.index(['systemctl', 'daemon-reload']), names.index(['systemctl', 'start']))
        self.assertEqual(calls[-1], ['systemctl', 'start', '--', 'orbit-frankenphp.service', 'orbit-process-5.service', 'orbit-schedule-9.timer'])
        self.assertEqual(container.states()['orbit-process-6.service'], ['inactive', 'disabled'])

        # A second start finds a renamed container and changes nothing.
        before = len(calls)
        self.assertEqual(container.run(), {'changed': False, 'steps': []})
        self.assertEqual(container.calls()[before:], [])

    def test_a_container_of_the_new_image_changes_nothing(self):
        container = self.container(NEW_PASSWD, NEW_GROUP, {'orbit-frankenphp.service': (NEW_UNIT, ('active', 'enabled')),
                                                           't3.service': (T3, ('active', 'enabled'))}, home='web')

        self.assertEqual(container.run(), {'changed': False, 'steps': []})
        self.assertEqual(container.calls(), [])
        self.assertEqual(container.unit('orbit-frankenphp.service'), NEW_UNIT)

    def test_a_run_that_stopped_after_the_user_finishes_the_group_the_files_and_the_units(self):
        # The user is renamed and moved, but `work` and the group 1000 are left, and the units still name `orbit`: they failed at boot.
        passwd = OLD_PASSWD.replace('orbit:x:1000:1000::/home/orbit', 'web:x:1000:1000::/home/web')
        group = OLD_GROUP.replace('agent,orbit', 'agent,web')
        units = older_units()
        units['orbit-frankenphp.service'] = (OLD_UNIT, ('failed', 'enabled'))
        units['orbit-process-5.service'] = (units['orbit-process-5.service'][0], ('failed', 'enabled'))
        units['orbit-schedule-9.timer'] = (units['orbit-schedule-9.timer'][0], ('inactive', 'enabled'))
        container = self.container(passwd, group, units, home='web')

        self.assertEqual(container.run(), {'changed': True, 'steps': ['group', 'primary', 'regroup', 'units', 'start']})

        self.assertEqual((container.root / 'etc/passwd').read_text(), NEW_PASSWD)
        self.assertEqual((container.root / 'etc/group').read_text(), SWAPPED_GROUP)
        self.assertEqual(container.unit('orbit-frankenphp.service'), NEW_UNIT)
        # Nothing runs as uid 1000 any more and the user has its name: no stop and no second rename.
        self.assertNotIn(['systemctl', 'stop'], [call[:2] for call in container.calls()])
        self.assertNotIn(['usermod', '--login'], [call[:2] for call in container.calls()])
        self.assertEqual(container.calls()[-1],
                         ['systemctl', 'start', '--', 'orbit-frankenphp.service', 'orbit-process-5.service', 'orbit-schedule-9.timer'])

    def test_a_run_that_stopped_after_the_group_1000_rewrites_and_starts_only_the_units(self):
        # Every account is renamed, but the units still name `orbit` and stay stopped from that run.
        units = {name: (content, ('inactive', state[1])) for name, (content, state) in older_units().items() if name != 't3.service'}
        container = self.container(NEW_PASSWD, NEW_GROUP, units, home='web')

        self.assertEqual(container.run(), {'changed': True, 'steps': ['units', 'start']})

        self.assertEqual(container.unit('orbit-frankenphp.service'), NEW_UNIT)
        self.assertEqual([call[:2] for call in container.calls()],
                         [['systemctl', 'show'], ['systemctl', 'daemon-reload'], ['systemctl', 'start']])
        self.assertEqual(container.calls()[-1], ['systemctl', 'start', '--', 'orbit-frankenphp.service', 'orbit-process-5.service',
                                                 'orbit-schedule-9.timer'])

    def test_the_container_phase_renames_right_after_the_start_in_every_phase_that_boots(self):
        names = [task.get('name') for task in PHASE]
        task = PHASE[names.index('Name the app user web')]
        self.assertEqual(names.index('Name the app user web'), names.index('Start the container') + 1)
        self.assertEqual(task['tags'], ['container', 'clone', 'start'])
        self.assertEqual(task['when'], "phase in ['container', 'clone', 'start'] and not start_skips_boot | default(false) | bool")
        self.assertIn("as_root + ['python3', '-I', '-c', lookup('ansible.builtin.file', playbook_dir ~ '/instance_app_user.py'), "
                      'container.workspace]', task['ansible.builtin.command']['argv'])


if __name__ == '__main__':
    unittest.main()
