"""The CI role adopts the setup-script host projection without touching runner containers."""
import copy
import json
import subprocess
import unittest
from unittest.mock import patch

from harness import REMOTE


def load():
    namespace = {'__name__': 'ci_runner_host_test'}
    source = (REMOTE.parent / 'task-vms/incus-host.sh').read_text().split("<<'CI_PY'\n", 1)[1].split('\nCI_PY', 1)[0]
    exec(compile(source, 'ci-runner-host', 'exec'), namespace)
    return namespace


class IncusFixture:
    def __init__(self):
        self.objects = {}
        self.calls = []

    def run(self, argv, **options):
        self.calls.append(argv)
        if argv[1] == 'query':
            assert '--project' not in argv, 'incus query rejects --project'
            args = argv[1:]
        else:
            assert argv[:3] == ['incus', '--project', 'default'], argv
            args = argv[3:]
        output = ''
        if args[0] == 'query':
            path = args[1]
            collection = path.split('?', 1)[0]
            if collection in ('/1.0/projects', '/1.0/networks', '/1.0/network-acls', '/1.0/storage-pools'):
                if collection in ('/1.0/networks', '/1.0/network-acls'):
                    assert path.endswith('?project=default'), path
                output = json.dumps([key for key in self.objects if key.startswith(collection + '/')])
            else:
                output = json.dumps(self.objects[path])
        elif args[:2] == ['storage', 'create']:
            self.objects['/1.0/storage-pools/default'] = {'driver': args[3]}
        elif args[:2] == ['project', 'create']:
            config = dict(item.split('=', 1) for item in args[3:] if item != '-c')
            self.objects['/1.0/projects/orbit-ci'] = {'config': config}
            self.objects['/1.0/instances?project=orbit-ci'] = []
        elif args[:2] == ['project', 'set']:
            self.objects['/1.0/projects/orbit-ci']['config'].update(item.split('=', 1) for item in args[3:])
        elif args[:3] == ['network', 'acl', 'create']:
            self.objects['/1.0/network-acls/orbit-ci-egress?project=default'] = {}
        elif args[:3] == ['network', 'acl', 'edit']:
            self.objects['/1.0/network-acls/orbit-ci-egress?project=default'] = json.loads(options['input'])
        elif args[:2] == ['network', 'create']:
            self.objects['/1.0/networks/orbitci0?project=default'] = {
                'type': 'bridge', 'managed': True, 'config': {'ipv4.address': '10.253.3.1/24'},
            }
        elif args[:2] == ['network', 'set']:
            self.objects['/1.0/networks/orbitci0?project=default']['config'].update(item.split('=', 1) for item in args[3:])
        elif args[:2] == ['profile', 'edit']:
            assert args[-2:] == ['--project', 'orbit-ci'], args
            self.objects['/1.0/profiles/default?project=orbit-ci'] = json.loads(options['input'])
        elif args[:2] == ['project', 'delete']:
            del self.objects['/1.0/projects/orbit-ci']
        elif args[:2] == ['network', 'delete']:
            del self.objects['/1.0/networks/orbitci0?project=default']
        elif args[:3] == ['network', 'acl', 'delete']:
            del self.objects['/1.0/network-acls/orbit-ci-egress?project=default']
        else:
            raise AssertionError(argv)
        return subprocess.CompletedProcess(argv, 0, output, '')


class CiRunnerHostTest(unittest.TestCase):
    def setUp(self):
        self.program = load()
        self.incus = IncusFixture()
        self.patch = patch('subprocess.run', self.incus.run)
        self.patch.start()
        self.addCleanup(self.patch.stop)

    def act(self, action):
        return self.program['main']({'action': action})[0]

    def test_converges_a_fresh_host_and_is_idempotent(self):
        self.assertEqual(self.act('converge'), 'converged')
        before = copy.deepcopy(self.incus.objects)
        self.assertEqual(self.act('converge'), 'converged')
        self.assertEqual(self.incus.objects, before)
        self.assertEqual(self.act('inspect'), 'matches')
        self.assertEqual(self.incus.objects['/1.0/storage-pools/default']['driver'], 'dir')
        self.assertEqual(self.incus.objects['/1.0/instances?project=orbit-ci'], [])
        config = self.incus.objects['/1.0/projects/orbit-ci']['config']
        self.assertEqual(config['restricted.containers.privilege'], 'unprivileged')
        self.assertEqual(config['restricted.containers.nesting'], 'block')
        self.assertEqual(config['limits.virtual-machines'], '0')
        profile = self.incus.objects['/1.0/profiles/default?project=orbit-ci']
        self.assertEqual(profile['config']['limits.cpu'], '6')
        self.assertEqual(profile['config']['limits.memory'], '12GiB')
        self.assertEqual(profile['devices']['eth0']['network'], 'orbitci0')

    def test_adopts_existing_setup_resources_without_changing_subnet_pool_or_runners(self):
        self.act('converge')
        self.incus.objects['/1.0/networks/orbitci0?project=default']['config']['ipv4.address'] = '10.253.2.1/24'
        self.incus.objects['/1.0/storage-pools/default'] = {'driver': 'zfs', 'config': {'source': 'fast/incus'}}
        self.incus.objects['/1.0/instances?project=orbit-ci'] = ['/1.0/instances/ci-shark-1?project=orbit-ci']
        before = copy.deepcopy(self.incus.objects)
        self.act('converge')
        self.assertEqual(self.incus.objects, before)
        self.assertFalse(any('launch' in call or 'exec' in call or 'stop' in call for call in self.incus.calls))

    def test_inspection_reports_each_boundary_drift_and_convergence_repairs_it(self):
        self.act('converge')
        cases = [
            ('/1.0/projects/orbit-ci', 'config', 'restricted.containers.nesting', 'allow'),
            ('/1.0/networks/orbitci0?project=default', 'config', 'security.acls', ''),
            ('/1.0/profiles/default?project=orbit-ci', 'config', 'security.privileged', 'true'),
            ('/1.0/profiles/default?project=orbit-ci', 'devices', 'host', {'type': 'disk', 'source': '/'}),
        ]
        for path, field, key, value in cases:
            with self.subTest(key=key):
                self.incus.objects[path][field][key] = value
                before = copy.deepcopy(self.incus.objects)
                self.assertEqual(self.act('inspect'), 'drift')
                self.assertEqual(self.incus.objects, before)
                self.assertEqual(self.act('converge'), 'converged')
        self.incus.objects['/1.0/network-acls/orbit-ci-egress?project=default']['egress'] = []
        self.assertEqual(self.act('inspect'), 'drift')
        self.assertEqual(self.act('converge'), 'converged')

    def test_acl_rejects_private_ipv4_and_all_ipv6_before_allowing_public_ipv4(self):
        self.act('converge')
        egress = self.incus.objects['/1.0/network-acls/orbit-ci-egress?project=default']['egress']
        self.assertEqual(egress[0]['action'], 'reject')
        for network in ('10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
                        '172.16.0.0/12', '192.168.0.0/16', '224.0.0.0/3'):
            self.assertIn(network, egress[0]['destination'].split(','))
        self.assertEqual(egress[1], {'action': 'reject', 'destination': '::/0', 'state': 'enabled'})
        self.assertEqual(egress[2], {'action': 'allow', 'destination': '0.0.0.0/0', 'state': 'enabled'})

    def test_removal_refuses_containers_and_never_deletes_them(self):
        self.act('converge')
        self.incus.objects['/1.0/instances?project=orbit-ci'] = ['/1.0/instances/ci-beast-1?project=orbit-ci']
        before = copy.deepcopy(self.incus.objects)
        self.assertEqual(self.act('remove'), 'occupied')
        self.assertEqual(self.act('purge'), 'occupied')
        self.assertEqual(self.incus.objects, before)

    def test_empty_removal_retains_resources_and_purge_keeps_the_shared_pool(self):
        self.act('converge')
        before = copy.deepcopy(self.incus.objects)
        self.assertEqual(self.act('remove'), 'retained')
        self.assertEqual(self.incus.objects, before)
        self.assertEqual(self.act('purge'), 'removed')
        self.assertIn('/1.0/storage-pools/default', self.incus.objects)
        self.assertEqual(self.act('purge'), 'removed')

    def test_incuses_errors_are_not_mistaken_for_an_absent_resource(self):
        with patch('subprocess.run', side_effect=subprocess.CalledProcessError(1, ['incus'])):
            with self.assertRaises(subprocess.CalledProcessError):
                self.act('converge')

    def test_inspection_of_an_unprepared_host_reports_drift_without_creating_resources(self):
        self.assertEqual(self.act('inspect'), 'drift')
        self.assertEqual(self.incus.objects, {})

    def test_the_bootstrap_contract_rejects_an_unknown_action(self):
        self.patch.stop()
        completed = subprocess.run(['bash', str(REMOTE.parent / 'task-vms/incus-host.sh'), '--ci-runner', 'register'], capture_output=True, check=False)
        self.assertNotEqual(completed.returncode, 0)
        self.assertIn(b'invalid CI host action', completed.stderr)
