"""The egress-log part of the task VM host playbook only records, takes the bridge from the intent and renders the same files every run."""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PLAYBOOK = ANSIBLE / 'task-vm-host.yml'
AGENT_SETUP = Path(__file__).resolve().parents[2] / 'apps/agent/src/commands/incus/setup.rs'
PRIVATE = '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10, 224.0.0.0/4, 240.0.0.0/4'
OURS = 'log-queries=extra\nlog-facility=/var/log/incus/dnsmasq.orbittask0.log'


def command():
    return shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')


def have_ansible():
    return bool(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists())


def egress_play():
    return next(play for play in yaml.safe_load(PLAYBOOK.read_text()) if play['name'] == 'Task VM egress log')


def tasks_by_name(play):
    return {task['name']: task for task in play['tasks']}


def run_local(folder, tasks, variables):
    """Run `tasks` through Ansible on this machine with the vars of the part and `variables`; return the finished process."""
    vars_file = folder / 'vars.yml'
    vars_file.write_text(yaml.safe_dump(egress_play()['vars']))
    (folder / 'local.yml').write_text(yaml.safe_dump([{'hosts': 'localhost', 'gather_facts': False, 'vars_files': [str(vars_file)],
                                                       'vars': variables, 'tasks': tasks}]))
    return subprocess.run([command(), '-i', 'localhost,', '-c', 'local', str(folder / 'local.yml')], check=False, capture_output=True,
                          text=True, timeout=120, env={**os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg')})


def bridge_config(raw=None, **changes):
    """The `config` of the bridge as `incus query` returns it: as `prepare-host` makes it, with `raw.dnsmasq` when `raw` is given."""
    config = {'ipv6.address': 'none', 'security.acls': 'orbittask0-egress', **({'raw.dnsmasq': raw} if raw is not None else {}), **changes}
    return json.dumps({'config': config})


def render(folder, raw=None, bridge='orbittask0'):
    """Render the files of the part through Ansible itself, from the vars of the play, a host intent and the bridge as it was before."""
    names = ('nft_rules', 'rsyslog_rules', 'logrotate_rules', 'egress_unit', 'dnsmasq_raw', 'dnsmasq_missing')
    return run_local(folder, [{'name': name, 'ansible.builtin.copy': {'dest': str(folder / name), 'content': '{{ ' + name + ' | to_json }}'
                                                                      if name == 'dnsmasq_missing' else '{{ ' + name + ' }}', 'mode': '0644'}}
                              for name in names], {'host': {'network': bridge}, 'bridge_before': {'stdout': bridge_config(raw)}})


TABLE = ('table inet orbit_egress {\n\tset seen {\n\t\ttype ipv4_addr . ipv4_addr . inet_proto . inet_service\n\t}\n\tchain forward {\n'
         '\t\ttype filter hook forward priority filter + 10; policy accept;\n\t\tadd @seen { ip saddr . ip daddr } log prefix "orbit-egress "\n\t}\n}\n')


def files(**changes):
    """The three `stat` results of the end check: the folder and the two logs, world-readable unless `changes` says otherwise."""
    folder = {'exists': True, 'roth': True, 'xoth': True}
    log = {'exists': True, 'roth': True, 'xoth': False}
    results = [{'stat': {**folder, **changes.get('folder', {})}}, {'stat': {**log, **changes.get('egress', {})}},
               {'stat': {**log, **changes.get('dnsmasq', {})}}]
    return {'results': results}


def end_state(**changes):
    """What the reads of the part leave in the registers, for a bridge `orbittask0` that is as the part expects; `changes` damages it."""
    acl = {'egress': [{'action': 'drop', 'destination': PRIVATE.replace(' ', ''), 'state': 'enabled'}, {'action': 'allow', 'state': 'enabled'}]}
    state = {'host': {'network': 'orbittask0'}, 'bridge_before': {'stdout': bridge_config(OURS)},
             'egress_bridge': {'stdout': bridge_config(OURS)}, 'egress_acl': {'stdout': json.dumps(acl)}, 'egress_end_table': {'stdout': TABLE},
             'egress_units': {'results': [{'status': {'ActiveState': 'active'}}] * 2}, 'egress_files': files()}
    state.update(changes)
    return state


class TaskVmHostEgressTest(unittest.TestCase):
    def test_the_part_is_one_tag_and_takes_the_bridge_only_from_the_intent(self):
        play = egress_play()
        self.assertEqual(play['tags'], ['egress-log'])
        self.assertEqual(play['vars']['bridge'], '{{ host.network }}')
        self.assertNotRegex(yaml.safe_dump(play), r'orbittask[a-z0-9]')

    def test_the_ranges_the_log_skips_are_the_ranges_the_bridge_acl_drops(self):
        # One list in two places: the agent's `acl()` makes the ACL, this part reads it. Drift would log a private range or hide a public one.
        source = AGENT_SETUP.read_text()
        acl = re.search(r'"action":"drop","destination":"([^"]+)"', source)
        self.assertIsNotNone(acl, 'apps/agent/src/commands/incus/setup.rs no longer lists the dropped egress ranges')
        self.assertEqual(egress_play()['vars']['private_ranges'], acl.group(1).split(','))
        self.assertEqual(PRIVATE.split(', '), acl.group(1).split(','))

    def test_it_changes_the_bridge_in_one_setting_and_nothing_else_of_incus(self):
        play = egress_play()
        incus = [task['ansible.builtin.command']['argv'] for task in play['tasks'] if 'ansible.builtin.command' in task
                 and isinstance(task['ansible.builtin.command']['argv'], list) and task['ansible.builtin.command']['argv'][0] == 'incus']
        writes = [argv for argv in incus if argv[1] != 'query']
        self.assertEqual(len(writes), 1)
        self.assertEqual(writes[0][:3], ['incus', 'network', 'set'])
        self.assertEqual(writes[0][4], 'raw.dnsmasq={{ dnsmasq_raw }}')
        # Only when a line is missing, so a rerun restarts no dnsmasq, and after the log files exist, so dnsmasq keeps their mode.
        tasks = tasks_by_name(play)
        self.assertEqual(tasks['Log the DNS queries of the bridge']['when'], 'dnsmasq_missing | length > 0')
        order = [task['name'] for task in play['tasks']]
        self.assertLess(order.index('Make the log files'), order.index('Log the DNS queries of the bridge'))
        self.assertLess(order.index('Check that the DNS log is free'), order.index('Log the DNS queries of the bridge'))
        self.assertEqual(tasks['Make the log files']['ansible.builtin.file']['modification_time'], 'preserve')

    def test_each_file_is_checked_by_the_tool_that_reads_it_and_the_changes_notify_a_handler(self):
        play = egress_play()
        tasks = tasks_by_name(play)
        handlers = {handler['name'] for handler in play['handlers']}
        for name, validator, notify in (('Write the rsyslog rule', '/usr/sbin/rsyslogd -N1 -f %s', 'Restart rsyslog'),
                                        ('Write the nftables rules', '/usr/sbin/nft -c -f %s', 'Reload the egress log rules'),
                                        ('Write the log rotation', '/usr/sbin/logrotate -d %s', None)):
            copy = tasks[name]['ansible.builtin.copy']
            self.assertEqual(copy['validate'], validator, name)
            self.assertEqual(tasks[name].get('notify'), notify, name)
            if notify:
                self.assertIn(notify, handlers)
        self.assertEqual(tasks['Apply the changed rules']['ansible.builtin.meta'], 'flush_handlers')
        order = [task['name'] for task in play['tasks']]
        self.assertLess(order.index('Apply the changed rules'), order.index('Check the rules'))

    def test_the_end_state_asserts_the_log_and_the_safety_of_the_bridge_with_public_codes(self):
        play = egress_play()
        codes = {task['name']: task['ansible.builtin.assert']['fail_msg'] for task in play['tasks'] if 'ansible.builtin.assert' in task}
        self.assertEqual(set(codes.values()), {'egress.dnsmasq_log_taken', 'egress.rules_missing', 'egress.dnsmasq_unset', 'egress.log_unreadable',
                                               'egress.service_inactive', 'egress.bridge_dual_stack', 'egress.acl_changed'})
        for code in codes.values():
            self.assertRegex(code, r'^[a-z]+\.[a-z_]+$')
        asserted = yaml.safe_dump([task for task in play['tasks'] if 'ansible.builtin.assert' in task])
        for needle in ('policy accept', 'ipv6.address', 'security.acls', 'private_ranges | difference(dropped)', 'dnsmasq_lines', 'roth', 'xoth'):
            self.assertIn(needle, asserted)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_the_files_render_for_the_bridge_of_the_intent_and_log_without_a_verdict(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            result = render(folder, bridge='orbittask7')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            rules = (folder / 'nft_rules').read_text()
            match = f'iifname "orbittask7" oifname != "orbittask7" ct state new meta l4proto {{ tcp, udp }} ip daddr != {{ {PRIVATE} }}'
            self.assertEqual(rules.splitlines()[:3], ['table inet orbit_egress', 'delete table inet orbit_egress', 'table inet orbit_egress {'])
            self.assertIn('type ipv4_addr . ipv4_addr . inet_proto . inet_service', rules)
            self.assertIn('flags dynamic,timeout', rules)
            self.assertIn('timeout 10m', rules)
            self.assertIn('size 262144', rules)
            self.assertIn('type filter hook forward priority filter + 10; policy accept;', rules)
            self.assertIn(f'{match} ip saddr . ip daddr . meta l4proto . th dport @seen return', rules)
            # The flow is logged first and added to the set after: an add to a full set fails and skips what follows it in the rule.
            self.assertIn(f'{match} log prefix "orbit-egress " flags ether add @seen {{ ip saddr . ip daddr . meta l4proto . th dport }}', rules)
            # Log only: no rule drops, rejects or accepts, and nothing marks, queues or changes a packet. Only new flows are logged.
            lines = [line.strip() for line in rules.splitlines() if 'iifname' in line]
            self.assertEqual(len(lines), 2)
            self.assertTrue(lines[0].endswith(' @seen return'))
            self.assertLess(lines[1].index(' log prefix '), lines[1].index(' add @seen '))
            self.assertTrue(all('ct state new' in line for line in lines))
            self.assertNotRegex(rules, r'\b(drop|reject|queue|dnat|snat|masquerade|mark)\b')
            self.assertEqual((folder / 'dnsmasq_raw').read_text(), 'log-queries=extra\nlog-facility=/var/log/incus/dnsmasq.orbittask7.log')
            rsyslog = (folder / 'rsyslog_rules').read_text()
            self.assertIn('if $msg contains "orbit-egress " then {', rsyslog)
            self.assertIn('action(type="omfile" file="/var/log/orbit/task-egress.log" template="OrbitEgress" fileCreateMode="0644")', rsyslog)
            self.assertEqual(rsyslog.splitlines()[-2:], ['    stop', '}'])
            rotation = (folder / 'logrotate_rules').read_text()
            self.assertIn('/var/log/incus/dnsmasq.orbittask7.log {', rotation)
            self.assertIn('create 0644 syslog adm', rotation)
            self.assertIn('/usr/lib/rsyslog/rsyslog-rotate', rotation)
            self.assertEqual(rotation.count('rotate 7'), 2)
            self.assertEqual(rotation.count('maxsize 200M'), 2)
            self.assertEqual(rotation.count('copytruncate'), 1)
            self.assertIn('RemainAfterExit=yes', (folder / 'egress_unit').read_text())

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_raw_dnsmasq_is_merged_never_overwritten(self):
        ours = OURS.split('\n')
        cases = [
            # (what the bridge holds, the lines to add, the value to set)
            (None, ours, OURS),
            ('', ours, OURS),
            ('address=/lan.test/10.1.2.3', ours, 'address=/lan.test/10.1.2.3\n' + OURS),
            ('address=/lan.test/10.1.2.3\nlog-queries=extra', ours[1:], 'address=/lan.test/10.1.2.3\nlog-queries=extra\n' + ours[1]),
            (OURS, [], OURS),
            ('log-facility=/var/log/incus/dnsmasq.orbittask0.log\n' + 'address=/x/1.1.1.1\n' + 'log-queries=extra', [], None),
        ]
        for raw, missing, value in cases:
            with self.subTest(raw=raw), tempfile.TemporaryDirectory() as folder:
                folder = Path(folder)
                result = render(folder, raw=raw)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(json.loads((folder / 'dnsmasq_missing').read_text()), missing)
                if value is not None:
                    self.assertEqual((folder / 'dnsmasq_raw').read_text(), value)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_foreign_dnsmasq_log_file_is_refused_before_anything_changes(self):
        play = egress_play()
        order = [task['name'] for task in play['tasks']]
        gate = tasks_by_name(play)['Check that the DNS log is free']
        # It runs before the first task that changes the host, the folders.
        self.assertLess(order.index('Check that the DNS log is free'), order.index('Make the folders'))
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            for raw, code in ((None, None), (OURS, None), ('log-facility=/tmp/mine.log', 'egress.dnsmasq_log_taken')):
                with self.subTest(raw=raw):
                    state = {'host': {'network': 'orbittask0'}, 'bridge_before': {'stdout': bridge_config(raw)}}
                    result = run_local(folder, [gate], state)
                    self.assertEqual(result.returncode == 0, code is None, result.stdout + result.stderr)
                    if code:
                        self.assertIn(code, result.stdout + result.stderr)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_the_checks_pass_on_the_expected_end_state_and_each_damage_fails_with_its_code(self):
        checks = [task for task in egress_play()['tasks'] if 'ansible.builtin.assert' in task and task['name'] != 'Check that the DNS log is free']
        acl = json.loads(end_state()['egress_acl']['stdout'])

        def with_acl(rules):
            return {'stdout': json.dumps({'egress': rules})}

        cases = [
            ('egress.rules_missing', end_state(egress_end_table={'stdout': TABLE.replace('set seen', 'set other')})),
            ('egress.rules_missing', end_state(egress_end_table={'stdout': TABLE.replace('policy accept', 'policy drop')})),
            ('egress.rules_missing', end_state(egress_end_table={'stdout': ''})),
            ('egress.dnsmasq_unset', end_state(egress_bridge={'stdout': bridge_config()})),
            ('egress.dnsmasq_unset', end_state(egress_bridge={'stdout': bridge_config('log-queries=extra')})),
            ('egress.log_unreadable', end_state(egress_files=files(folder={'xoth': False}))),
            ('egress.log_unreadable', end_state(egress_files=files(dnsmasq={'roth': False}))),
            ('egress.log_unreadable', end_state(egress_files=files(egress={'exists': False, 'roth': False}))),
            ('egress.service_inactive', end_state(egress_units={'results': [{'status': {'ActiveState': 'active'}}, {'status': {'ActiveState': 'inactive'}}]})),
            ('egress.bridge_dual_stack', end_state(egress_bridge={'stdout': bridge_config(OURS, **{'ipv6.address': 'fd42:1::1/64'})})),
            ('egress.acl_changed', end_state(egress_bridge={'stdout': bridge_config(OURS, **{'security.acls': 'other'})})),
            ('egress.acl_changed', end_state(egress_acl=with_acl([{**acl['egress'][0], 'destination': '10.0.0.0/8'}, acl['egress'][1]]))),
            ('egress.acl_changed', end_state(egress_acl=with_acl([acl['egress'][1], acl['egress'][0]]))),
        ]
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            passed = run_local(folder, checks, end_state())
            self.assertEqual(passed.returncode, 0, passed.stdout + passed.stderr)
            for code, state in cases:
                with self.subTest(code=code, state=state):
                    failed = run_local(folder, checks, state)
                    self.assertNotEqual(failed.returncode, 0)
                    self.assertIn(code, failed.stdout + failed.stderr)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_second_render_changes_nothing(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            self.assertEqual(render(folder).returncode, 0)
            second = render(folder)
            self.assertEqual(second.returncode, 0, second.stdout + second.stderr)
            self.assertRegex(second.stdout, r'changed=0\s')

    @unittest.skipUnless(shutil.which('nft') and hasattr(os, 'geteuid') and os.geteuid() == 0 and have_ansible(), 'needs root, nft and ansible')
    def test_the_rendered_rules_pass_nft_check_mode(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            self.assertEqual(render(folder).returncode, 0)
            result = subprocess.run(['nft', '-c', '-f', str(folder / 'nft_rules')], check=False, capture_output=True, text=True, timeout=30)
            self.assertEqual(result.returncode, 0, result.stderr)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_the_playbook_passes_the_ansible_syntax_check_for_the_part(self):
        intent = {'host': {'network': 'orbittask0', 'project': 'orbit-tasks', 'pool': 'orbit-tasks', 'ssh': {'target': {'host': '10.0.0.1', 'port': 22, 'user': 'orbit',
                                                                                                                  'host_keys': []}, 'identity_file': '/x'}}}
        result = subprocess.run([command(), '--syntax-check', '--tags', 'egress-log', '-i', 'localhost,', '-e', json.dumps(intent), '-e', 'run_dir=/tmp',
                                 str(PLAYBOOK)], check=False, capture_output=True, text=True, timeout=60,
                                env={**os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg')})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
