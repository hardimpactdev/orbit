"""Pin what keeps the container model forwarder fail-closed: the playbook's units, its reload, its order, and its end checks."""
import unittest
from pathlib import Path

import yaml

PLAYBOOK = Path(__file__).resolve().parents[1] / 'ansible/ai-router-model-forwarder.yml'


def tasks(play):
    """Every task of a play, the tasks of its blocks and their `always` included."""
    found = []
    for task in play['tasks']:
        found.append(task)
        for key in ('block', 'always'):
            found.extend(task.get(key, []))
    return found


class ModelForwarderPlaybookTest(unittest.TestCase):
    def setUp(self):
        self.plays = {play['name']: play for play in yaml.safe_load(PLAYBOOK.read_text())}
        self.apply = {task['name']: task for task in tasks(self.plays['Apply the forwarder'])}
        self.check = {task['name']: task for task in tasks(self.plays['Check the forwarder'])}

    def test_table_unit_loads_after_nftables_and_reloads_in_place(self):
        unit = self.apply['Write the unit of the table']['ansible.builtin.copy']['content']
        self.assertIn('After=local-fs.target nftables.service', unit)
        self.assertIn('DefaultDependencies=no', unit)
        self.assertIn('ExecReload=/usr/sbin/nft --file', unit)
        # Its stop deletes the table, so the run never restarts it.
        self.assertEqual(self.apply['Load the table']['ansible.builtin.systemd_service']['state'], 'started')
        self.assertEqual(self.apply['Load a changed table in place']['ansible.builtin.command']['argv'][:2], ['nft', '--file'])

    def test_forwarder_runs_only_with_its_table(self):
        unit = self.apply['Write the unit']['ansible.builtin.copy']['content']
        self.assertIn('BindsTo={{ intent.table_unit }}', unit)
        self.assertIn('After=network-online.target wg-quick@orbit.service incus.service {{ intent.table_unit }}', unit)
        self.assertIn('ExecStartPre=+/usr/sbin/nft list table inet {{ intent.table }}', unit)
        self.assertIn('User={{ intent.user }}', unit)
        self.assertNotIn('DynamicUser', unit)

    def test_table_comes_before_the_forwarder(self):
        names = list(self.apply)
        self.assertLess(names.index('Load the table'), names.index('Run the forwarder'))

    def test_checks_cover_every_path(self):
        for name in ('Check that the guard answers a container through the forwarder', 'Check that the forwarder\'s table dropped it',
                     'Check that the output rule reset it', 'Check that the input rule reset it'):
            self.assertIn(name, self.check)
        relayed = self.check['Check that the Node\'s own processes cannot use the forwarder']['ansible.builtin.command']['argv']
        self.assertEqual(relayed[-1], '{{ intent.origin }}/v1/responses')


if __name__ == '__main__':
    unittest.main()
