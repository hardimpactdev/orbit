"""The hub converge prunes only the peer files of Nodes that are gone or being removed, decided when the prune runs."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PRUNE = ANSIBLE / 'tasks/node-converge-hub-prune.yml'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')


class HubPruneTest(unittest.TestCase):
    def prune(self, directory, keep, newest):
        """Run the prune task on this machine against a peer directory; returns the exit code, the output and the changed count."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'play.yml').write_text(yaml.safe_dump([{'name': 'Prune', 'hosts': 'hub', 'gather_facts': False,
                                                              'tasks': [{'ansible.builtin.import_tasks': str(PRUNE)}]}]))
            (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'hub': {
                'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
            plan = {'hub_plan': {'prune': {'directory': str(directory), 'keep': keep, 'newest': newest}}}
            result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(plan), str(folder / 'play.yml')],
                                    check=False, capture_output=True, text=True, timeout=120, env={
                                        **os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
            changed = [line for line in result.stdout.splitlines() if 'changed=' in line]
            return result.returncode, result.stdout + result.stderr, int(changed[-1].split('changed=')[1].split()[0]) if changed else -1

    @NEEDS_ANSIBLE
    def test_a_node_created_after_the_intent_keeps_its_peer_file(self):
        with tempfile.TemporaryDirectory() as directory:
            directory = Path(directory)
            # 3 and 4 are kept Nodes, 5 is gone, 7 is being removed, 9 was the newest Node at intent time.
            # 12 and 120 joined after the intent; stray and 007 are no Node's file; notes.txt is not a peer file.
            for name in ('3.conf', '4.conf', '5.conf', '7.conf', '9.conf', '12.conf', '120.conf', 'stray.conf', '007.conf', 'notes.txt'):
                (directory / name).write_text('[Peer]\n')
            code, output, changed = self.prune(directory, keep=[3, 4, 9], newest=9)
            self.assertEqual(code, 0, output)
            self.assertEqual(sorted(path.name for path in directory.iterdir()), ['12.conf', '120.conf', '3.conf', '4.conf', '9.conf', 'notes.txt'])
            self.assertEqual(changed, 1, output)

            # A rerun finds nothing to prune.
            code, output, changed = self.prune(directory, keep=[3, 4, 9], newest=9)
            self.assertEqual((code, changed), (0, 0), output)

    def test_the_hub_converge_runs_this_prune(self):
        tasks = {task['name']: task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub.yml').read_text())}
        self.assertEqual(tasks['Prune only removed peers']['ansible.builtin.import_tasks'], 'node-converge-hub-prune.yml')

    def test_node_add_loads_the_peer_files_the_hub_has_when_it_applies(self):
        shell = yaml.safe_load((ANSIBLE / 'tasks/node-add-hub-peers.yml').read_text())[0]['ansible.builtin.shell']
        # No snapshot of the admitted peers: a Node that joined after admission has only its file, and must stay loaded.
        self.assertNotIn('stdin', shell)
        self.assertIn('/etc/wireguard/orbit-peers.d/*.conf', shell['cmd'])
        plays = {play['name']: play['tasks'] for play in yaml.safe_load((ANSIBLE / 'operation-node-add.yml').read_text())}
        # A joining Node's hub and a hub that enrolls itself both load the hub's files.
        for play, task in (('Add the peer on the hub', 'Apply the hub peers'), ('Set up the tunnel', "Apply the hub's own peers")):
            imported = next(item for item in plays[play] if item['name'] == task)
            self.assertEqual(imported['ansible.builtin.import_tasks'], 'tasks/node-add-hub-peers.yml', play)


if __name__ == '__main__':
    unittest.main()
