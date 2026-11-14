"""Remote service access only; all Node commands and connections are fixtures."""
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from contextlib import nullcontext
from pathlib import Path
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('services', ROOT / 'ansible/instance_node_services.py')
services = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(services)


class RemoteAccessTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.target = {'id': 11, 'process_id': 23, 'process_name': 'fixture-http',
                       'host': '10.44.0.11', 'port': 8094, 'docker': False,
                       'host_env_key': 'SERVICE_HOST', 'port_env_key': 'SERVICE_PORT'}
        self.base = 'localhost,127.0.0.1,::1,10.253.0.1'
        self.value = self.base + ',10.44.0.11'
        self.environment = {'NO_PROXY': self.value, 'no_proxy': self.value}
        self.container = {'name': 'instance-11', 'project': 'orbit-instances', 'acl': 'instance-11',
                          'network': 'orbitinst0', 'bridge_cidr': '10.253.0.0/24',
                          'bridge_admitted_at': 1800000000000000, 'node_services': [self.target],
                          'environment': [key + '=' + value for key, value in self.environment.items()]}
        self.acl = {'description': 'fixture', 'ingress': [], 'egress': [{'action': 'reject'}]}
        self.config = {}
        self.manager = {}
        self.calls = []
        self.probes = []
        self.reachable = True
        self.other_acls = []
        (self.root / 'bridge/endpoints').mkdir(parents=True)

    def connect(self, address, timeout):
        self.probes.append(address)
        self.assertEqual(timeout, 2)
        if not self.reachable:
            raise TimeoutError('private fixture diagnostic')
        return nullcontext()

    def guest_run(self, argv, content=None):
        self.calls.append((argv, content))
        if argv == ['systemctl', 'show-environment']:
            return '\n'.join(key + '=' + value for key, value in self.manager.items())
        if argv[:2] == ['systemctl', 'set-environment']:
            self.manager.update(entry.split('=', 1) for entry in argv[2:])
            return ''
        self.fail('Unexpected guest command: ' + str(argv))

    def node_run(self, argv, content=None, public_guest=False):
        self.calls.append((argv, content))
        if argv[:2] == ['incus', 'query']:
            if '/network-acls?' in argv[-1]:
                return json.dumps([dict(self.acl, name='instance-11'), *self.other_acls])
            return json.dumps({'status': 'Running', 'config': self.config} if '/instances/' in argv[-1] else self.acl)
        if argv[:4] == ['incus', 'network', 'acl', 'edit']:
            self.assertEqual(argv[4:], ['instance-11', '--project', 'default'])
            self.acl = json.loads(content)
            return ''
        if argv[:3] == ['incus', 'config', 'set']:
            self.assertEqual(argv[3:6], ['instance-11', '--project', 'orbit-instances'])
            self.config.update(entry.split('=', 1) for entry in argv[6:])
            return ''
        if argv[0] == 'python3':
            self.assertEqual(argv[2], 'fixture bridge')
            incoming = json.loads(content)
            folder = self.root / 'bridge/endpoints'
            if '--reconcile' in argv:
                for path in folder.glob('*.json'):
                    path.unlink()
            for endpoint in incoming['endpoints']:
                row = dict(endpoint, admitted_at=incoming['admitted_at'], translated={'host': endpoint['host'], 'port': endpoint['port']})
                (folder / (endpoint['host'] + '-' + str(endpoint['port']) + '.json')).write_text(json.dumps(row))
            return ''
        if argv[0] == 'ufw':
            return ''
        self.assertEqual(argv[:2], ['incus', 'exec'])
        self.assertEqual(argv[2:5], ['--project', 'orbit-instances', 'instance-11'])
        with patch.object(services.os, 'chown'):
            return json.dumps(services.guest(argv[-1], json.loads(content), self.root / 'guest',
                                             self.guest_run, self.connect))

    def apply(self):
        return services.apply(self.container, {'node_services': 'fixture helper', 'bridge': 'fixture bridge'},
                              self.node_run, self.root / 'state', self.root / 'bridge')

    def test_real_remote_host_exact_acl_and_untranslated_bridge_endpoint(self):
        self.assertEqual(self.apply(), {'status': 'applied', 'ports': [8094]})
        self.assertEqual(self.acl['egress'], [{'action': 'reject'}, {
            'action': 'allow', 'destination': '10.44.0.11/32', 'protocol': 'tcp',
            'destination_port': '8094', 'state': 'enabled', 'description': 'Orbit Node service:8094'}])
        bridge = [json.loads(content) for argv, content in self.calls if argv[0] == 'python3']
        self.assertEqual(bridge[0]['endpoints'], [{'host': '10.44.0.11', 'port': 8094, 'docker': False}])
        self.assertEqual(self.probes, [('10.44.0.11', 8094)])
        self.assertEqual(services.units([self.target]), {})
        self.assertFalse((self.root / 'guest/etc/systemd/system').exists())

    def test_proxy_bypass_reaches_exec_manager_and_app_login_and_repeat_is_unchanged(self):
        self.apply()
        for key in ('NO_PROXY', 'no_proxy'):
            self.assertEqual(self.config['environment.' + key], self.value)
            self.assertEqual(self.manager[key], self.value)
        for path in ('etc/systemd/system.conf.d/99-orbit-node-services.conf',
                     'etc/profile.d/99-orbit-node-services.sh'):
            content = (self.root / 'guest' / path).read_text()
            self.assertIn('NO_PROXY=' + self.value, content)
            self.assertIn('no_proxy=' + self.value, content)
            self.assertEqual((self.root / 'guest' / path).stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.apply(), {'status': 'unchanged', 'ports': [8094]})

    def test_fleet_proxy_writes_the_same_drop_ins_and_manager_value_once_and_refuses_a_foreign_file(self):
        fleet = self.base + ',10.45.0.1,10.45.0.0/24,10.232.2.0/24'
        environment = {'NO_PROXY': fleet, 'no_proxy': fleet}
        with patch.object(services.os, 'chown'):
            self.assertTrue(services.write_proxy_environment(environment, self.root / 'guest', self.guest_run))
            self.assertFalse(services.write_proxy_environment(environment, self.root / 'guest', self.guest_run))
        self.assertEqual(self.manager, environment)
        self.assertEqual((self.root / 'guest/etc/profile.d/99-orbit-node-services.sh').read_text(),
                         services.MARKER + 'export NO_PROXY=' + fleet + ' no_proxy=' + fleet + '\n')
        self.assertEqual((self.root / 'guest/etc/systemd/system.conf.d/99-orbit-node-services.conf').read_text(),
                         services.MARKER + '[Manager]\nDefaultEnvironment=NO_PROXY=' + fleet + ' no_proxy=' + fleet + '\n')
        # A file of the same name that Orbit did not write stays, and the writer fails with the public code.
        (self.root / 'guest/etc/profile.d/99-orbit-node-services.sh').write_text('# not Orbit\n')
        with patch.object(services.os, 'chown'), self.assertRaisesRegex(RuntimeError, 'instance.node_service_unit_conflict'):
            services.write_proxy_environment({'NO_PROXY': 'x', 'no_proxy': 'x'}, self.root / 'guest', self.guest_run)
        self.assertEqual((self.root / 'guest/etc/profile.d/99-orbit-node-services.sh').read_text(), '# not Orbit\n')

    def test_the_proxy_command_reads_its_value_from_stdin_and_prints_only_whether_it_changed(self):
        seen = []
        stdin = __import__('io').StringIO('{"proxy_environment": {"NO_PROXY": "secretless", "no_proxy": "secretless"}}')
        out = __import__('io').StringIO()
        with patch.object(services, 'write_proxy_environment', lambda environment: seen.append(environment) or True), \
                patch.object(sys, 'argv', ['instance_node_services.py', 'proxy']), patch.object(sys, 'stdin', stdin), \
                patch.object(sys, 'stdout', out):
            services.main()
        self.assertEqual(seen, [{'NO_PROXY': 'secretless', 'no_proxy': 'secretless'}])
        self.assertEqual(json.loads(out.getvalue()), {'changed': True})

    def test_the_one_renderer_gives_the_drop_ins_that_the_writer_writes_and_its_command_prints_them(self):
        fleet = self.base + ',feature.tool.test'
        environment = {'NO_PROXY': fleet, 'no_proxy': fleet}
        with patch.object(services.os, 'chown'):
            services.write_proxy_environment(environment, self.root / 'guest', self.guest_run)
        files = services.proxy_files(environment)
        self.assertEqual({path: (self.root / 'guest' / path).read_text() for path in files}, files)
        printed = subprocess.run([sys.executable, '-c', (ROOT / 'ansible/instance_node_services.py').read_text(), 'proxy-files'],
                                 input=json.dumps({'proxy_environment': environment}), text=True, capture_output=True, check=True)
        self.assertEqual(json.loads(printed.stdout), {'files': files})

    def test_the_hosts_renderer_keeps_other_lines_replaces_orbits_block_and_drops_it_without_names(self):
        names = [{'name': 'feature.tool.test', 'host': '10.253.0.1', 'port': 443}, {'name': 'reverb.orbit', 'host': '10.44.0.3', 'port': 8443}]
        block = '# BEGIN Orbit private names\n10.253.0.1 feature.tool.test\n10.44.0.3 reverb.orbit\n# END Orbit private names\n'
        old = '# BEGIN Orbit private names\n10.1.1.9 gone.test\n# END Orbit private names\n'
        for case, hosts, plan, expected in (
                ('a plain file gets the block last', '127.0.0.1 localhost\n', names, '127.0.0.1 localhost\n' + block),
                ('an older block goes, other lines stay', '127.0.0.1 localhost\n' + old + '10.1.1.1 operator', names,
                 '127.0.0.1 localhost\n10.1.1.1 operator\n' + block),
                ('no name drops the block', '127.0.0.1 localhost\n' + old, [], '127.0.0.1 localhost\n'),
                ('nothing at all', '', [], ''),
                ('a missing file gets only the block', '', names, block)):
            with self.subTest(case=case):
                self.assertEqual(services.private_names_hosts(hosts, plan), expected)
                printed = subprocess.run([sys.executable, '-c', (ROOT / 'ansible/instance_node_services.py').read_text(), 'hosts'],
                                         input=json.dumps({'hosts': hosts, 'private_names': plan}), text=True, capture_output=True, check=True)
                self.assertEqual(json.loads(printed.stdout), {'content': expected})

    def test_attached_remote_probe_failure_has_public_target_identity(self):
        self.reachable = False
        with self.assertRaises(services.TargetFailure) as error:
            self.apply()
        self.assertEqual(error.exception.evidence(), {
            'error_code': 'instance.node_service_unreachable',
            'node_service_target': {'id': 11, 'process_id': 23, 'process_name': 'fixture-http', 'port': 8094}})
        self.assertNotIn('private fixture diagnostic', str(error.exception))

    def test_detach_removes_acl_endpoint_forward_and_all_managed_proxy_hosts(self):
        self.apply()
        self.calls.clear()
        self.container.update(node_services=[], node_service_withdraw=True, node_service_removed_ports=[],
                              node_service_removed_hosts=['10.44.0.11'],
                              node_service_withdraw_endpoint={'host': '10.44.0.11', 'port': 8094, 'docker': False},
                              node_service_removed_rules=[{
                                  'rule': 'allow', 'interface_in': 'orbitinst0', 'interface_out': 'orbit',
                                  'from_ip': '10.253.0.2', 'to_ip': '10.44.0.11', 'to_port': '8094', 'proto': 'tcp'}],
                              environment=['NO_PROXY=' + self.base, 'no_proxy=' + self.base])
        self.assertEqual(self.apply(), {'status': 'applied', 'ports': []})
        self.assertEqual(self.acl['egress'], [{'action': 'reject'}])
        bridge = [(argv, json.loads(content)) for argv, content in self.calls if argv[0] == 'python3']
        self.assertEqual(bridge[0][0][-3:], ['--intent', '--reconcile', '--withdraw'])
        self.assertEqual(bridge[0][1]['endpoints'], [])
        self.assertIn((['ufw', '--force', 'route', 'delete', 'allow', 'in', 'on', 'orbitinst0',
                        'out', 'on', 'orbit', 'from', '10.253.0.2', 'to', '10.44.0.11', 'port', '8094', 'proto', 'tcp'], None), self.calls)
        self.assertEqual(self.config['environment.NO_PROXY'], self.base)
        self.assertEqual(self.manager, {'NO_PROXY': self.base, 'no_proxy': self.base})
        for path in ('etc/systemd/system.conf.d/99-orbit-node-services.conf', 'etc/profile.d/99-orbit-node-services.sh'):
            self.assertNotIn('10.44.0.11', (self.root / 'guest' / path).read_text())

    def test_multiple_hosts_on_one_port_keep_distinct_rules_without_sockets(self):
        second = dict(self.target, host='10.44.0.12', id=12)
        self.container['node_services'].append(second)
        self.container['environment'] = [key + '=' + self.value + ',10.44.0.12' for key in ('NO_PROXY', 'no_proxy')]
        self.apply()
        self.assertEqual([rule.get('destination') for rule in self.acl['egress'][1:]],
                         ['10.44.0.11/32', '10.44.0.12/32'])
        self.assertEqual(self.probes, [('10.44.0.11', 8094), ('10.44.0.12', 8094)])

    def withdraw(self, endpoint, targets):
        self.container.update(node_services=targets, node_service_withdraw=True, node_service_removed_ports=[],
                              node_service_removed_hosts=[endpoint['host']],
                              node_service_withdraw_endpoint={key: endpoint[key] for key in ('host', 'port', 'docker')},
                              node_service_removed_rules=[{'rule': 'allow', 'interface_in': 'orbitinst0', 'interface_out': 'orbit',
                                                           'from_ip': '10.253.0.2', 'to_ip': endpoint['host'],
                                                           'to_port': str(endpoint['port']), 'proto': 'tcp'}])
        value = self.base + (',' + ','.join(dict.fromkeys(t['host'] for t in targets)) if targets else '')
        self.container['environment'] = [key + '=' + value for key in ('NO_PROXY', 'no_proxy')]
        return self.apply()

    def test_same_host_keeps_proxy_bypass_until_its_last_port_is_detached(self):
        other = dict(self.target, id=12, port=8096)
        self.container['node_services'].append(other)
        self.apply()
        self.calls.clear()
        self.withdraw(self.target, [other])
        self.assertEqual([r.get('destination_port') for r in self.acl['egress'][1:]], ['8096'])
        self.assertEqual(self.manager['NO_PROXY'], self.value)
        self.assertEqual(self.config['environment.no_proxy'], self.value)
        bridge = [json.loads(content)['endpoints'] for argv, content in self.calls if argv[0] == 'python3']
        self.assertEqual(bridge, [[{'host': '10.44.0.11', 'port': 8096, 'docker': False}]])
        self.assertEqual([argv[argv.index('port') + 1] for argv, _ in self.calls if argv[0] == 'ufw'], ['8094'])
        self.withdraw(other, [])
        self.assertEqual(self.acl['egress'], [{'action': 'reject'}])
        self.assertEqual(self.manager['NO_PROXY'], self.base)
        self.assertEqual(self.config['environment.no_proxy'], self.base)
        self.assertEqual(list((self.root / 'bridge/endpoints').glob('*.json')), [])

    def test_withdrawal_retains_other_instances_applied_endpoint_without_revalidating_it(self):
        self.apply()
        unrelated = {'host': '10.44.0.12', 'port': 9004, 'docker': False,
                     'admitted_at': 1800000000000000, 'translated': {'host': '10.44.0.12', 'port': 9004}}
        (self.root / 'bridge/endpoints/unrelated.json').write_text(json.dumps(unrelated))
        other_acl = {'name': 'instance-12', 'egress': [
            {'action': 'allow', 'destination': '10.44.0.12/32', 'protocol': 'tcp', 'destination_port': '9004', 'state': 'enabled'}]}
        self.other_acls.append(other_acl)
        self.calls.clear()
        self.withdraw(self.target, [])
        bridge = [json.loads(content)['endpoints'] for argv, content in self.calls if argv[0] == 'python3']
        self.assertEqual(bridge, [[{'host': '10.44.0.12', 'port': 9004, 'docker': False}]])
        self.assertEqual(self.other_acls, [other_acl])
        self.assertEqual(self.acl['egress'], [{'action': 'reject'}])
        self.assertEqual([argv[argv.index('to') + 1] for argv, _ in self.calls if argv[0] == 'ufw'], ['10.44.0.11'])

    def test_other_container_retains_shared_bridge_endpoint_but_not_this_containers_forward_rule(self):
        self.apply()
        self.other_acls.append({'name': 'instance-12', 'egress': [self.acl['egress'][-1]]})
        self.calls.clear()
        self.withdraw(self.target, [])
        bridge = [json.loads(content)['endpoints'] for argv, content in self.calls if argv[0] == 'python3']
        self.assertEqual(bridge, [[{'host': '10.44.0.11', 'port': 8094, 'docker': False}]])
        self.assertEqual(len([argv for argv, _ in self.calls if argv[0] == 'ufw']), 1)
        self.assertEqual(self.acl['egress'], [{'action': 'reject'}])

    def test_remote_destroy_and_failed_migrate_restore_remove_only_their_owned_path(self):
        executable = shutil.which('ansible-playbook')
        deno = shutil.which('deno')
        self.assertIsNotNone(executable)
        self.assertIsNotNone(deno)
        rule = {'rule': 'allow', 'route': 'yes', 'interface_in': 'orbitinst0', 'interface_out': 'orbit',
                'from_ip': '10.253.0.2', 'to_ip': '10.44.0.11', 'to_port': '8094', 'proto': 'tcp',
                'comment': 'orbit:instance-11-node-service-10.44.0.11-8094'}
        remote_acl = {'action': 'allow', 'destination': '10.44.0.11/32', 'protocol': 'tcp',
                      'destination_port': '8094', 'state': 'enabled'}
        for kind in ('destroy', 'failed-migrate-restore'):
            with self.subTest(kind=kind):
                folder = self.root / kind
                for name in ('bin', 'run', 'containers/instance-11', 'containers/instance-12', 'mirror.git', 'bridge/endpoints'):
                    (folder / name).mkdir(parents=True)
                container = dict(self.container, pool='orbit-instances', mirror=str(folder / 'mirror.git'),
                                 firewall_prefix='orbit:instance-11-', firewall=[rule])
                state = {'containers': {'instance-11': {'status': 'Running'}, 'instance-12': {'status': 'Running'}},
                         'network-acls': [{'name': 'instance-11', 'egress': [remote_acl]},
                                          {'name': 'instance-12', 'egress': [remote_acl]},
                                          {'name': 'orbitinst0-egress', 'egress': [remote_acl]}]}
                (folder / 'state.json').write_text(json.dumps(state))
                shared = json.dumps({'host': '10.44.0.11', 'port': 8094, 'docker': False})
                (folder / 'bridge/endpoints/remote.json').write_text(shared)
                (folder / 'ufw.json').write_text(json.dumps([
                    {'spec': 'route allow in on orbitinst0 from 10.253.0.2 to 10.44.0.11 port 8094 proto tcp', 'comment': rule['comment']},
                    {'spec': 'route allow in on orbitinst0 from 10.253.0.3 to 10.44.0.11 port 8096 proto tcp',
                     'comment': 'orbit:instance-12-node-service-10.44.0.11-8096'}]))
                (folder / 'bin/incus').write_text('#!/bin/sh\nexec ' + repr(deno) + ' run -A ' +
                                                repr(str(ROOT / 'tests/fixtures/instance-container-incus.ts')) + ' "$@"\n')
                (folder / 'bin/ufw').write_text('#!/bin/sh\nexec ' + repr(deno) + ' run -A ' +
                                              repr(str(ROOT / 'tests/fixtures/instance-container-ufw.ts')) + ' "$@"\n')
                for command in ('incus', 'ufw'):
                    (folder / 'bin' / command).chmod(0o755)
                if kind == 'destroy':
                    production = (ROOT / 'ansible/instance-container-destroy.yml').read_text().replace(
                        '/var/lib/orbit/instance-mail', str(folder / 'mail-state'))
                    tasks = yaml.safe_load(production)[1]['tasks']
                else:
                    tasks = yaml.safe_load((ROOT / 'ansible/tasks/instance-migrate-discard.yml').read_text())
                    modules = folder / 'collections/ansible_collections/community/general/plugins/modules'
                    modules.mkdir(parents=True)
                    (modules / 'ufw.py').write_text(
                        'from ansible.module_utils.basic import AnsibleModule\nimport json,os\nfrom pathlib import Path\n'
                        'm=AnsibleModule(argument_spec={**{k:{"type":"str"} for k in '
                        '["rule","interface_in","interface_out","from_ip","to_ip","to_port","proto","comment"]},'
                        '"route":{"type":"bool"},"delete":{"type":"bool"}})\n'
                        'assert m.params["delete"] is True\n'
                        'Path(os.environ["HOST_FIXTURE"],"removed-forward.json").write_text(json.dumps(m.params))\n'
                        'm.exit_json(changed=True)\n')
                proof = [{'hosts': 'localhost', 'connection': 'local', 'gather_facts': False,
                          'vars': {'container': container, 'phase': 'remove', 'incus': ['incus', '--project', 'orbit-instances'],
                                   'run': str(folder / 'run'), 'ansible_python_interpreter': sys.executable},
                          'tasks': tasks}]
                (folder / 'proof.yml').write_text(yaml.safe_dump(proof))
                environment = dict(os.environ, HOST_FIXTURE=str(folder), ANSIBLE_STDOUT_CALLBACK='default',
                                   ANSIBLE_NOCOLOR='1', PATH=str(folder / 'bin') + os.pathsep + os.environ['PATH'])
                if kind != 'destroy':
                    environment['ANSIBLE_COLLECTIONS_PATH'] = str(folder / 'collections')
                done = subprocess.run([executable, '-i', 'localhost,', str(folder / 'proof.yml')],
                                      env=environment, text=True, capture_output=True, timeout=30, check=False)
                self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
                result = json.loads((folder / 'state.json').read_text())
                self.assertEqual(list(result['containers']), ['instance-12'])
                self.assertEqual([acl['name'] for acl in result['network-acls']], ['instance-12', 'orbitinst0-egress'])
                self.assertEqual(result['network-acls'][0]['egress'], [remote_acl])
                self.assertEqual((folder / 'bridge/endpoints/remote.json').read_text(), shared)
                self.assertFalse((folder / 'mirror.git').exists())
                if kind == 'destroy':
                    self.assertEqual([r['comment'] for r in json.loads((folder / 'ufw.json').read_text())],
                                     ['orbit:instance-12-node-service-10.44.0.11-8096'])
                else:
                    removed = json.loads((folder / 'removed-forward.json').read_text())
                    self.assertEqual({key: removed[key] for key in ('from_ip', 'to_ip', 'to_port', 'proto')},
                                     {'from_ip': '10.253.0.2', 'to_ip': '10.44.0.11', 'to_port': '8094', 'proto': 'tcp'})
                    self.assertTrue(removed['route'])
                    self.assertTrue(removed['delete'])

    def test_previous_gateway_local_intent_has_no_new_required_fields(self):
        container = dict(self.container, node_services=[], environment=[])
        self.assertEqual(services.apply(container, {}, self.node_run, self.root / 'state', self.root / 'bridge'),
                         {'status': 'unchanged', 'ports': []})
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
