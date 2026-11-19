"""The hub converge prunes only the peer files of Nodes that are gone or being removed, decided when the prune runs, and
removes the task VM hub filter that the removed `task_vm.hub_converge` installed, with the UFW route rules of the task VM range."""
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
FILTER = ANSIBLE / 'tasks/node-converge-hub-filter-absent.yml'
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
        tasks = {task['name']: task for top in yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub.yml').read_text())
                 for task in [top, *top.get('block', [])]}
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


# Stand-ins answer from marker files in $STUB_DIR: `active`, `enabled` and `table`; each call is logged to `calls.log`.
SYSTEMCTL = """#!/bin/sh
echo "systemctl $*" >> "$STUB_DIR/calls.log"
case "$1" in
  show)
    if [ -f "$STUB_DIR/etc/systemd/system/orbit-task-vms-hub.service" ]; then echo LoadState=loaded; else echo LoadState=not-found; fi
    if [ -f "$STUB_DIR/active" ]; then echo ActiveState=active; else echo ActiveState=inactive; fi ;;
  is-enabled) if [ -f "$STUB_DIR/enabled" ]; then echo enabled; else echo disabled; exit 1; fi ;;
  stop) rm -f "$STUB_DIR/active" ;;
  disable) rm -f "$STUB_DIR/enabled" ;;
  daemon-reload) ;;
  *) exit 1 ;;
esac
"""
NFT = """#!/bin/sh
echo "nft $*" >> "$STUB_DIR/calls.log"
case "$*" in
  "list table inet orbit_task_vms") [ -f "$STUB_DIR/table" ] || { echo 'Error: No such file or directory' >&2; exit 1; } ;;
  "delete table inet orbit_task_vms") rm "$STUB_DIR/table" ;;
  *) exit 1 ;;
esac
"""
# `ufw status numbered` and `ufw --force delete <n>` over $STUB_DIR/ufw.rules, one rule per line as ufw prints it after its number.
UFW = r"""#!/bin/sh
echo "ufw $*" >> "$STUB_DIR/calls.log"
rules="$STUB_DIR/ufw.rules"
case "$*" in
  "status numbered")
    printf 'Status: active\n\n     To                         Action      From\n     --                         ------      ----\n'
    [ -f "$rules" ] && awk '{ printf "[%2d] %s\n", NR, $0 }' "$rules"
    echo ;;
  "--force delete "*)
    n="$3"; total=$(wc -l < "$rules")
    [ "$n" -ge 1 ] && [ "$n" -le "$total" ] || { echo "ERROR: Could not find rule '$n'" >&2; exit 1; }
    sed -i "${n}d" "$rules"; echo 'Rule deleted' ;;
  *) exit 1 ;;
esac
"""

# The route rules that the task VM hub left on the live vpn Node, exactly as `ufw status` shows them.
TASK_VM_ROUTES = [
    '10.44.0.128/25 22/tcp on orbit ALLOW FWD 10.44.0.15 on orbit # orbit:task-vm-control-ssh',
    '10.44.0.128/25 80/tcp ALLOW FWD 10.44.0.7 # orbit:task-vm-router-web-10-44-0-7-80',
    '10.44.0.128/25 443/tcp ALLOW FWD 10.44.0.7 # orbit:task-vm-router-web-10-44-0-7-443',
    '10.44.0.128/25 443/tcp ALLOW FWD 10.44.0.11 # orbit:task-vm-router-web-10-44-0-11-443',
    '10.44.0.128/25 80/tcp ALLOW FWD 10.44.0.11 # orbit:task-vm-router-web-10-44-0-11-80',
    '10.44.0.128/25 443/tcp ALLOW FWD 10.44.0.15 # orbit:task-vm-control-web',
    '10.44.0.128/25 3774/tcp ALLOW FWD 10.44.0.15 # orbit:task-vm-swamp-pi',
]
# Rules that stay: other orbit route rules, a task bridge input rule whose comment only starts alike, and an uncommented rule.
OTHER_RULES = [
    'Anywhere on orbit ALLOW IN 10.44.0.0/24 # orbit:wireguard-members',
    '10.44.0.1 22/tcp on orbit ALLOW FWD 192.168.1.0/24 on eth1 # orbit:vpn-lan-ssh',
    'Anywhere on orbitci0 ALLOW FWD Anywhere # orbit-ci-runners',
    '10.252.10.1 53/udp on orbittask0 ALLOW IN Anywhere # orbit:task-vms-dns-udp',
    '10.44.0.128/25 9000/tcp ALLOW FWD 10.44.0.20',
    '10.44.0.3 8318/tcp on orbit ALLOW IN 10.44.0.128/25 # orbit:ai-router-task-guard',
]


@NEEDS_ANSIBLE
class HubFilterAbsentTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.folder)
        (self.folder / 'bin').mkdir()
        for name, body in (('systemctl', SYSTEMCTL), ('nft', NFT), ('ufw', UFW)):
            (self.folder / 'bin' / name).write_text(body)
            (self.folder / 'bin' / name).chmod(0o755)
        # The task file with its absolute paths moved under the test folder.
        (self.folder / 'tasks.yml').write_text(FILTER.read_text().replace('/etc/', f'{self.folder}/etc/'))

    def install_filter(self):
        """The footprint the removed task_vm.hub_converge left on the vpn Node."""
        for path in ('etc/systemd/system/orbit-task-vms-hub.service', 'etc/orbit/task-vms/hub.nft'):
            (self.folder / path).parent.mkdir(parents=True, exist_ok=True)
            (self.folder / path).write_text('managed by Orbit\n')
        for marker in ('active', 'enabled', 'table'):
            (self.folder / marker).write_text('')

    def converge(self):
        """Run the filter tasks on this machine; returns the exit code, the output, the changed count and the stand-in calls."""
        (self.folder / 'calls.log').write_text('')
        (self.folder / 'play.yml').write_text(json.dumps([{'hosts': 'hub', 'gather_facts': False, 'tasks': [
            {'ansible.builtin.import_tasks': str(self.folder / 'tasks.yml')}]}]))
        (self.folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'hub': {
            'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
        result = subprocess.run([COMMAND, '-i', str(self.folder / 'inventory.yml'), str(self.folder / 'play.yml')],
                                check=False, capture_output=True, text=True, timeout=120, env={
                                    **os.environ, 'PATH': f"{self.folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(self.folder),
                                    'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
        changed = [line for line in result.stdout.splitlines() if 'changed=' in line]
        return (result.returncode, result.stdout + result.stderr, int(changed[-1].split('changed=')[1].split()[0]) if changed else -1,
                (self.folder / 'calls.log').read_text().splitlines())

    def remaining(self):
        return sorted(str(path.relative_to(self.folder)) for path in self.folder.rglob('*')
                      if path.relative_to(self.folder).parts[0] in ('etc', 'active', 'enabled', 'table'))

    def test_a_hub_with_the_filter_ends_without_it(self):
        self.install_filter()
        (self.folder / 'etc/systemd/system/other.service').write_text('')
        code, output, changed, calls = self.converge()
        self.assertEqual(code, 0, output)
        # Five tasks change: the stop and disable, the two files, the reload, the empty folder and the table.
        self.assertEqual(changed, 5, output)
        self.assertEqual(self.remaining(), ['etc', 'etc/orbit', 'etc/systemd', 'etc/systemd/system', 'etc/systemd/system/other.service'])
        self.assertIn('systemctl daemon-reload', calls)
        self.assertIn('nft delete table inet orbit_task_vms', calls)

    def test_a_second_run_changes_nothing(self):
        self.install_filter()
        self.assertEqual(self.converge()[0], 0)
        code, output, changed, calls = self.converge()
        self.assertEqual((code, changed), (0, 0), output)
        self.assertEqual(calls, ['nft list table inet orbit_task_vms', 'ufw status numbered'])

    def test_a_hub_that_never_had_the_filter_changes_nothing(self):
        code, output, changed, calls = self.converge()
        self.assertEqual((code, changed), (0, 0), output)
        # The failing table read is the answer that the table is absent, not an error.
        self.assertEqual(calls, ['nft list table inet orbit_task_vms', 'ufw status numbered'])
        self.assertEqual(self.remaining(), [])

    def test_a_task_vm_folder_with_other_files_stays(self):
        self.install_filter()
        (self.folder / 'etc/orbit/task-vms/notes.txt').write_text('')
        code, output, _, _ = self.converge()
        self.assertEqual(code, 0, output)
        self.assertIn('etc/orbit/task-vms/notes.txt', self.remaining())
        self.assertNotIn('etc/orbit/task-vms/hub.nft', self.remaining())

    def test_a_stopped_and_disabled_unit_loses_only_its_files(self):
        self.install_filter()
        for marker in ('active', 'enabled', 'table'):
            (self.folder / marker).unlink()
        code, output, changed, calls = self.converge()
        # The two files, the reload and the empty folder; no stop, disable or table delete.
        self.assertEqual((code, changed), (0, 3), output)
        self.assertFalse([call for call in calls if call.startswith(('systemctl stop', 'systemctl disable', 'nft delete'))], calls)
        self.assertEqual(self.remaining(), ['etc', 'etc/orbit', 'etc/systemd', 'etc/systemd/system'])

    def test_a_table_left_alone_is_deleted(self):
        (self.folder / 'table').write_text('')
        code, output, changed, calls = self.converge()
        self.assertEqual((code, changed), (0, 1), output)
        self.assertEqual(calls, ['nft list table inet orbit_task_vms', 'nft delete table inet orbit_task_vms', 'ufw status numbered'])
        self.assertEqual(self.remaining(), [])

    def rules(self, lines):
        (self.folder / 'ufw.rules').write_text(''.join(line + '\n' for line in lines))

    def test_the_task_vm_route_rules_go_and_every_other_rule_stays(self):
        # The task VM routes sit between and after other rules, so a delete by a stale number would hit the wrong rule.
        mixed = [OTHER_RULES[0], *TASK_VM_ROUTES[:3], OTHER_RULES[1], OTHER_RULES[2], *TASK_VM_ROUTES[3:6], OTHER_RULES[3], OTHER_RULES[4],
                 TASK_VM_ROUTES[6], OTHER_RULES[5]]
        self.rules(mixed)
        code, output, changed, calls = self.converge()
        self.assertEqual((code, changed), (0, 1), output)
        self.assertEqual((self.folder / 'ufw.rules').read_text().splitlines(), OTHER_RULES)
        # Highest number first, one delete per rule.
        self.assertEqual([call for call in calls if call.startswith('ufw --force delete')],
                         [f'ufw --force delete {n}' for n in (12, 9, 8, 7, 4, 3, 2)])

        code, output, changed, calls = self.converge()
        self.assertEqual((code, changed), (0, 0), output)
        self.assertEqual((self.folder / 'ufw.rules').read_text().splitlines(), OTHER_RULES)
        self.assertEqual([call for call in calls if call.startswith('ufw')], ['ufw status numbered'])

    def test_a_hub_without_task_vm_route_rules_changes_nothing(self):
        self.rules(OTHER_RULES)
        code, output, changed, calls = self.converge()
        self.assertEqual((code, changed), (0, 0), output)
        self.assertEqual((self.folder / 'ufw.rules').read_text().splitlines(), OTHER_RULES)
        self.assertEqual([call for call in calls if call.startswith('ufw')], ['ufw status numbered'])

    def test_the_hub_converge_removes_the_filter(self):
        tasks = {task['name']: task for top in yaml.safe_load((ANSIBLE / 'tasks/node-converge-hub.yml').read_text())
                 for task in [top, *top.get('block', [])]}
        self.assertEqual(tasks['Remove the task VM hub filter']['ansible.builtin.import_tasks'], 'node-converge-hub-filter-absent.yml')
        # The vpn Node's own converge reaches the hub tasks through its network phase in hub mode.
        network = {task['name']: task for task in yaml.safe_load((ANSIBLE / 'tasks/node-converge-network.yml').read_text())}
        self.assertEqual(network['Reconcile a target hub exactly once']['ansible.builtin.include_tasks'], 'node-converge-hub.yml')


if __name__ == '__main__':
    unittest.main()
