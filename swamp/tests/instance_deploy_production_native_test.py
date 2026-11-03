"""Production instance.deploy on pinned Swamp with unmodified official SSH and cfgmgmt; the Node is a scratch home."""

import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
import time
import uuid
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase

WORKFLOW = 'operation-instance-deploy-direct'
CHILDREN = ('operation-process-create', 'operation-process-destroy', 'operation-process-vite', 'operation-tool-manager-setup')


class ProductionDeployNative(NativeTestCase):
    native_timeout = 240

    def git(self, cwd, *args):
        return subprocess.run(['git', '-C', str(cwd), *args], check=True, capture_output=True, text=True).stdout.strip()

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations')
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        for package in ['@swamp/ssh', '@swamp/cfgmgmt']:
            self.cli('extension', 'pull', f"{package}@{inventory[package]['version']}")
        # The Node's home lives outside the native repository, whose files must hold no private bytes.
        self.node = Path(tempfile.mkdtemp(prefix='orbit-deploy-node-'))
        self.addCleanup(shutil.rmtree, self.node, True)
        (self.node / 'etc/systemd/system').mkdir(parents=True)
        self.home = self.node / 'home'
        (self.home / 'releases/initial/public').mkdir(parents=True)
        (self.home / 'releases/old').mkdir()
        (self.home / 'current').symlink_to('releases/initial')
        (self.home / '.env').write_text('APP_KEY=old\n')
        (self.home / 'storage/app').mkdir(parents=True)
        (self.home / 'storage/app/kept.txt').write_text('kept\n')
        # The origin: main has two commits; the side branch's commit is not on main's first-parent chain.
        self.origin = self.node / 'origin'
        self.git(self.node, 'init', '--quiet', '--initial-branch=main', str(self.origin))
        for key, value in (('user.name', 'proof'), ('user.email', 'proof@example.test')):
            self.git(self.origin, 'config', key, value)
        (self.origin / 'public').mkdir()
        (self.origin / 'public/index.php').write_text('<?php echo 1;\n')
        (self.origin / 'storage/app').mkdir(parents=True)
        (self.origin / 'storage/app/kept.txt').write_text('from the release\n')
        (self.origin / 'storage/app/new.txt').write_text('new\n')
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '--quiet', '-m', 'one')
        self.first = self.git(self.origin, 'rev-parse', 'HEAD')
        self.git(self.origin, 'checkout', '--quiet', '-b', 'side')
        (self.origin / 'side.txt').write_text('side\n')
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '--quiet', '-m', 'side')
        self.side = self.git(self.origin, 'rev-parse', 'HEAD')
        self.git(self.origin, 'checkout', '--quiet', 'main')
        (self.origin / 'README').write_text('two\n')
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '--quiet', '-m', 'two')
        self.head = self.git(self.origin, 'rev-parse', 'HEAD')

        self.calls_file = self.repo / 'calls.jsonl'
        tools = self.repo / 'tools'
        tools.mkdir()
        self.native_env['PATH'] = str(tools) + ':' + os.environ['PATH']
        scripts = {
            # Runs the remote command locally; sudo, absolute or not, goes to the fixture below.
            'ssh': """import re,subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
sys.exit(subprocess.call(['/bin/sh','-c',re.sub(r"(?<![\\w/])'?(/usr/bin/)?sudo'?(?= )",SUDO,sys.argv[-1].replace('/etc/systemd/system/',SYSTEMD))]))
""".replace('SUDO', repr(str(tools / 'sudo'))).replace('SYSTEMD', repr(str(self.node / 'etc/systemd/system') + '/')),
            'scp': """import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
""",
            # Drops sudo's own options and a leading timeout; records the Node's service, ACL and container commands.
            'sudo': """import json,os,subprocess,sys
a=sys.argv[1:]
while a and a[0].startswith('-') and a[0]!='--':
    a=a[2:] if a[0] in ('-u','--user','-g') else a[1:]
if a and a[0]=='--':a=a[1:]
if a and os.path.basename(a[0])=='timeout':a=a[2:]
if a[:2]==['getent','passwd']:
    print(a[2]+':x:1000:1000::'+MANAGER+':/bin/bash');sys.exit(0)
a=[part.replace('/opt/orbit/vite-plus/bin/vp',PUBLIC_VP) for part in a]
if a and a[0]=='install':
    a[a.index('-o')+1]=str(os.getuid());a[a.index('-g')+1]=str(os.getgid())
if a and os.path.basename(a[0])=='find' and a[1]=='/proc':
    if os.path.exists(FAIL) and open(FAIL).read()=='find':sys.exit(1)
    from pathlib import Path
    wanted=a[a.index('-lname')+1]
    for proc in Path('/proc').glob('[0-9]*'):
        try:
            if proc.stat().st_uid!=os.getuid():continue
            for name in ('cwd','root','exe'):
                try:value=os.readlink(proc/name)
                except (FileNotFoundError,PermissionError):continue
                if value==wanted or value.startswith(wanted+'/'):
                    print(proc/name);sys.exit(0)
        except FileNotFoundError:continue
    sys.exit(0)
if a and (os.path.basename(a[0]) in ('systemctl','docker','setfacl') or '/usr/bin/setfacl' in a):
    with open(CALLS,'a') as f:f.write(json.dumps([os.path.basename(a[0]),*a[1:]])+'\\n')
    if os.path.basename(a[0])=='systemctl' and a[1]=='show':print('LoadState=loaded\\nActiveState=active\\nUnitFileState=enabled')
    if os.path.basename(a[0])=='systemctl' and a[1]=='is-active':print('active')
    if os.path.basename(a[0])=='systemctl' and a[1]=='is-enabled':print('enabled')
    sys.exit(1 if os.path.exists(FAIL) and open(FAIL).read() in a else 0)
sys.exit(subprocess.call(a))
""".replace('CALLS', repr(str(self.calls_file))).replace('FAIL', repr(str(self.repo / 'fail'))).replace('MANAGER', repr(str(self.node / 'manager'))).replace('PUBLIC_VP', repr(str(self.node / 'public/bin/vp'))),
            'systemctl': "import json,sys\nif sys.argv[1]=='show': print('LoadState=loaded\\nActiveState=inactive\\nUnitFileState=disabled' if '.timer' in sys.argv[2] else 'LoadState=loaded\\nActiveState=active\\nUnitFileState=enabled')\nelse:\n    with open(" + repr(str(self.calls_file)) + ",'a') as f:f.write(json.dumps(['systemctl',*sys.argv[1:]])+'\\n')\n",
            'chown': 'pass\n',
            'chgrp': 'pass\n',
            'find': "import subprocess,sys\nif sys.argv[1:3]==['/opt','.']:print('/opt/orbit/vite-plus/bin/vp')\nelse:sys.exit(subprocess.call(['/usr/bin/find',*sys.argv[1:]]))\n",
        }
        for name, source in scripts.items():
            tool = tools / name
            tool.write_text('#!' + sys.executable + '\n' + source)
            tool.chmod(0o700)
        for name in (WORKFLOW, *CHILDREN):
            workflow = yaml.safe_load((ROOT / f'workflows/workflow-{name}.yaml').read_text())
            for job in workflow['jobs']:
                job.pop('labels', None)
                for step in job['steps']:
                    step.pop('labels', None)
                    if step['task'].get('behaviorType') == '@swamp/ssh':
                        step['task']['globalArgs'].update(sshBinary=str(tools / 'ssh'), scpBinary=str(tools / 'scp'))
            (self.repo / f'workflows/workflow-{name}.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False, width=1000000))
        (self.repo / 'key').write_text('fixture identity not consumed')
        self.sentinel = 'native-private-' + uuid.uuid4().hex
        self.secrets = (self.sentinel,)
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'instance-deploy-operation-17-credential', 'https://x-access-token:unused@github.com')
        self.cli('vault', 'put', 'orbit-control-inputs', 'instance-environment-17', f'APP_KEY="{self.sentinel}"\n')
        self.user = pwd.getpwuid(os.getuid()).pw_name
        self.release = '20261102230000-op-00000000000000000017'
        self.directory = f'{self.home}/releases/{self.release}'
        git = ['/usr/bin/env', 'GIT_CONFIG_NOSYSTEM=1', 'GIT_CONFIG_GLOBAL=/dev/null', '/usr/bin/git', '-c', 'core.hooksPath=/dev/null']
        self.steps = []
        self.inputs = {'operation_id': 17, 'node_id': 9, 'kind': 'instance.deploy', 'execution_secrets': {
            'credential': {'vault': 'orbit-control-inputs', 'key': 'instance-deploy-operation-17-credential'},
            'environment': {'vault': 'orbit-control-inputs', 'key': 'instance-environment-17'}}, 'intent': {
            'instance_id': 42, 'commit': None, 'application': self.directory, 'vp_setup': None, 'account_home': str(self.node),
            'ssh_execution': {'identity_file': str(self.repo / 'key'), 'target': {'name': 'target', 'user': self.user, 'port': 22, 'host': 'fixture.invalid',
                              'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111']}},
            'deployment': {'variant': 'production', 'needs_vp': False, 'steps': self.steps, 'node_user': self.user, 'branch': 'main',
                           'repository': str(self.origin), 'home': str(self.home)},
            'environment': {'instance_id': 42, 'environment': 'production', 'base': str(self.home), 'user': self.user, 'keys': ['APP_KEY'],
                            'owned_keys': [], 'sha256': '', 'testing': None},
            'process_members': [], 'php_input': None,
            'runtime_definitions': {'schedules': [], 'restart': [{'process_id': 3, 'runtime': 'systemd', 'name': 'orbit-process-3-horizon.service'}]},
            'production': {'instance_id': 42, 'user': self.user, 'home': str(self.home), 'release': self.release, 'release_path': self.directory,
                           'commit': None, 'branch': 'main', 'credential': str(self.node / 'credential'), 'step_file': str(self.node / 'step-'),
                           'prepare': [
                               {'argv': [*git, '-c', 'credential.helper=', '-c', f'credential.helper=store --file={self.node}/credential', '-c', 'protocol.allow=never',
                                         '-c', 'protocol.file.allow=always', 'clone', '--quiet', '--no-checkout', '--origin', 'origin', '--', str(self.origin), self.directory], 'ok': [0]},
                               {'argv': [*git, '-C', self.directory, 'checkout', '--quiet', '--detach', 'refs/remotes/origin/main'], 'ok': [0]},
                               {'argv': ['/usr/bin/mkdir', '-p', '--', f'{self.home}/storage'], 'ok': [0]},
                               {'argv': ['/usr/bin/find', f'{self.home}/storage', '-mindepth', '1', '-maxdepth', '1', '-print', '-quit'], 'ok': [0]},
                               {'argv': ['/usr/bin/test', '-d', f'{self.directory}/storage'], 'ok': [0, 1]},
                               {'argv': ['/usr/bin/cp', '-a', '-T', '--', f'{self.directory}/storage', f'{self.home}/storage'], 'ok': [0], 'empty': 3, 'source': 4},
                               {'argv': ['/usr/bin/rm', '-rf', '--', f'{self.directory}/storage'], 'ok': [0]},
                               {'argv': ['/usr/bin/mkdir', '-p', '--', self.directory], 'ok': [0]},
                               {'argv': ['/usr/bin/mkdir', '-p', '--', f'{self.home}/storage/app/public'], 'ok': [0]}],
                           'links': [{'path': f'{self.directory}/.env', 'target': '../../.env'},
                                     {'path': f'{self.directory}/storage', 'target': f'{self.home}/storage'},
                                     {'path': f'{self.directory}/public/storage', 'target': '../storage/app/public'}],
                           'grants': [['/usr/bin/setfacl', '-m', 'u:caddy:--x', '--', self.directory],
                                      ['/usr/bin/setfacl', '-P', '-R', '-m', 'u:caddy:r-X', '--', f'{self.directory}/public']],
                           'steps': self.steps, 'before': [], 'after': [],
                           'cleanup': [{'name': 'old', 'path': f'{self.home}/releases/old'}]}}}

        preparation = self.inputs['intent']['production']['prepare']
        self.inputs['intent']['production']['shared'] = [dict(action, empty=action['empty']-2, source=action['source']-2) if 'empty' in action else action for action in preparation[2:]]
        self.inputs['intent']['production']['prepare'] = preparation[:2]

    def command(self, command, phase='before_activation'):
        index = len(self.steps)
        key = f'instance-deploy-operation-17-step-{index}'
        step = {'index': index, 'name': f'step-{index}', 'phase': phase, 'timeout_seconds': 10}
        self.steps.append(step)
        self.inputs['intent']['production']['before' if phase == 'before_activation' else 'after'].append(step)
        # The Gateway's quiet first line (ProductionDeployInput::QuietStep) keeps the step's output on the Node.
        self.cli('vault', 'put', 'orbit-control-inputs', key, 'exec >/dev/null 2>&1\n{\n' + command + '\n} </dev/null\n')
        self.inputs['execution_secrets'][f'step_{index}'] = {'vault': 'orbit-control-inputs', 'key': key}

    def deploy(self, success=True):
        self.cli('workflow', 'validate', WORKFLOW)
        reply = self.cli('workflow', 'run', WORKFLOW, '--stdin', input=json.dumps(self.inputs), success=success)
        self.assertNotIn('Invalid expression', reply.stdout + reply.stderr)
        run = json.JSONDecoder().raw_decode(reply.stdout[reply.stdout.index('{'):])[0]
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        self.assert_private_absent(json.dumps(history))
        self.status = {step['name']: step['status'] for job in history['jobs'] for step in job['steps']}
        return history['status']

    def calls(self):
        return [row for line in self.calls_file.read_text().splitlines() if (row := json.loads(line))[1] not in ('show','is-active','is-enabled')] if self.calls_file.exists() else []

    def test_prepares_activates_restarts_and_cleans_up_without_private_output(self):
        self.command(f'test -z "$(cat)"; printf %s {self.sentinel}; printf "$PWD" > {self.node}/before-ran')
        self.command(f'printf %s {self.sentinel}; readlink {self.home}/current > {self.node}/after-saw', 'after_activation')
        before = os.lstat(self.home / 'current').st_ino

        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        release = Path(self.directory)
        self.assertEqual(self.git(release, 'rev-parse', 'HEAD'), self.head)
        # current.next was renamed over current: a new link under the old name, and no current.next left.
        self.assertEqual(os.readlink(self.home / 'current'), f'releases/{self.release}')
        self.assertNotEqual(os.lstat(self.home / 'current').st_ino, before)
        self.assertFalse(os.path.lexists(self.home / 'current.next'))
        self.assertEqual(os.readlink(release / '.env'), '../../.env')
        self.assertEqual(os.readlink(release / 'storage'), f'{self.home}/storage')
        self.assertEqual(os.readlink(release / 'public/storage'), '../storage/app/public')
        # A populated persistent directory keeps its contents.
        self.assertEqual((self.home / 'storage/app/kept.txt').read_text(), 'kept\n')
        self.assertFalse((self.home / 'storage/app/new.txt').exists(), 'an existing persistent directory is not seeded again')
        self.assertEqual((self.home / '.env').read_text(), f'APP_KEY="{self.sentinel}"\n')
        self.assertEqual(oct((self.home / '.env').stat().st_mode & 0o777), '0o600')
        # Before steps run in the new release before the switch; after steps see the new selection.
        self.assertEqual((self.node / 'before-ran').read_text(), self.directory)
        self.assertEqual((self.node / 'after-saw').read_text().strip(), f'releases/{self.release}')
        self.assertEqual(self.calls(), [
            ['setfacl', '-m', 'u:caddy:--x', '--', self.directory],
            ['setfacl', '-P', '-R', '-m', 'u:caddy:r-X', '--', f'{self.directory}/public'],
            ['systemctl', 'reload-or-restart', 'orbit-process-3-horizon.service']])
        # The read token is gone; no command file was copied; the expired release is removed.
        self.assertFalse((self.node / 'credential').exists())
        self.assertEqual(sorted(path.name for path in self.node.glob('step-*')), [])
        self.assertFalse((self.home / 'releases/old').exists())
        self.assertTrue((self.home / 'releases/initial').exists())
        self.assertEqual(self.status['Check the deployment'], 'succeeded')
        self.assertEqual(self.status['Run the deploy steps-0'], 'skipped', 'the development steps skip')

    def test_a_pinned_commit_off_the_first_parent_chain_stops_before_the_env_and_current(self):
        self.inputs['intent']['production']['commit'] = self.side
        self.inputs['intent']['production']['prepare'][1]['argv'][-1] = self.side
        self.assertEqual(self.deploy(success=False), 'failed')
        self.assertEqual(self.status['Check the branch commit'], 'failed')
        self.assertEqual(os.readlink(self.home / 'current'), 'releases/initial')
        self.assertEqual((self.home / '.env').read_text(), 'APP_KEY=old\n')
        self.assertEqual(self.calls(), [])

    def test_a_pinned_commit_on_the_chain_deploys_exactly_that_commit(self):
        self.inputs['intent']['production']['commit'] = self.first
        self.inputs['intent']['production']['prepare'][1]['argv'][-1] = self.first
        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        self.assertEqual(self.git(Path(self.directory), 'rev-parse', 'HEAD'), self.first)
        self.assertEqual(self.status['Check the branch commit'], 'succeeded')

    def test_a_foreign_env_key_refuses_before_the_write(self):
        (self.home / '.env').write_text('APP_KEY=old\nHAND_MADE=1\n')
        self.assertEqual(self.deploy(success=False), 'failed')
        self.assertEqual(self.status['Check for foreign keys'], 'failed')
        self.assertEqual((self.home / '.env').read_text(), 'APP_KEY=old\nHAND_MADE=1\n')
        self.assertEqual(os.readlink(self.home / 'current'), 'releases/initial')

    def test_a_failed_before_step_keeps_the_old_release_selected(self):
        self.command('exit 4')
        self.command(f'touch {self.node}/second-ran')
        self.assertEqual(self.deploy(success=False), 'failed')
        self.assertEqual((self.status['Run before steps-0'], self.status['Run before steps-1']), ('failed', 'skipped'))
        self.assertEqual(self.status['Swap current'], 'skipped')
        self.assertEqual(os.readlink(self.home / 'current'), 'releases/initial')
        self.assertFalse((self.node / 'second-ran').exists())
        self.assertTrue((self.home / 'releases/old').exists(), 'a failed deploy removes nothing')
        self.assertEqual(sorted(path.name for path in self.node.glob('step-*')), [], 'the step files go also after a failure')

    def test_a_private_syntax_error_fails_without_echoing_the_command(self):
        self.command('private_' + self.sentinel + ' {')
        self.assertEqual(self.deploy(success=False), 'failed')
        self.assertEqual(self.status['Run before steps-0'], 'failed')
        self.assertEqual(os.readlink(self.home / 'current'), 'releases/initial')

    def test_a_failed_restart_keeps_the_new_release_and_stops_the_after_steps(self):
        (self.repo / 'fail').write_text('reload-or-restart')
        self.command(f'touch {self.node}/after-ran', 'after_activation')
        self.assertEqual(self.deploy(success=False), 'failed')
        self.assertEqual(self.status['Restart Processes-0'], 'failed')
        self.assertEqual(os.readlink(self.home / 'current'), f'releases/{self.release}')
        self.assertFalse((self.node / 'after-ran').exists())
        self.assertTrue((self.home / 'releases/old').exists())

    def test_a_release_a_process_still_uses_stays(self):
        process = subprocess.Popen(['sleep', '60'], cwd=self.home / 'releases/old')
        self.addCleanup(process.wait)
        self.addCleanup(process.kill)
        time.sleep(0.2)
        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        self.assertTrue((self.home / 'releases/old').exists())
        self.assertEqual(self.status['Clean up releases-0'], 'skipped')
        self.assertEqual(self.status['Check the deployment'], 'succeeded')


    def test_an_unreadable_process_scan_keeps_the_release_as_a_cleanup_warning(self):
        (self.repo / 'fail').write_text('find')
        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        self.assertTrue((self.home / 'releases/old').exists())
        self.assertEqual(self.status['Find releases in use-0'], 'failed')
        self.assertEqual(self.status['Clean up releases-0'], 'skipped')

    def test_a_user_step_that_moves_the_checkout_fails_the_end_check(self):
        self.command('git checkout --quiet --detach ' + self.first, 'after_activation')
        self.assertEqual(self.deploy(success=False), 'failed')
        self.assertEqual(self.status['Check the deployment'], 'failed')
        self.assertEqual(os.readlink(self.home / 'current'), f'releases/{self.release}')

    def test_installs_a_captured_node_timer_without_copying_its_private_command(self):
        schedule_id = '9fd64da7-2e68-4b7c-983a-55707946c795'
        self.inputs['intent']['runtime_definitions']['schedules'] = [{
            'id': schedule_id, 'user': self.user, 'home': str(self.home),
            'working_directory': str(self.home / 'current'), 'shell': '/bin/bash', 'login_shell': False,
            'calendar': '*-*-* 03:00:00', 'command_hash': 'a' * 64, 'timeout_seconds': 30, 'desired': 'disabled'}]
        self.assertEqual(self.deploy(), 'succeeded')
        self.assertEqual(self.status['Check Schedule timers-0'], 'succeeded')
        timer = self.node / ('etc/systemd/system/orbit-schedule-' + schedule_id + '.timer')
        self.assertIn('OnCalendar=*-*-* 03:00:00', timer.read_text())

    def test_an_empty_persistent_directory_is_seeded_once(self):
        shutil.rmtree(self.home / 'storage')
        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        self.assertEqual((self.home / 'storage/app/kept.txt').read_text(), 'from the release\n')
        self.assertEqual((self.home / 'storage/app/new.txt').read_text(), 'new\n')

    def test_publishes_only_the_manager_cli_and_uses_the_app_owned_vite_cache(self):
        manager = self.node / 'manager/.local/share/vite-plus/bin/vp'
        manager.parent.mkdir(parents=True)
        manager.write_text('#!' + sys.executable + '\n' + '''import json,os,sys
with open(CALLS,'a') as f:f.write(json.dumps(['vp',os.environ.get('VP_HOME'),*sys.argv[1:]])+'\\n')
if '--version' in sys.argv:print('vp 0.3.3')
elif 'list' in sys.argv:print('{"node":[{"version":"24.22.0"}],"package_managers":{"npm":[{"version":"11.12.0"}]}}')
'''.replace('CALLS', repr(str(self.calls_file))))
        manager.chmod(0o755)
        private = manager.parent.parent / 'private.json'
        private.write_text(self.sentinel)
        for name, value in {
            'package.json': {'devDependencies': {'vite-plus': '0.3.3'}, 'packageManager': 'npm@11.12.0', 'engines': {'node': '24.22.0'}},
            'package-lock.json': {'packages': {'node_modules/vite-plus': {'version': '0.3.3'}}},
        }.items():
            (self.origin / name).write_text(json.dumps(value))
        self.git(self.origin, 'add', '.')
        self.git(self.origin, 'commit', '--quiet', '-m', 'Vite project')
        self.inputs['intent']['deployment']['needs_vp'] = True

        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        public = self.node / 'public/bin/vp'
        self.assertEqual(public.read_bytes(), manager.read_bytes())
        self.assertEqual(public.stat().st_mode & 0o777, 0o755)
        self.assertFalse((public.parent.parent / 'private.json').exists())
        self.assertEqual(self.status['Publish public Vite CLI'], 'succeeded')
        invocations = [row for row in self.calls() if row[0] == 'vp']
        self.assertEqual(len(invocations), 3)
        self.assertTrue(all(row[1] == str(self.home / '.local/share/vite-plus') for row in invocations))
        self.assertEqual(invocations[0][2:], ['--version'])
        self.assertTrue(all(row[2:4] == ['-C', str(self.node)] for row in invocations[1:]))

    def test_a_vite_command_without_a_vite_dependency_needs_no_manager(self):
        self.inputs['intent']['deployment']['needs_vp'] = True
        self.assertEqual(self.deploy(), 'succeeded', json.dumps(self.status)[:3000])
        self.assertEqual(self.status['Read Vite manager CLI'], 'skipped')
        self.assertEqual(self.status['Publish public Vite CLI'], 'skipped')
        self.assertEqual(self.status['dev-vp-manager-ready'], 'succeeded')

if __name__ == "__main__":
    import unittest
    unittest.main()
