"""Run the migration with a disposable filesystem and simulated Docker/apt boundaries.

Inspect/create fields follow moby/moby docker-v29.1.3/api/swagger.yaml (API 1.52).
"""
from contextlib import nullcontext
import copy
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

RESOURCE = Path(__file__).resolve().parents[2] / 'resources/machine-software'
module = types.ModuleType('migration')
exec(compile((RESOURCE / 'converge-node.py').read_text().split("if __name__ == '__main__':")[0]
             + '\n' + (RESOURCE / 'migrate-docker.py').read_text(), str(RESOURCE / 'migrate-docker.py'), 'exec'), module.__dict__)


class DockerMigrationTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.apt = self.root / 'apt'
        (self.apt / 'sources.list.d').mkdir(parents=True)
        self.source = self.apt / 'sources.list.d/docker.sources'
        self.source.write_text('Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: resolute\nComponents: stable\nSigned-By: /etc/apt/keyrings/docker.asc\n')
        self.source_before = self.source.read_bytes()
        self.daemon = self.root / 'daemon.json'
        self.daemon.write_text('{"runtimes":{"nvidia":{"path":"nvidia-container-runtime","runtimeArgs":[]}}}')
        self.daemon_before = self.daemon.read_bytes()
        self.data = self.root / 'volumes/mysql/_data'
        self.data.mkdir(parents=True)
        (self.data / 'database').write_text('irreplaceable')
        self.journal = self.root / 'journal'
        self.journal.mkdir(mode=0o700)
        self.dropin = self.root / 'systemd/docker.service.d/orbit-docker-migration.conf'
        self.policy = self.root / 'policy-rc.d'
        self.payload = {'recipe': 'sha256:fixture', 'snapshot': '20261014T000000Z', 'architecture': 'amd64',
                        'docker_version': '29.1.3-0ubuntu4.1', 'process_ids': ['7'], 'process_states': {'7': 'running'},
                        'process_specs': {'7': {'name': 'orbit-process-7-mysql', 'hash': 'spec-hash',
                                                'volumes': [{'source': 'mysql', 'target': '/data'}]}}}
        self.original = {
            'one': self.container('one', 'mailpit'),
            'two': self.container('two', 'orbit-process-7-mysql', orbit=True),
            'three': self.container('three', 'stopped', running=False),
        }
        self.containers = copy.deepcopy(self.original)
        self.volumes = {'mysql': {'Name': 'mysql', 'Driver': 'local', 'Mountpoint': str(self.data), 'Options': None, 'Labels': {}}}
        self.networks = [{'Id': 'bridge-id', 'Name': 'bridge', 'Driver': 'bridge', 'Scope': 'local'},
                         {'Id': 'a' * 64, 'Name': 'app-network', 'Driver': 'bridge', 'Scope': 'local',
                          'Internal': False, 'Attachable': False, 'EnableIPv4': True, 'EnableIPv6': False,
                          'IPAM': {'Driver': 'default', 'Config': [{'Subnet': '172.30.0.0/24', 'Gateway': '172.30.0.1'}]},
                          'Options': {}, 'Labels': {}}]
        self.pinned_image = 'registry.example/app@sha256:' + 'a' * 64
        self.images = [{'Id': 'sha256:img', 'RepoDigests': [self.pinned_image], 'Architecture': 'amd64', 'Os': 'linux'}]
        self.installed = dict.fromkeys(module.CE, 'ce-version')
        self.commands = []
        self.requests = []
        self.service = True
        self.incompatible = False
        self.changes = []
        self.fail_create = None
        self.simulation = 'Remv docker-ce [ce-version]\nRemv containerd.io [ce-version]\n'
        original_publish = module.publish
        replacements = {'APT_ETC': self.apt, 'JOURNAL': self.journal, 'DAEMON': self.daemon,
                        'DROPIN': self.dropin, 'POLICY': self.policy, 'api': self.api, 'run': self.command,
                        'packages': lambda: dict(self.installed), 'temporary_indexes': lambda source: nullcontext([]),
                        'publish': lambda path, content, mode=0o644: original_publish(path, content, mode, os.getuid(), os.getgid())}
        for name, value in replacements.items():
            fixture = patch.object(module, name, value)
            fixture.start()
            self.addCleanup(fixture.stop)

    def container(self, identifier, name, orbit=False, running=True):
        labels = {'orbit.managed': 'true', 'orbit.container.kind': 'process', 'orbit.process.id': '7', 'orbit.process.spec': 'spec-hash'} if orbit else {'app': 'fixture'}
        return {'Id': identifier, 'Name': '/' + name, 'Image': 'sha256:img',
                'Config': {'Image': 'registry.example/app:stable', 'Env': ['PASSWORD=secret'], 'Labels': labels,
                           'Entrypoint': ['/app'], 'Cmd': ['serve'], 'WorkingDir': '/work'},
                'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped', 'MaximumRetryCount': 0},
                               'Binds': ['mysql:/data:rw'], 'Runtime': 'runc' if orbit else 'nvidia', 'NetworkMode': 'default' if orbit else 'app-network',
                               'PortBindings': {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '8080'}]}},
                'Mounts': [{'Type': 'volume', 'Name': 'mysql', 'Source': str(self.data), 'Destination': '/data', 'RW': True}],
                'NetworkSettings': {'Networks': {'bridge' if orbit else 'app-network':
                                                {'IPAMConfig': None, 'Aliases': None if orbit else ['app'], 'GwPriority': 0 if orbit else 7}}},
                'State': {'Running': running, 'Status': 'running' if running else 'exited'}}

    def api(self, method, path, body=None, *, missing=False):
        self.requests.append((method, path, copy.deepcopy(body)))
        if not self.service:
            raise OSError('service unavailable')
        if path == '/info':
            return {'DockerRootDir': '/var/lib/docker'}
        if path == '/containers/json?all=1':
            return [{'Id': c['Id']} for c in self.containers.values()]
        if path.startswith('/containers/') and path.endswith('/changes'):
            return self.changes
        if path.startswith('/containers/') and path.endswith('/json'):
            name = path.split('/')[2].replace('%2F', '/')
            for c in self.containers.values():
                if name in (c['Id'], c['Name'], c['Name'].lstrip('/')):
                    return copy.deepcopy(c)
            if missing:
                return None
            raise module.RecipeFailure('docker-api')
        if path == '/volumes':
            return {'Volumes': list(self.volumes.values())}
        if path.startswith('/volumes/') and method == 'GET':
            return copy.deepcopy(self.volumes.get(path.split('/')[2]))
        if path == '/volumes/create':
            self.volumes[body['Name']] = {'Name': body['Name'], 'Options': body['DriverOpts']}
            return self.volumes[body['Name']]
        if path == '/networks':
            return self.networks
        if path == '/networks/create':
            network = dict(body, Id='new-' + body['Name'], Scope='local')
            self.networks.append(network)
            return {'Id': network['Id']}
        if path.startswith('/networks/'):
            name = path.rsplit('/', 1)[1]
            return next((n for n in self.networks if name in (n['Id'], n['Name'])), None)
        if path == '/images/json?all=1':
            return self.images
        if path.startswith('/images/'):
            return None if self.incompatible and 'docker.io' in self.installed and not getattr(self, 'fresh', False) else copy.deepcopy(self.images[0])
        if path.startswith('/containers/create?name='):
            name = path.split('name=')[1]
            if self.fail_create == name:
                self.fail_create = None
                raise module.RecipeFailure('docker-api')
            identifier = 'new-' + name
            c = self.container(identifier, name, running=False)
            c['Config'] = {k: v for k, v in body.items() if k not in ('HostConfig', 'NetworkingConfig')}
            c['HostConfig'] = body['HostConfig']
            self.containers[identifier] = c
            return {'Id': identifier}
        raise AssertionError((method, path, body))

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        stdout = ''
        if argv[:2] == ['apt-get', 'install']:
            if '--simulate' in argv:
                stdout = self.simulation
            else:
                self.installed = {'docker.io': self.payload['docker_version'], 'containerd': '2.2.2-0ubuntu1.1',
                                  'docker-buildx': 'fixture', 'docker-compose-v2': 'fixture'}
        elif argv[:2] == ['systemctl', 'stop']:
            self.service = False
        elif argv[:2] == ['systemctl', 'start']:
            self.service = True
            if self.dropin.exists() and not getattr(self, 'fresh', False):
                self.fresh = True
                self.containers = {}
                self.volumes = {}
                self.networks = self.networks[:1]
        elif argv[:2] == ['docker', 'stop']:
            c = self.containers[argv[-1]]
            c['State'] = {'Running': False, 'Status': 'exited'}
        elif argv[:2] == ['docker', 'start']:
            for c in self.containers.values():
                if c['Name'].lstrip('/') == argv[-1]:
                    c['State'] = {'Running': True, 'Status': 'running'}
        return subprocess.CompletedProcess(argv, 0, stdout, '')

    def test_fallback_reuses_explicit_bridge_name_and_resumes_without_duplicate_network(self):
        self.networks[1]['Options']['com.docker.network.bridge.name'] = 'app-br'
        self.incompatible = True
        module.migrate(self.payload, 'prepare')
        state = json.loads((self.journal / 'inventory.json').read_text())
        self.assertEqual(self.networks[1]['Options']['com.docker.network.bridge.name'], 'app-br')
        module.fresh_engine(state)
        self.assertEqual(len([r for r in self.requests if r[:2] == ('POST', '/networks/create')]), 1)

    def test_fallback_refuses_existing_network_with_wrong_bridge_or_configuration(self):
        self.incompatible = True
        module.migrate(self.payload, 'prepare')
        state = json.loads((self.journal / 'inventory.json').read_text())
        expected = copy.deepcopy(self.networks[1])
        for change in ({'Options': {'com.docker.network.bridge.name': 'wrong-br'}}, {'Internal': True},
                       {'IPAM': {'Driver': 'default', 'Config': [{'Subnet': '172.31.0.0/24'}]}}):
            self.networks[1] = dict(expected, **change)
            with self.subTest(change=change), self.assertRaisesRegex(module.RecipeFailure, 'network-conflict'):
                module.fresh_engine(state)
        self.assertEqual(len([r for r in self.requests if r[:2] == ('POST', '/networks/create')]), 1)

    def test_dry_run_inventory_plan_has_no_writes_or_stops_and_no_secrets(self):
        before = {str(p): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        report = module.migrate(self.payload, 'plan')
        self.assertEqual(report['containers'], ['mailpit', 'orbit-process-7-mysql', 'stopped'])
        self.assertIn('docker.io=29.1.3-0ubuntu4.1', report['packages'])
        self.assertIn('docker-ce-', report['packages'])
        self.assertEqual(report['source_backups'], [str(self.apt / 'docker.sources.orbit-docker-backup')])
        self.assertIn('reconcile Orbit Processes from stored specs', report['steps'])
        self.assertNotIn('secret', json.dumps(report))
        self.assertTrue(all(argv[:3] == ['docker', 'manifest', 'inspect'] for argv in self.commands))
        self.assertEqual(before, {str(p): p.read_bytes() for p in self.root.rglob('*') if p.is_file()})

    def test_refuses_unrecreatable_container_before_stopping_or_recording(self):
        for key, value in [('AutoRemove', True), ('NetworkMode', 'container:other'), ('VolumesFrom', ['other'])]:
            with self.subTest(key=key):
                self.containers['one']['HostConfig'][key] = value
                with self.assertRaises(module.RecipeFailure):
                    module.migrate(self.payload, 'prepare')
                del self.containers['one']['HostConfig'][key]
        self.changes = [{'Kind': 1, 'Path': '/important-data'}]
        with self.assertRaisesRegex(module.RecipeFailure, 'writable-layer'):
            module.migrate(self.payload, 'prepare')
        self.assertEqual(self.commands, [])
        self.assertFalse((self.journal / 'inventory.json').exists())

    def test_success_keeps_daemon_and_volume_data_and_retires_sources_with_backup(self):
        report = module.migrate(self.payload, 'prepare')
        self.assertFalse(report['fallback'])
        self.assertEqual(report['process_ids'], ['7'])
        self.assertEqual(self.daemon.read_bytes(), self.daemon_before)
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')
        self.assertFalse(self.source.exists())
        self.assertEqual((self.apt / 'docker.sources.orbit-docker-backup').read_bytes(), self.source_before)
        saved = json.loads((self.journal / 'inventory.json').read_text())
        self.assertEqual(saved['containers'], list(self.original.values()))
        self.assertEqual((self.journal / 'inventory.json').stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.installed['containerd'], '2.2.2-0ubuntu1.1')
        self.assertFalse(self.policy.exists())
        self.assertFalse(self.dropin.exists())
        self.assertFalse(any(method == 'POST' for method, path, body in self.requests))
        self.assertFalse(module.migrate(self.payload, 'report')['healthy'])  # Gateway must restart its Process.
        self.command(['docker', 'start', 'orbit-process-7-mysql'])
        self.assertTrue(module.migrate(self.payload, 'report')['healthy'])

    def test_fallback_recreates_non_orbit_with_every_recorded_setting_and_reuses_volume_paths(self):
        self.incompatible = True
        report = module.migrate(self.payload, 'prepare')
        self.assertTrue(report['fallback'])
        self.assertIn('--data-root=' + str(self.journal / 'engine'), self.dropin.read_text())
        self.assertNotIn('--containerd', self.dropin.read_text())
        self.assertEqual(self.volumes['mysql']['Options']['device'], str(self.data))
        creates = [body for method, path, body in self.requests if path.startswith('/containers/create')]
        self.assertEqual(len(creates), 2)  # Orbit container is recreated by stored specs, not inspect.
        body = creates[0]
        self.assertEqual(body['Env'], ['PASSWORD=secret'])
        self.assertEqual(body['Labels'], {'app': 'fixture', 'orbit.docker.migration': 'one'})
        self.assertEqual(body['HostConfig'], self.original['one']['HostConfig'])
        self.assertEqual(body['NetworkingConfig']['EndpointsConfig']['app-network']['Aliases'], ['app'])
        self.assertEqual(body['NetworkingConfig']['EndpointsConfig']['app-network']['GwPriority'], 7)
        self.assertEqual(self.networks[1]['IPAM']['Config'][0]['Subnet'], '172.30.0.0/24')
        self.assertEqual(self.networks[1]['Options']['com.docker.network.bridge.name'], 'br-' + 'a' * 12)
        self.assertTrue(all(argv[-1] == self.pinned_image for argv in self.commands if argv[:2] == ['docker', 'pull']))
        self.assertIn(['docker', 'tag', self.pinned_image, 'registry.example/app:stable'], self.commands)
        self.assertEqual(self.daemon.read_bytes(), self.daemon_before)
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')
        self.assertEqual(report['containers'][2]['state'], 'exited')
        self.assertNotIn('secret', json.dumps(report))

    def test_refuses_newer_api_settings_and_unrecorded_process_volumes(self):
        self.containers['one']['HostConfig']['FutureStorageMode'] = 'not-supported'
        with self.assertRaisesRegex(module.RecipeFailure, 'newer-engine-configuration'):
            module.migrate(self.payload, 'prepare')
        self.assertEqual(self.commands, [])
        del self.containers['one']['HostConfig']['FutureStorageMode']
        self.images[0]['RepoDigests'] = []
        with self.assertRaisesRegex(module.RecipeFailure, 'unpullable-image'):
            module.migrate(self.payload, 'prepare')
        self.images[0]['RepoDigests'] = [self.pinned_image]
        self.payload['process_specs']['7']['volumes'] = []
        with self.assertRaisesRegex(module.RecipeFailure, 'orbit-unrecorded-mount'):
            module.migrate(self.payload, 'prepare')
        self.assertFalse((self.journal / 'inventory.json').exists())
        self.assertFalse(any(argv[:2] == ['docker', 'stop'] for argv in self.commands))

    def test_anonymous_mount_api_reuses_original_volume_name(self):
        self.incompatible = True
        self.containers['one']['HostConfig']['Binds'] = []
        self.containers['one']['HostConfig']['Mounts'] = [{'Type': 'volume', 'Target': '/data', 'Source': ''}]
        module.migrate(self.payload, 'prepare')
        body = next(body for method, path, body in self.requests if path == '/containers/create?name=mailpit')
        self.assertEqual(body['HostConfig']['Mounts'][0]['Source'], 'mysql')
        self.assertEqual(self.volumes['mysql']['Options']['device'], str(self.data))
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')

    def test_old_engine_start_failure_uses_fresh_engine(self):
        command = self.command
        def fail_original(argv, **kwargs):
            if argv[:2] == ['systemctl', 'start'] and not self.dropin.exists():
                raise module.RecipeFailure('engine-start')
            return command(argv, **kwargs)
        with patch.object(module, 'run', fail_original):
            report = module.migrate(self.payload, 'prepare')
        self.assertTrue(report['fallback'])
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')

    def test_changed_stored_spec_refuses_resume_without_any_new_commands(self):
        module.migrate(self.payload, 'prepare')
        before = len(self.commands)
        self.payload['process_specs']['7']['hash'] = 'changed'
        with self.assertRaisesRegex(module.RecipeFailure, 'orbit-spec-changed'):
            module.migrate(self.payload, 'prepare')
        self.assertEqual(len(self.commands), before)

    def test_shutdown_writable_changes_refuse_before_package_swap(self):
        command = self.command
        def shutdown_changes(argv, **kwargs):
            result = command(argv, **kwargs)
            if argv[:2] == ['docker', 'stop']:
                self.changes = [{'Kind': 1, 'Path': '/unmounted-database'}]
            return result
        with patch.object(module, 'run', shutdown_changes), self.assertRaisesRegex(module.RecipeFailure, 'writable-layer'):
            module.migrate(self.payload, 'prepare')
        self.assertIn('docker-ce', self.installed)
        self.assertTrue(self.source.exists())
        self.assertFalse(any(argv[:2] == ['apt-get', 'install'] for argv in self.commands))

    def test_report_includes_unreadable_containers_and_fails_health(self):
        module.migrate(self.payload, 'prepare')
        self.service = False
        report = module.migrate(self.payload, 'report')
        self.assertFalse(report['healthy'])
        self.assertEqual([c['state'] for c in report['containers']], ['unreadable'] * 3)

    def test_resumes_partial_fallback_without_second_swap_or_replacing_original_inventory(self):
        self.incompatible = True
        self.fail_create = 'stopped'
        with self.assertRaisesRegex(module.RecipeFailure, 'docker-api'):
            module.migrate(self.payload, 'prepare')
        saved = json.loads((self.journal / 'inventory.json').read_text())
        self.assertTrue(saved['fallback'])
        self.assertFalse(saved['prepared'])
        report = module.migrate(self.payload, 'prepare')
        self.assertTrue(report['fallback'])
        installs = [a for a in self.commands if a[:2] == ['apt-get', 'install'] and '--yes' in a]
        self.assertEqual(len(installs), 1)
        pulls = [a for a in self.commands if a[:2] == ['docker', 'pull']]
        self.assertEqual(len(pulls), 3)  # First container is not pulled or recreated twice.
        self.assertEqual(json.loads((self.journal / 'inventory.json').read_text())['containers'], saved['containers'])
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')

    def test_resumes_after_package_failure_with_daemon_stopped(self):
        self.simulation += 'Remv unrelated [1.0]\n'
        with self.assertRaisesRegex(module.RecipeFailure, 'unrelated-package-removal'):
            module.migrate(self.payload, 'prepare')
        self.assertFalse(self.service)
        self.simulation = 'Remv docker-ce [ce-version]\n'
        report = module.migrate(self.payload, 'prepare')
        self.assertFalse(report['fallback'])
        self.assertEqual(self.installed['docker.io'], self.payload['docker_version'])
        self.assertEqual((self.apt / 'docker.sources.orbit-docker-backup').read_bytes(), self.source_before)

    def test_refuses_recipe_or_daemon_drift_on_resume(self):
        module.migrate(self.payload, 'prepare')
        with self.assertRaisesRegex(module.RecipeFailure, 'recipe-mismatch'):
            module.migrate(dict(self.payload, recipe='other'), 'prepare')
        self.daemon.write_text('{}')
        with self.assertRaisesRegex(module.RecipeFailure, 'daemon-changed'):
            module.migrate(self.payload, 'prepare')

    def test_list_sources_retire_only_docker_lines_and_backup_collision_refuses(self):
        self.source.unlink()
        source = self.apt / 'sources.list.d/docker.list'
        before = b'deb [arch=amd64 signed-by=/key] https://download.docker.com/linux/ubuntu resolute stable\ndeb https://example.org/ubuntu resolute main\n'
        source.write_bytes(before)
        changes = module.docker_sources()
        self.assertEqual(changes, [(source, before, b'deb https://example.org/ubuntu resolute main\n')])
        (self.apt / 'docker.list.orbit-docker-backup').write_text('different original')
        with self.assertRaisesRegex(module.RecipeFailure, 'apt-source'):
            module.migrate(self.payload, 'prepare')
        self.assertTrue(all(argv[:3] == ['docker', 'manifest', 'inspect'] for argv in self.commands))

    def test_convergence_detects_ce_and_refuses_before_plan_or_apply(self):
        for mode in ('inspect', 'apply'):
            with patch.object(module, 'platform_check'), patch.object(module, 'plan') as plan, \
                 patch.object(module, 'apply') as apply, patch.object(module.sys, 'argv', ['program', mode, json.dumps(self.payload)]), \
                 patch('sys.stdout', new_callable=io.StringIO) as output:
                self.assertEqual(module.main(), 1)
                self.assertEqual(output.getvalue().strip(), 'recipe-stage:docker-provider:docker-ce')
                plan.assert_not_called()
                apply.assert_not_called()


if __name__ == '__main__':
    unittest.main()
