"""A task VM caches the Ubuntu snapshot indexes in its own apt-cacher-ng, and every apt read of the snapshot goes through it."""
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'


def tasks(name):
    return yaml.safe_load((ANSIBLE / 'tasks' / name).read_text())


class TaskVmAptCacheTest(unittest.TestCase):
    def test_the_apt_configuration_runs_the_cache_first_and_reads_the_snapshot_through_it(self):
        config = tasks('task-vm-apt-config.yml')
        self.assertEqual(config[0]['ansible.builtin.import_tasks'], 'task-vm-apt-cache.yml')
        text = (ANSIBLE / 'tasks/task-vm-apt-config.yml').read_text()
        self.assertIn('URIs: http://snapshot.ubuntu.com/ubuntu/{{ recipe.ubuntu.snapshot }}/', text)
        self.assertNotIn('https://snapshot.ubuntu.com', text)
        self.assertIn('Acquire::http::Proxy "http://localhost:3142";', text)

    def test_the_cache_installs_from_the_own_sources_and_keeps_indexes_not_packages(self):
        by_name = {task['name']: task for task in tasks('task-vm-apt-cache.yml')}
        install = by_name['Install the package cache']
        self.assertEqual(install['ansible.builtin.apt']['name'], 'apt-cacher-ng')
        self.assertNotIn('APT_CONFIG', install['environment'], 'the cache comes before the snapshot configuration')
        self.assertEqual(install['until'], 'apt_cache_install is succeeded')
        self.assertEqual((install['retries'], install['delay']), ('{{ apt_retries }}', '{{ apt_delay }}'))
        config = by_name['Keep the packages out of the cache and the indexes in']['ansible.builtin.copy']
        self.assertIn(r'DontCacheRequested: \.deb$', config['content'])
        self.assertIn('SPfilePatternEx: /ubuntu/[0-9]{8}T[0-9]{6}Z/', config['content'])
        service = by_name['Run the package cache']['ansible.builtin.systemd']
        self.assertEqual((service['name'], service['enabled']), ('apt-cacher-ng.service', True))
        self.assertIn('restarted', service['state'])

    def test_the_image_keeps_the_cache(self):
        text = (ANSIBLE / 'tasks/task-vm-image-cache.yml').read_text()
        self.assertNotIn('apt-cacher-ng', text, 'the downloads leave an image; the cache of the indexes stays in it')

    def test_the_end_check_reads_the_cache(self):
        text = (ANSIBLE / 'tasks/task-vm-check.yml').read_text()
        self.assertIn('apt-cacher-ng.service', text)
        self.assertIn("seen.cache == 'active'", text)


if __name__ == '__main__':
    unittest.main()
