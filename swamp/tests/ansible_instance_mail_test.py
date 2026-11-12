"""Exercise the shared SMTP phase with real Ansible and the real additive bridge resolver, no live network."""
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]

FAKE = r'''import importlib.util, json, os, sys
from pathlib import Path
root = Path(os.environ['MAIL_FIXTURE_ROOT'])
a = sys.argv[1:]
state = json.loads((root / 'state.json').read_text())
name = Path(sys.argv[0]).name
if name == 'python3':
    assert '--intent' in a and '--reconcile' not in a
    incoming = json.load(sys.stdin)
    (root / 'helper-input.json').write_text(json.dumps(incoming))
    spec = importlib.util.spec_from_file_location('bridge', str(root / 'bridge-program'))
    # The staged file has no suffix; load explicitly as Python source.
    from importlib.machinery import SourceFileLoader
    spec = importlib.util.spec_from_loader('bridge', SourceFileLoader('bridge', str(root / 'bridge-program')))
    bridge = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(bridge)
    def api(name, value=None, etag=None, deadline=None):
        path = root / 'bridge-acl.json'
        old = json.loads(path.read_text())
        if value is not None:
            old.update(value)
            path.write_text(json.dumps(old))
        return old, 'fixture-etag'
    bridge.api = api
    bridge.subprocess.check_output = lambda *args, **kwargs: ''.join('-A DOCKER -d ' + incoming['endpoints'][0]['host'] + '/32 -p tcp -m tcp --dport ' + str(port) + ' -j DNAT --to-destination ' + state['docker'] + ':1025\n' for port in state.get('publications', [1025]))
    if state.get('path_failed'):
        print(json.dumps({'error_code': 'instance.container_endpoint_unresolved'}))
        sys.exit(1)
    print(json.dumps({'changed': bridge.apply(root / 'bridge-state', incoming)}))
    sys.exit()
with (root / 'calls.jsonl').open('a') as out:
    out.write(json.dumps(a) + '\n')
if a[0] == 'query':
    if '/network-acls/' in a[-1]:
        print(json.dumps(state['acl']))
    else:
        print(json.dumps({'status': state.get('power', 'Running')}))
elif a[:3] == ['network', 'acl', 'edit']:
    assert a[3] == 'instance-7'
    state['acl'] = json.load(sys.stdin)
elif 'file' in a and 'push' in a:
    target = a[-1].removeprefix('instance-7')
    assert target.startswith('/etc/systemd/system/orbit-mail-')
    assert a[a.index('--uid') + 1] == a[a.index('--gid') + 1] == '0'
    assert a[a.index('--mode') + 1] == '0644'
    path = root / 'guest' / target.lstrip('/')
    path.parent.mkdir(parents=True, exist_ok=True)
    if state.get('push_failed') or path.name == state.get('fail_unit'): sys.exit(1)
    path.write_text(sys.stdin.read())
    path.chmod(0o644)
elif a[0] == 'exec':
    assert 'instance-7' in a
    c = a[a.index('--') + 1:]
    if c[:2] == ['test', '-x']:
        sys.exit(1 if state.get('binary_missing') else 0)
    if c[0] == 'cat':
        path = root / 'guest' / c[1].lstrip('/')
        if not path.exists(): sys.exit(1)
        sys.stdout.write(path.read_text())
    elif c[0] == 'ss':
        port = int(c[-1].split(':')[-1])
        if port in state.get('foreign_ports', []) or state['sockets'].get(str(port)):
            print('LISTEN 0 128 127.0.0.1:' + str(port) + ' 0.0.0.0:*')
    elif c[:2] == ['systemctl', 'show']:
        port = c[2].split('-')[-1].split('.')[0]
        active = state['sockets'].get(port, False)
        print('ActiveState=' + ('active' if active else 'inactive'))
        print('UnitFileState=' + ('enabled' if active else 'disabled'))
        if active: print('Listen=127.0.0.1:' + port + ' (Stream)')
    elif c[:2] == ['systemctl', 'enable']:
        port = c[-1].split('-')[-1].split('.')[0]
        state['sockets'][port] = True
    elif c[:2] == ['systemctl', 'try-restart']:
        assert c[-1] in ['orbit-mail-1025.service', 'orbit-mail-2525.service']
        if c[-1] in state.get('services', {}):
            state['services'][c[-1]] = state['loaded_services'][c[-1]]
    elif c == ['systemctl', 'daemon-reload']:
        if state.get('reload_failed'): sys.exit(1)
        state['loaded_services'] = {}
        for unit in (root / 'guest/etc/systemd/system').glob('*.service'):
            target = next(line.split()[-1] for line in unit.read_text().splitlines() if line.startswith('ExecStart='))
            state['loaded_services'][unit.name] = target
    else:
        raise AssertionError(c)
else:
    raise AssertionError(a)
(root / 'state.json').write_text(json.dumps(state))
'''

@unittest.skipUnless(shutil.which('ansible-playbook'), 'pinned Ansible required')
class MailPhase(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-mail-path-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.state = {'acl': {'description': 'Instance 7', 'egress': [], 'ingress': [{'action': 'allow', 'source': '10.253.0.1/32'}]},
                      'docker': '172.17.0.7', 'sockets': {}}
        self.save()
        # The already-installed timer has its original host config; lifecycle admission adds endpoints.
        (self.root / 'bridge-state').mkdir()
        (self.root / 'bridge-state/config.json').write_text(json.dumps({'network': 'orbitinst0', 'cidr': '10.253.0.0/24', 'admitted_at': 1799999999999999, 'baseline': {}}))
        (self.root / 'bin').mkdir()
        for name in ['incus', 'python3']:
            file = self.root / 'bin' / name
            file.write_text('#!' + sys.executable + '\n' + FAKE)
            file.chmod(0o755)
        (self.root / 'bridge-acl.json').write_text(json.dumps({'description': 'bridge', 'egress': [], 'ingress': [], 'config': {}}))
        for name in ['instance-mail.yml', 'instance-bridge-rules.yml']:
            content = (ROOT / 'swamp/ansible/tasks' / name).read_text()
            if name == 'instance-bridge-rules.yml':
                # Keep the production privilege contract asserted, but perform local fixture writes as the fixture user.
                self.assertIn('owner: root', content)
                self.assertIn('group: root', content)
                self.assertIn("mode: '0755'", content)
            content = content.replace('/usr/local/libexec/orbit-instance-bridge', str(self.root / 'bridge-program'))
            content = content.replace('/usr/local/libexec', str(self.root / 'libexec'))
            content = content.replace('/var/lib/orbit/instance-bridge', str(self.root / 'bridge-state'))
            content = content.replace('/var/lib/orbit/instance-mail', str(self.root / 'activation'))
            content = content.replace('owner: root', 'owner: "' + str(os.getuid()) + '"')
            content = content.replace('group: root', 'group: "' + str(os.getgid()) + '"')
            (self.root / name).write_text(content)
        shutil.copy(ROOT / 'swamp/ansible/instance_bridge.py', self.root / 'instance_bridge.py')
        self.endpoint = {'host': '10.253.0.1', 'port': 1025, 'docker': True}
        php = 'require "' + str(ROOT / 'apps/gateway/app/Domain/Instances/DevelopmentMail.php') + r'"; echo json_encode((new \App\Domain\Instances\DevelopmentMail)->units(json_decode($argv[1],true)));'
        self.units = json.loads(subprocess.check_output(['php', '-r', php, json.dumps(self.endpoint)], text=True))
        # A hand-edited workspace is a canary: the phase must never address or change it.
        workspace = self.root / 'guest/srv/workspace'
        workspace.mkdir(parents=True)
        (workspace / '.env').write_bytes(b'# hand edited\r\nMAIL_MAILER=log\r\nDB_HOST=hand')

    def save(self):
        (self.root / 'state.json').write_text(json.dumps(self.state))

    def run_phase(self, success=True):
        plan = {'project': 'orbit-instances', 'name': 'instance-7', 'acl': 'instance-7', 'network': 'orbitinst0',
                'bridge_cidr': '10.253.0.0/24', 'bridge_admitted_at': 1800000000000000, 'mail': self.endpoint, 'mail_units': self.units}
        play = [{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False,
                 'vars': {'ansible_python_interpreter': sys.executable, 'container': plan,
                          'as_root': ['incus', 'exec', '--project', 'orbit-instances', 'instance-7', '--']},
                 'tasks': [{'ansible.builtin.include_tasks': str(self.root / 'instance-mail.yml')},
                           {'ansible.builtin.copy': {'dest': str(self.root / 'result.json'), 'content': '{{ mail_result | to_json }}', 'mode': '0600'}}]}]
        (self.root / 'play.json').write_text(json.dumps(play))
        env = {**os.environ, 'MAIL_FIXTURE_ROOT': str(self.root), 'PATH': str(self.root / 'bin') + ':' + os.environ['PATH'],
               'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_COLLECTIONS_PATH': str(self.root / 'empty'), 'ANSIBLE_NOCOLOR': '1',
               'ANSIBLE_LOCAL_TEMP': str(self.root / 'ansible-local'), 'ANSIBLE_REMOTE_TEMP': str(self.root / 'ansible-remote')}
        done = subprocess.run(['ansible-playbook', '-i', 'localhost,', str(self.root / 'play.json')], env=env, capture_output=True, text=True)
        self.assertEqual(done.returncode == 0, success, done.stdout + done.stderr)
        self.state = json.loads((self.root / 'state.json').read_text())
        return json.loads((self.root / 'result.json').read_text()) if success else done.stdout

    def test_two_private_listeners_exact_rule_additive_dnat_refresh_and_no_app_changes(self):
        before = (self.root / 'guest/srv/workspace/.env').read_bytes()
        self.assertEqual(self.run_phase()['status'], 'applied')
        self.assertEqual(self.state['acl']['egress'], [{'action': 'allow', 'destination': '10.253.0.1/32', 'protocol': 'tcp', 'destination_port': '1025', 'state': 'enabled', 'description': 'Orbit development SMTP'}])
        self.assertEqual(self.state['sockets'], {'1025': True, '2525': True})
        # Real Incus GETs can include empty default fields; they must not cause duplicate rules.
        self.state['acl']['egress'][0].update({'source': '', 'source_port': '', 'icmp_type': '', 'icmp_code': ''})
        self.save()
        call = json.loads((self.root / 'helper-input.json').read_text())
        self.assertEqual(call['endpoints'], [self.endpoint])
        record = next((self.root / 'bridge-state/endpoints').glob('*.json'))
        self.assertEqual(json.loads(record.read_text())['translated'], {'host': '172.17.0.7', 'port': 1025})
        paths = sorted((self.root / 'guest/etc/systemd/system').iterdir())
        stamps = {x.name: x.stat().st_mtime_ns for x in paths}
        calls_before = (self.root / 'calls.jsonl').read_text()
        self.assertEqual(self.run_phase()['status'], 'unchanged')
        self.assertEqual({x.name: x.stat().st_mtime_ns for x in paths}, stamps)
        new_calls = (self.root / 'calls.jsonl').read_text()[len(calls_before):]
        self.assertNotIn('try-restart', new_calls)
        self.assertNotIn('push', new_calls)
        self.assertEqual((self.root / 'guest/srv/workspace/.env').read_bytes(), before)
        self.assertEqual(len(self.state['acl']['egress']), 1)
        for path in paths:
            self.assertEqual(path.stat().st_mode & 0o777, 0o644)
        calls = (self.root / 'calls.jsonl').read_text()
        self.assertNotIn('php', calls)
        self.assertNotIn('/srv/workspace', calls)
        self.assertEqual(len(paths), 4)
        # Run precisely the timer's refresh mode against the persisted endpoint after Docker readdresses it.
        spec = importlib.util.spec_from_file_location('refresh', ROOT / 'swamp/ansible/instance_bridge.py')
        bridge = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(bridge)
        from unittest.mock import patch
        def api(name, value=None, etag=None, deadline=None):
            path = self.root / 'bridge-acl.json'
            data = json.loads(path.read_text())
            if value is not None:
                data.update(value)
                path.write_text(json.dumps(data))
            return data, 'fixture'
        with patch.object(bridge, 'api', side_effect=api), patch.object(bridge.subprocess, 'check_output', return_value='-A DOCKER -d 10.253.0.1/32 -p tcp --dport 1025 -j DNAT --to-destination 172.17.0.9:1025\n'):
            bridge.apply(self.root / 'bridge-state', None)
        self.assertEqual(json.loads(record.read_text())['translated'], {'host': '172.17.0.9', 'port': 1025})

    def test_failed_unit_write_keeps_network_path_and_a_retry_completes_it(self):
        self.state['push_failed'] = True
        self.save()
        self.run_phase(success=False)
        self.assertEqual(len(self.state['acl']['egress']), 1)
        self.assertTrue(list((self.root / 'bridge-state/endpoints').glob('*.json')))
        self.assertEqual(self.state['sockets'], {})
        self.state['push_failed'] = False
        self.save()
        self.assertEqual(self.run_phase()['status'], 'applied')
        self.assertEqual(len(self.state['acl']['egress']), 1)
        self.assertEqual(self.state['sockets'], {'1025': True, '2525': True})
        self.assertEqual(self.run_phase()['status'], 'unchanged')

    def activation_retry(self, failure):
        self.assertEqual(self.run_phase()['status'], 'applied')
        services = ['orbit-mail-1025.service', 'orbit-mail-2525.service']
        self.state['services'] = {name: '10.253.0.1:1025' for name in services}
        # Both publications exist during the endpoint transition; the unchanged helper stays additive.
        self.state['publications'] = [1025, 2025]
        self.endpoint['port'] = 2025
        self.units = [{**unit, 'content': unit['content'].replace('10.253.0.1:1025', '10.253.0.1:2025')} for unit in self.units]
        if failure == 'write':
            self.state['fail_unit'] = 'orbit-mail-2525.service'
        else:
            self.state['reload_failed'] = True
        self.save()
        self.run_phase(success=False)
        pending = self.root / 'activation/instance-7/activation.json'
        self.assertEqual(json.loads(pending.read_text()), services)
        first = self.root / 'guest/etc/systemd/system/orbit-mail-1025.service'
        self.assertIn('10.253.0.1:2025', first.read_text())
        self.assertEqual(self.state['services'], {name: '10.253.0.1:1025' for name in services})
        self.state.pop('fail_unit', None)
        self.state.pop('reload_failed', None)
        self.save()
        calls = (self.root / 'calls.jsonl').read_text()
        self.assertEqual(self.run_phase()['status'], 'applied')
        self.assertEqual(self.state['services'], {name: '10.253.0.1:2025' for name in services})
        new_calls = (self.root / 'calls.jsonl').read_text()[len(calls):]
        for name in services:
            self.assertIn('"try-restart", "' + name + '"', new_calls)
        self.assertFalse(pending.exists())
        calls = (self.root / 'calls.jsonl').read_text()
        self.assertEqual(self.run_phase()['status'], 'unchanged')
        self.assertNotIn('try-restart', (self.root / 'calls.jsonl').read_text()[len(calls):])

    def test_failure_after_one_successful_service_write_finishes_pending_activation(self):
        self.activation_retry('write')

    def test_failure_after_all_writes_before_reload_finishes_pending_activation(self):
        self.activation_retry('reload')

    def test_foreign_listener_or_unit_skips_both_ports_without_mutation(self):
        for kind in ['port', 'unit']:
            with self.subTest(kind=kind):
                if kind == 'port':
                    self.state['foreign_ports'] = [2525]
                    self.save()
                else:
                    self.state['foreign_ports'] = []
                    self.save()
                    folder = self.root / 'guest/etc/systemd/system'
                    folder.mkdir(parents=True)
                    (folder / 'orbit-mail-1025.service').write_text('# owned by project\n')
                result = self.run_phase()
                self.assertEqual(result, {'status': 'skipped', 'reason': 'mail_port_in_use' if kind == 'port' else 'mail_unit_conflict'})
                self.assertFalse((self.root / 'bridge-state/endpoints').exists())
                self.assertEqual(self.state['sockets'], {})
                self.assertEqual(self.state['acl']['egress'], [])

    def test_stopped_missing_binary_or_path_failure_never_changes_guest(self):
        for reason in ['power', 'binary_missing', 'path_failed']:
            with self.subTest(reason=reason):
                self.state = {**self.state, 'power': 'Stopped' if reason == 'power' else 'Running',
                              'binary_missing': reason == 'binary_missing', 'path_failed': reason == 'path_failed'}
                self.save()
                self.run_phase(success=False)
                self.assertFalse((self.root / 'guest/etc').exists())
                self.assertEqual(self.state['acl']['egress'], [])

if __name__ == '__main__':
    unittest.main()
