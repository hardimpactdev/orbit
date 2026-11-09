"""A target hub's own converge: every recorded peer must be loaded, and only a peer that was live before the change must
handshake again. Run on this machine with stand-in `wg` and `ip`."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')
# The stand-in answers from files in $STUB_DIR: `handshakes.txt` holds `key<TAB>epoch` lines, `peers.txt` the loaded keys.
WG = """#!/bin/sh
case "$*" in
  "show orbit peers") cat "$STUB_DIR/peers.txt" ;;
  "show orbit latest-handshakes") cat "$STUB_DIR/handshakes.txt" ;;
  *) exit 1 ;;
esac
"""
IP = '#!/bin/sh\n[ -f "$STUB_DIR/link" ]\n'
LIVE, LAPTOP, PARKED = 'LIVEKEY', 'LAPTOPKEY', 'PARKEDKEY'


@NEEDS_ANSIBLE
class HubConvergePeersTest(unittest.TestCase):
    def run_tasks(self, tasks, variables, files):
        """Run task dicts on this machine; returns the exit code, the output and the registered `hub_live` lines."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'bin').mkdir()
            for name, body in (('wg', WG), ('ip', IP)):
                (folder / 'bin' / name).write_text(body)
                (folder / 'bin' / name).chmod(0o755)
            for name, body in files.items():
                (folder / name).write_text(body)
            # The included wait polls once, so a missing handshake fails at once.
            wait = yaml.safe_load((ANSIBLE / 'tasks/node-converge-handshake.yml').read_text())
            wait[0].update({'retries': 1, 'delay': 0})
            (folder / 'node-converge-handshake.yml').write_text(yaml.safe_dump(wait))
            (folder / 'node-converge-hub-peers.yml').write_text((ANSIBLE / 'tasks/node-converge-hub-peers.yml').read_text())
            (folder / 'tasks.yml').write_text(yaml.safe_dump(tasks))
            after = {'ansible.builtin.copy': {'dest': str(folder / 'live.json'), 'content': '{{ hub_live.stdout_lines | default([]) | to_json }}'}}
            (folder / 'play.yml').write_text(json.dumps([{'hosts': 'hub', 'gather_facts': False, 'tasks': [
                {'ansible.builtin.include_tasks': str(folder / 'tasks.yml')}, after]}]))
            (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'hub': {
                'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
            result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(variables), str(folder / 'play.yml')],
                                    check=False, capture_output=True, text=True, timeout=120, env={
                                        **os.environ, 'PATH': f"{folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(folder),
                                        'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
            live = json.loads((folder / 'live.json').read_text()) if (folder / 'live.json').exists() else None
            return result.returncode, result.stdout + result.stderr, live

    @staticmethod
    def handshakes(**ages):
        now = int(time.time())
        return ''.join(f"{key}\t{0 if age is None else now - age}\n" for key, age in ages.items())

    def check(self, live_keys, loaded, **ages):
        include = {'ansible.builtin.include_tasks': 'node-converge-hub-peers.yml'}
        return self.run_tasks([include], {'hub_peer_keys': [LIVE, LAPTOP, PARKED], 'hub_live_keys': live_keys},
                              {'peers.txt': ''.join(f'{key}\n' for key in loaded), 'handshakes.txt': self.handshakes(**ages)})

    def read_live(self, link, **ages):
        read = next(task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-network.yml').read_text())
                    if task['name'] == "Read a target hub's live peers before any change")
        files = {'handshakes.txt': self.handshakes(**ages), **({'link': ''} if link else {})}
        return self.run_tasks([{key: value for key, value in read.items() if key != 'when'}], {}, files)

    def test_offline_recorded_peers_do_not_fail_the_hub_converge(self):
        # Operation 15006: the hub waited for orbit-ops, offline for 44 minutes, and the network phase failed.
        code, output, _ = self.check([LIVE], [LIVE, LAPTOP, PARKED], LIVEKEY=30, LAPTOPKEY=2664, PARKEDKEY=None)
        self.assertEqual(code, 0, output)

    def test_a_peer_that_was_live_before_the_change_must_handshake_again(self):
        code, output, _ = self.check([LIVE, LAPTOP], [LIVE, LAPTOP, PARKED], LIVEKEY=30, LAPTOPKEY=2664, PARKEDKEY=None)
        self.assertNotEqual(code, 0, output)
        self.assertIn('"attempts": 1', output)

    def test_a_recorded_peer_missing_from_the_hub_fails(self):
        code, output, _ = self.check([], [LIVE, LAPTOP], LIVEKEY=30, LAPTOPKEY=30)
        self.assertNotEqual(code, 0, output)
        self.assertIn('vpn.hub_peer_missing', output)

    def test_the_live_peers_are_those_with_a_handshake_in_the_last_120_seconds(self):
        code, output, live = self.read_live(True, LIVEKEY=30, IDLEKEY=170, LAPTOPKEY=2664, PARKEDKEY=None)
        self.assertEqual((code, live), (0, [LIVE]), output)

    def test_a_hub_without_its_interface_has_no_live_peer(self):
        code, output, live = self.read_live(False, LIVEKEY=30)
        self.assertEqual((code, live), (0, []), output)


class HubConvergePeersWiringTest(unittest.TestCase):
    def test_the_network_phase_reads_the_live_peers_before_it_changes_the_hub_and_waits_only_for_them(self):
        tasks = yaml.safe_load((ANSIBLE / 'tasks/node-converge-network.yml').read_text())
        names = [task['name'] for task in tasks]
        self.assertLess(names.index("Read a target hub's live peers before any change"), names.index('Reconcile a target hub exactly once'))
        check = tasks[names.index("Check a target hub's recorded peers")]
        self.assertEqual(check['ansible.builtin.include_tasks'], 'node-converge-hub-peers.yml')
        self.assertEqual(check['vars']['hub_live_keys'], '{{ hub_live.stdout_lines }}')
        self.assertNotIn("intent.network.hub.peer_keys }}\"", yaml.safe_dump([task for task in tasks if task.get('loop')]))

    def test_the_check_phase_requires_the_recorded_peers_without_waiting_for_a_handshake(self):
        play = next(play for play in yaml.safe_load((ANSIBLE / 'operation-node-converge.yml').read_text()) if play['hosts'] == 'target')
        check = next(task for task in play['tasks'] if task['name'] == 'Check the recorded hub peers without writes')
        self.assertEqual((check['ansible.builtin.include_tasks']['file'], check['vars']['hub_live_keys']),
                         ('tasks/node-converge-hub-peers.yml', []))


if __name__ == '__main__':
    unittest.main()
