"""Execute the real migration policy against disposable files and a fake Docker/OS boundary."""
import copy
import importlib.util
import json
import stat
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

FAMILY = Path(__file__).resolve().parent
INPUT = dict(recipe='recipe-fixture', snapshot='20261031T000000Z', architecture='amd64', docker_version='29.1.3', containerd_version='2.2.2', process_ids=['42'], process_states={'42': 'running'}, process_specs={'42': {'name': 'orbit-42', 'hash': 'same-spec', 'volumes': []}}, fallback=False)


def module(name, file):
    spec = importlib.util.spec_from_file_location(name, file)
    value = importlib.util.module_from_spec(spec)
    sys.modules[name] = value
    spec.loader.exec_module(value)
    return value


class DockerActions(unittest.TestCase):
    def setUp(self):
        self.d = module('docker_inventory', FAMILY / 'scripts/docker_inventory.py')
        self.a = module('docker_actions', FAMILY / 'scripts/actions.py')
        self.data = copy.deepcopy(INPUT)
        self.d.data = self.data
        exec((FAMILY / 'actions_fixture.py').read_text(), self.d.__dict__)
        self.addCleanup(self.d.workspace.cleanup)
        self.a.Path = lambda p: self.d.root / 'os-release' if p == '/etc/os-release' else Path(p)
        (self.d.root / 'os-release').write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')

    def run_action(self, name, item=None):
        value = self.a.action(name, self.data, item)
        self.assertNotIn('never-return-this', json.dumps(value))
        return value

    def inventory(self, mode='migrate'):
        value = self.a.inventory(self.data, mode, '11111111-1111-4111-8111-111111111111')
        self.assertNotIn('never-return-this', json.dumps(value))
        return value

    def prepare(self):
        inv = self.inventory()
        for name in ['disable-policy', 'stop']:
            for item in inv['container_ids']:
                self.run_action(name, item)
        for item in inv['unmanaged']:
            self.run_action('commit', item)
        for item in self.run_action('catalogue')['images']:
            self.run_action('archive', item)
        self.d.command(['systemctl', 'stop', 'docker.service', 'docker.socket', 'containerd.service'])
        for name in ['backup', 'sources', 'packages']:
            self.run_action(name)
        self.d.command(['systemctl', 'start', 'docker.service'])
        return inv

    def finish(self, inv):
        fallback = self.run_action('readiness')['fallback']
        if fallback:
            self.d.command(['systemctl', 'stop', 'docker.service', 'docker.socket', 'containerd.service'])
            self.run_action('fallback-config')
            self.d.command(['systemctl', 'start', 'docker.service'])
            for name, field in [('volume', 'volumes'), ('network', 'networks'), ('load', 'images'), ('tag', 'tags'), ('pull', 'unmanaged'), ('recreate', 'unmanaged')]:
                for item in self.run_action('catalogue')[field]:
                    self.run_action(name, item)
            # The native shared Process child owns managed recreation; this fixture stands in only for that boundary.
            self.d.containers.append(copy.deepcopy(next(c for c in self.a.load(self.data)['containers'] if self.d.managed(c))))
        for item in inv['container_ids']:
            self.run_action('restore-policy', item)
        for item in inv['unmanaged']:
            self.run_action('start', item)
        current = self.d.find('orbit-42')
        current['State'] = {'Running': True, 'Status': 'running'}
        self.d.check_data()
        return self.run_action('report')

    def test_success_retains_offline_metadata_volumes_and_private_inventory(self):
        result = self.finish(self.prepare())
        self.assertTrue(result['healthy'])
        self.assertEqual(self.d.swap_count, 1)
        self.assertEqual((self.d.JOURNAL / 'metadata/docker/volumes/metadata.db').read_text(), 'volume-metadata')
        self.assertFalse((self.d.JOURNAL / 'metadata/docker/overlay2').exists())
        self.assertEqual(stat.S_IMODE(self.d.JOURNAL.stat().st_mode), 0o700)
        self.assertEqual(stat.S_IMODE((self.d.JOURNAL / 'inventory.json').stat().st_mode), 0o600)
        state = self.a.load(self.data)
        self.assertTrue(all('Env' not in c['Config'] for c in state['containers'] if self.d.managed(c)))
        self.run_action('packages')
        self.assertEqual(self.d.swap_count, 1)

    def test_plan_does_not_create_inventory_or_change_host(self):
        before = self.d.original_source
        result = self.inventory('plan')
        self.assertTrue(result['planned'])
        self.assertFalse(self.d.JOURNAL.exists())
        self.assertEqual(self.d.source_path.read_bytes(), before)
        self.assertEqual(self.d.swap_count, 0)

    def test_explicit_fallback_recreates_unmanaged_and_preserves_volume_backing(self):
        self.data['fallback'] = True
        self.d.incompatible = True
        result = self.finish(self.prepare())
        self.assertTrue(result['fallback'])
        self.assertEqual(self.d.volumes[0]['Options']['device'], str(self.d.root / 'volume/_data'))
        self.assertTrue(self.d.find('foreign')['State']['Running'])
        self.assertFalse(self.d.find('foreign-stopped')['State']['Running'])

    def test_readiness_checks_once_and_refuses_without_fallback(self):
        self.d.incompatible = True
        self.prepare()
        before = len(self.d.commands)
        with self.assertRaisesRegex(self.d.Refused, ''):
            self.run_action('readiness')
        self.assertEqual(len(self.d.commands), before)
        self.assertFalse(self.d.DROPIN.exists())

    def test_inventory_refuses_foreign_process_and_private_file_drift(self):
        self.d.containers[2]['Config']['Labels']['orbit.process.spec'] = 'changed'
        with self.assertRaises(self.d.Refused):
            self.inventory()
        self.assertFalse(self.d.JOURNAL.exists())
        self.d.containers[2]['Config']['Labels']['orbit.process.spec'] = 'same-spec'
        self.inventory()
        (self.d.JOURNAL / 'inventory.json').chmod(0o644)
        with self.assertRaises(self.d.Refused):
            self.run_action('disable-policy', 'abc')
        self.assertEqual(self.d.swap_count, 0)

    def test_package_plan_refuses_unrelated_removals_and_retains_restart_policy_custody(self):
        self.d.simulation = 'Remv nvidia-container-toolkit [safe]\n'
        with self.assertRaises(self.d.Refused):
            self.prepare()
        self.assertEqual(self.d.swap_count, 0)
        self.assertFalse(self.d.POLICY.exists())
        self.d.check_data()

    def test_health_is_one_shot_and_never_starts_or_retries_containers(self):
        self.prepare()
        before = len(self.d.commands)
        with self.assertRaises(self.d.Refused):
            self.run_action('report')
        self.assertEqual(len(self.d.commands), before)

    def test_manual_ce_restore_then_policy_recovery_refuses_another_migration(self):
        inv = self.prepare()
        self.d.packages = {'docker-ce': 'ce-version', 'containerd.io': 'ce-version'}
        self.inventory('restore-policies')
        for item in inv['container_ids']:
            self.run_action('restore-policy', item)
        for item in inv['unmanaged']:
            self.run_action('start', item)
        self.d.find('orbit-42')['State'] = {'Running': True, 'Status': 'running'}
        self.a.action('report', self.data, mode='restore-policies')
        self.assertEqual(self.a.load(self.data)['recovery'], 'restored')
        with self.assertRaises(self.d.Refused):
            self.inventory('migrate')
        self.assertEqual(self.d.swap_count, 1)

    def test_shutdown_commit_ignores_a_tag_from_an_older_inventory(self):
        inv = self.inventory()
        self.d.changed = True
        for item in inv['container_ids']:
            self.run_action('stop', item)
        original = self.d.api
        queried = []
        def api(method, path, body=None, missing=False):
            if path.startswith('/images/orbit-docker-migration'):
                queried.append(self.d.urllib.parse.unquote(path))
                return None
            return original(method, path, body, missing)
        self.d.api = api
        self.run_action('commit', 'abc')
        self.assertEqual(len(queried), 1)
        self.assertIn('11111111-1111-4111-8111-111111111111_abc', queried[0])
        commits = [v for v in self.d.commands if v[:2] == ['docker', 'commit']]
        self.assertEqual(len(commits), 1)
        self.assertIn('11111111-1111-4111-8111-111111111111', commits[0][-1])

    def test_managed_containers_are_never_recreated_from_inspect_secrets(self):
        inv = self.prepare()
        with self.assertRaises(self.d.Refused):
            self.run_action('recreate', 'orbit')
        self.assertEqual(inv['process_ids'], ['42'])


if __name__ == '__main__':
    unittest.main()
