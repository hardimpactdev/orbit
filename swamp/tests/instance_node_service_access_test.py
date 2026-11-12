"""Remote service access only; all Node commands and connections are fixtures."""
import importlib.util
import json
import tempfile
import unittest
from contextlib import nullcontext
from pathlib import Path
from unittest.mock import patch

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
                              self.node_run, self.root / 'state')

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
                              node_service_removed_hosts=['10.44.0.11'], bridge_endpoints=[],
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

    def test_previous_gateway_local_intent_has_no_new_required_fields(self):
        container = dict(self.container, node_services=[], environment=[])
        self.assertEqual(services.apply(container, {}, self.node_run, self.root / 'state'),
                         {'status': 'unchanged', 'ports': []})
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
