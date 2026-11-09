"""The egress-log part of the task VM host playbook only records, takes the bridge from the intent and renders the same files every run."""
import copy
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
PLAYBOOK = ANSIBLE / 'task-vm-host.yml'
HOST_PLAYBOOK = Path(__file__).resolve().parents[1] / 'ansible/task-vm-host.yml'
PRIVATE = '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10, 224.0.0.0/4, 240.0.0.0/4'
OURS = 'log-queries=extra\nlog-facility=/var/log/orbit/dnsmasq-orbittask0.log'
# Only these two checks fail the part, and with it the image refresh. Every other check warns.
SAFETY = {'egress.bridge_dual_stack', 'egress.acl_changed'}
WARNINGS = {'egress.rules_missing', 'egress.dnsmasq_unset', 'egress.log_unreadable', 'egress.service_inactive', 'egress.setup_failed'}


# The files of the part for a host without containers, as the part rendered them before container hosts. A host that keeps them
# reloads nothing in its next refresh.
VM_ONLY_NFT = 'table inet orbit_egress\ndelete table inet orbit_egress\ntable inet orbit_egress {\n    set seen {\n        type ipv4_addr . ipv4_addr . inet_proto . inet_service\n        flags dynamic,timeout\n        timeout 10m\n        size 262144\n    }\n    set flood {\n        type ipv4_addr\n        flags dynamic,timeout\n        timeout 1m\n        size 4096\n    }\n    chain forward {\n        type filter hook forward priority filter + 10; policy accept;\n        iifname "orbittask0" oifname != "orbittask0" ct state new meta l4proto { tcp, udp } ip daddr != { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10, 224.0.0.0/4, 240.0.0.0/4 } ip saddr . ip daddr . meta l4proto . th dport @seen return\n        iifname "orbittask0" oifname != "orbittask0" ct state new meta l4proto { tcp, udp } ip daddr != { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10, 224.0.0.0/4, 240.0.0.0/4 } add @flood { ip saddr limit rate over 50/second burst 200 packets } counter return\n        iifname "orbittask0" oifname != "orbittask0" ct state new meta l4proto { tcp, udp } ip daddr != { 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 169.254.0.0/16, 100.64.0.0/10, 224.0.0.0/4, 240.0.0.0/4 } log prefix "orbit-egress " flags ether add @seen { ip saddr . ip daddr . meta l4proto . th dport }\n    }\n}\n'
VM_ONLY_ROTATION = '/var/log/orbit/task-egress.log {\n    daily\n    rotate 7\n    maxsize 200M\n    compress\n    missingok\n    notifempty\n    create 0644 syslog adm\n    sharedscripts\n    postrotate\n        /usr/lib/rsyslog/rsyslog-rotate\n    endscript\n}\n\n/var/log/orbit/dnsmasq-orbittask0.log {\n    daily\n    rotate 7\n    maxsize 200M\n    compress\n    missingok\n    notifempty\n    copytruncate\n}\n'


def command():
    return shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')


def have_ansible():
    return bool(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists())


def egress_play():
    return next(play for play in yaml.safe_load(PLAYBOOK.read_text()) if play['name'] == 'Task VM egress log')


def flat(tasks):
    """The tasks of the play in order, the tasks of a block in place of the block."""
    for task in tasks:
        if 'block' in task:
            yield from flat(task['block'])
        else:
            yield task


def tasks_by_name(play):
    return {task['name']: task for task in flat(play['tasks'])}


def module_of(task):
    return next(key for key in task if key.startswith('ansible.builtin.'))


def run_local(folder, tasks, variables):
    """Run `tasks` through Ansible on this machine with the vars of the part and `variables`; return the finished process."""
    vars_file = folder / 'vars.yml'
    vars_file.write_text(yaml.safe_dump(egress_play()['vars']))
    (folder / 'local.yml').write_text(yaml.safe_dump([{'hosts': 'localhost', 'gather_facts': False, 'vars_files': [str(vars_file)],
                                                       'vars': variables, 'tasks': tasks}]))
    return subprocess.run([command(), '-i', 'localhost,', '-c', 'local', str(folder / 'local.yml')], check=False, capture_output=True,
                          text=True, timeout=120, env={**os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg')})


def bridge_config(raw=None, bridge='orbittask0', **changes):
    """The `config` of the bridge as `incus query` returns it: as `prepare-host` makes it, with `raw.dnsmasq` when `raw` is given."""
    config = {'ipv6.address': 'none', 'security.acls': f'{bridge}-egress', **({'raw.dnsmasq': raw} if raw is not None else {}), **changes}
    return json.dumps({'config': config})


def reads(*configs, bridges=('orbittask0',)):
    """A register of a loop over the bridges, as `incus query` answers for each: `configs` in the order of `bridges`."""
    return {'results': [{'item': bridge, 'stdout': config} for bridge, config in zip(bridges, configs)]}


def render(folder, raw=None, bridge='orbittask0', containers=None):
    """Render the files of the part through Ansible itself, from the vars of the play, a host intent and one bridge as it was before."""
    texts = ('nft_rules', 'rsyslog_rules', 'logrotate_rules', 'egress_unit', 'dnsmasq_raw')
    data = ('dnsmasq_missing', 'dnsmasq_taken')
    tasks = [{'name': name, 'ansible.builtin.copy': {'dest': str(folder / name), 'content': '{{ ' + name + ' }}', 'mode': '0644'}} for name in texts]
    tasks += [{'name': name, 'ansible.builtin.copy': {'dest': str(folder / name), 'content': '{{ ' + name + ' | to_json }}', 'mode': '0644'}}
              for name in data]
    host = {'network': bridge, **({'containers': {'network': containers}} if containers else {})}
    return run_local(folder, tasks, {'host': host, 'bridge_read': {'item': containers or bridge, 'stdout': bridge_config(raw)}})


def local_file_tasks(root, validator=('test', '-s')):
    """The file tasks of the part with every path moved below `root`, as an unprivileged user can run them twice.

    The block structure stays, so the `when` of a block and its rescue run as on a host. Owners go (the test is not root). The tool that
    checks the rsyslog rule becomes `validator`: an argv prefix, `test -s`, or a command that fails for a rule that does not pass. The tools
    that validate on the temporary file become a size check, and notifications go. Tasks that need systemd or Incus are left out.
    """
    def moved(path):
        return str(root) + path if path.startswith(('/', '{{ dnsmasq_log')) else path

    def transform(tasks):
        out = []
        for source in tasks:
            if 'block' in source:
                task = {key: value for key, value in source.items() if key not in ('block', 'rescue', 'always')}
                for part in ('block', 'rescue', 'always'):
                    if part in source:
                        task[part] = transform(source[part])
                if task['block']:
                    out.append(task)
                continue
            module = module_of(source)
            task = copy.deepcopy(source)
            if module == 'ansible.builtin.command':
                argv = task[module]['argv']
                if '/usr/sbin/rsyslogd' not in argv:
                    continue
                task[module] = {'argv': [*validator, moved(argv[-1])]}
            elif module in ('ansible.builtin.file', 'ansible.builtin.copy', 'ansible.builtin.stat'):
                args = task[module]
                for key in ('dest', 'path', 'src'):
                    if key in args and 'item.' not in args[key]:
                        args[key] = moved(args[key])
                for gone in ('owner', 'group'):
                    args.pop(gone, None)
                if 'validate' in args:
                    args['validate'] = 'test -s %s'
                if isinstance(task.get('loop'), str):
                    task['loop'] = task['loop'].replace("'/var/log/orbit/", f"'{root}/var/log/orbit/").replace(
                        "'path': log", f"'path': '{root}' ~ log")
            elif module != 'ansible.builtin.set_fact':
                continue
            task.pop('notify', None)
            out.append(task)
        return out

    folders = [{'name': 'Make the folders', 'ansible.builtin.file': {'path': str(root / 'etc' / name), 'state': 'directory', 'mode': '0755'}}
               for name in ('rsyslog.d', 'systemd/system', 'logrotate.d')]
    setup = []
    for task in egress_play()['tasks']:
        if task['name'] == 'Apply the changed rules':
            break
        setup.append(task)
    return folders + transform(setup)


TABLE = ('table inet orbit_egress {\n\tset seen {\n\t\ttype ipv4_addr . ipv4_addr . inet_proto . inet_service\n\t}\n\tchain forward {\n'
         '\t\ttype filter hook forward priority filter + 10; policy accept;\n'
         '\t\tiifname "orbittask0" oifname != "orbittask0" ct state new ip saddr . ip daddr . meta l4proto . th dport @seen return\n'
         '\t\tiifname "orbittask0" oifname != "orbittask0" ct state new add @flood { ip saddr limit rate over 50/second burst 200 packets } counter return\n'
         '\t\tiifname "orbittask0" oifname != "orbittask0" ct state new log prefix "orbit-egress " flags ether add @seen { ip saddr . ip daddr }\n'
         '\t}\n}\n')


def files(**changes):
    """The three `stat` results of the end check: the folder and the two logs, world-readable unless `changes` says otherwise."""
    folder = {'exists': True, 'roth': True, 'xoth': True, 'mode': '0755'}
    log = {'exists': True, 'roth': True, 'xoth': False, 'mode': '0644'}
    results = [{'stat': {**folder, **changes.get('folder', {})}}, {'stat': {**log, **changes.get('egress', {})}},
               {'stat': {**log, 'mode': '0664', **changes.get('dnsmasq', {})}}]
    return {'results': results}


def end_state(**changes):
    """What the reads of the part leave in the registers, for a bridge `orbittask0` that is as the part expects; `changes` damages it."""
    acl = {'egress': [{'action': 'drop', 'destination': PRIVATE.replace(' ', ''), 'state': 'enabled'}, {'action': 'allow', 'state': 'enabled'}]}
    state = {'host': {'network': 'orbittask0'}, 'bridge_before': reads(bridge_config(OURS)),
             'egress_bridge': reads(bridge_config(OURS)), 'egress_acl': reads(json.dumps(acl)), 'egress_end_table': {'stdout': TABLE},
             'egress_units': {'results': [{'status': {'ActiveState': 'active'}}] * 2}, 'egress_files': files(),
             'egress_config_files': {'results': [{'stat': {'exists': True}}] * 2}}
    state.update(changes)
    return state


class TaskVmHostEgressTest(unittest.TestCase):
    def test_the_part_is_one_tag_that_keeps_its_handlers_and_takes_the_bridge_only_from_the_intent(self):
        play = egress_play()
        self.assertEqual(play['tags'], ['egress-log'])
        self.assertIs(play['force_handlers'], True)
        self.assertEqual(play['vars']['bridges'],
                         "{{ [host.network] + ([host.containers.network] if (host.containers | default(none)) is not none else []) }}")
        self.assertEqual(play['vars']['bridge'], '{{ bridge_read.item }}')
        self.assertNotRegex(yaml.safe_dump(play), r'orbittask[a-z0-9]')
        # A handler that fails must not fail the part: the end checks report the state.
        self.assertTrue(all(handler['failed_when'] is False for handler in play['handlers']))

    def test_the_ranges_the_log_skips_are_the_ranges_the_bridge_acl_drops(self):
        prepare = next(play for play in yaml.safe_load(HOST_PLAYBOOK.read_text()) if play.get('tags') == ['host-prepare'])
        ranges = prepare['vars']['prepare_private_ranges']
        self.assertEqual(egress_play()['vars']['private_ranges'], ranges)
        self.assertEqual(PRIVATE.split(', '), ranges)

    def test_it_changes_the_bridge_in_one_setting_and_nothing_else_of_incus(self):
        play = egress_play()
        incus = [task[module_of(task)]['argv'] for task in flat(play['tasks']) if module_of(task) == 'ansible.builtin.command'
                 and isinstance(task[module_of(task)]['argv'], list) and task[module_of(task)]['argv'][0] == 'incus']
        writes = [argv for argv in incus if argv[1] != 'query']
        self.assertEqual(len(writes), 1)
        self.assertEqual(writes[0][:3], ['incus', 'network', 'set'])
        self.assertEqual(writes[0][4], 'raw.dnsmasq={{ dnsmasq_raw }}')
        # Only when a line is missing and the operator's own log file is not in the way, so a rerun runs no bridge setup, and after the log
        # file exists, so dnsmasq keeps its mode.
        tasks = tasks_by_name(play)
        self.assertEqual(tasks['Log the DNS queries of the bridge']['when'], '(dnsmasq_missing | length > 0) and not (dnsmasq_taken | bool)')
        order = [task['name'] for task in flat(play['tasks'])]
        self.assertLess(order.index('Touch the log files'), order.index('Log the DNS queries of the bridge'))
        self.assertEqual(tasks['Touch the log files']['ansible.builtin.file']['modification_time'], 'preserve')
        self.assertNotIn('pgrep', yaml.safe_dump(play))

    def test_only_the_rules_fail_the_part_and_each_setup_item_is_a_block_of_its_own_that_warns(self):
        play = egress_play()
        top = {task['name']: task for task in play['tasks']}
        names = [task['name'] for task in play['tasks']]
        # The rules are checked by `nft -c` outside any rescue: a table that does not load fails the part.
        self.assertEqual(top['Write the nftables rules']['ansible.builtin.copy']['validate'], '/usr/sbin/nft -c -f %s')
        self.assertEqual(top['Write the nftables rules']['notify'], 'Reload the egress log rules')
        self.assertNotIn('rescue', top['Write the nftables rules'])
        # Each item is a block with its own rescue, so a failure in one cannot skip another. The connection log comes first.
        items = ['Load the connection log', 'Make the log files', 'Set up the rsyslog rule', 'Set up the log rotation', 'Set up the DNS log']
        self.assertEqual([name for name in names if name in items], items)
        self.assertEqual(names.index('Write the nftables rules') + 1, names.index('Load the connection log'))
        for name in items:
            rescue = top[name]['rescue']
            self.assertEqual(len(rescue), 1, name)
            self.assertIn('egress.setup_failed', json.dumps(rescue[0]), name)
            self.assertIn('egress_warnings', rescue[0]['ansible.builtin.set_fact'], name)
        self.assertEqual([task['name'] for task in top['Load the connection log']['block']], ['Write the unit that loads the rules', 'Load the rules at boot'])
        self.assertEqual(top['Set up the log rotation']['block'][0]['ansible.builtin.copy']['validate'], '/usr/sbin/logrotate -d %s')
        # The DNS log waits for the log files: it does not run after they failed.
        self.assertIn('egress_logs_failed', top['Set up the DNS log']['when'])
        # The handlers run right after the writes and before the end checks.
        self.assertEqual(names.index('Apply the changed rules'), names.index('Set up the DNS log') + 1)
        self.assertEqual(top['Apply the changed rules']['ansible.builtin.meta'], 'flush_handlers')
        self.assertLess(names.index('Apply the changed rules'), names.index('Warn about the rules'))
        # Two asserts, both safety checks, after the warnings are printed; the observation checks only record warnings.
        asserts = {task['name']: task['ansible.builtin.assert']['fail_msg'] for task in play['tasks'] if 'ansible.builtin.assert' in task}
        self.assertEqual(set(asserts.values()), SAFETY)
        for code in asserts.values():
            self.assertRegex(code, r'^[a-z]+\.[a-z_]+$')
        self.assertLess(names.index('Print the warnings'), names.index('Check that the bridge has no IPv6'))
        # An operation does not carry the log of a step: the part writes no file for it and the workflow has no step for it.
        self.assertNotIn('egress-warnings', PLAYBOOK.read_text())
        self.assertNotIn('delegate_to', PLAYBOOK.read_text())
        warnings = [task for task in play['tasks'] if task['name'].startswith('Warn about')]
        self.assertEqual(len(warnings), 5)
        for task in warnings + [task for task in play['tasks'] if 'ansible.builtin.assert' in task]:
            self.assertIn('not ansible_check_mode', json.dumps(task['when']))

    def test_the_rsyslog_rule_is_checked_where_apparmor_lets_rsyslogd_read_it(self):
        # AppArmor confines rsyslogd to /etc/rsyslog.conf and /etc/rsyslog.d/**: `validate:` on the temporary file of `copy` fails with
        # "Permission denied". The staged file lives under /etc/rsyslog.d and its name does not end in `.conf`, so it is not included.
        block = next(task for task in egress_play()['tasks'] if task['name'] == 'Set up the rsyslog rule')
        steps = {task['name']: task for task in flat(block['block'])}
        stage = steps['Stage the rsyslog rule']['ansible.builtin.copy']['dest']
        check = steps['Check the staged rsyslog rule']['ansible.builtin.command']['argv']
        final = steps['Put the rsyslog rule in place']['ansible.builtin.copy']
        self.assertTrue(stage.startswith('/etc/rsyslog.d/'), stage)
        self.assertFalse(stage.endswith('.conf'), stage)
        self.assertEqual(check, ['/usr/sbin/rsyslogd', '-N1', '-f', stage])
        self.assertEqual((final['src'], final['dest'], final['remote_src']), (stage, '/etc/rsyslog.d/19-orbit-egress.conf', True))
        self.assertEqual(steps['Put the rsyslog rule in place']['notify'], 'Restart rsyslog')
        for task in flat(block['block']):
            self.assertNotIn('validate', task.get('ansible.builtin.copy', {}), task['name'])
        # Only when the content differs, and the staged file goes whatever the check says.
        replace = next(task for task in block['block'] if task['name'] == 'Check and replace the rsyslog rule')
        self.assertIn("hash('sha1')", replace['when'])
        self.assertNotIn('always', replace)
        # The cleanup is in the `always` of the whole block, outside the guard: a staged file that a crash left is removed on every run.
        self.assertEqual(block['always'][0]['ansible.builtin.file'], {'path': stage, 'state': 'absent'})
        self.assertIs(block['always'][0]['changed_when'], False)
        # The warning carries the tool's own words: the command module's `msg` is only "non-zero return code".
        warning = json.dumps(block['rescue'][0]['vars'])
        self.assertIn('ansible_failed_result.stderr', warning)
        self.assertIn('ansible_failed_result.stdout', warning)

    def test_the_rate_limit_is_per_source_and_the_log_comes_before_the_add(self):
        rules = egress_play()['vars']['nft_rules']
        lines = [line.strip() for line in rules.splitlines() if 'outside_match' in line]
        self.assertEqual(len(lines), 3)
        self.assertTrue(lines[0].endswith('@seen return'))
        self.assertTrue(lines[1].endswith('add @flood { ip saddr limit rate over 50/second burst 200 packets } counter return'))
        self.assertLess(lines[2].index(' log prefix '), lines[2].index(' add @seen '))
        self.assertRegex(rules, r'set flood \{\s+type ipv4_addr\s+flags dynamic,timeout\s+timeout 1m\s+size 4096\s+\}')

    def test_the_slow_tasks_have_a_timeout(self):
        play = egress_play()
        slow = [task for task in flat(play['tasks'] + play['handlers']) if module_of(task) == 'ansible.builtin.systemd'
                or task['name'] == 'Log the DNS queries of the bridge']
        self.assertGreaterEqual(len(slow), 6)
        for task in slow:
            self.assertIsInstance(task.get('timeout'), int, task['name'])

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
            self.assertIn(f'{match} add @flood {{ ip saddr limit rate over 50/second burst 200 packets }} counter return', rules)
            self.assertIn('set flood {', rules)
            # The flow is logged first and added to the set after: an add to a full set fails and skips what follows it in the rule.
            self.assertIn(f'{match} log prefix "orbit-egress " flags ether add @seen {{ ip saddr . ip daddr . meta l4proto . th dport }}', rules)
            # Log only: every rule returns or logs, and nothing drops, rejects, marks, queues or changes a packet. Only new flows are logged.
            lines = [line.strip() for line in rules.splitlines() if 'iifname' in line]
            self.assertEqual(len(lines), 3)
            self.assertTrue(all('ct state new' in line for line in lines))
            self.assertNotRegex(rules, r'\b(drop|reject|queue|dnat|snat|masquerade|mark)\b')
            self.assertEqual((folder / 'dnsmasq_raw').read_text(), 'log-queries=extra\nlog-facility=/var/log/orbit/dnsmasq-orbittask7.log')
            rsyslog = (folder / 'rsyslog_rules').read_text()
            self.assertIn('if $syslogfacility-text == "kern" and $msg contains "orbit-egress " then {', rsyslog)
            self.assertIn('%msg:::sp-if-no-1st-sp%%msg:::drop-last-lf%', rsyslog)
            self.assertIn('action(type="omfile" file="/var/log/orbit/task-egress.log" template="OrbitEgress" fileCreateMode="0644")', rsyslog)
            self.assertEqual(rsyslog.splitlines()[-2:], ['    stop', '}'])
            rotation = (folder / 'logrotate_rules').read_text()
            self.assertIn('/var/log/orbit/dnsmasq-orbittask7.log {', rotation)
            self.assertNotIn('/var/log/incus', rotation)
            self.assertIn('create 0644 syslog adm', rotation)
            self.assertIn('/usr/lib/rsyslog/rsyslog-rotate', rotation)
            self.assertEqual(rotation.count('rotate 7'), 2)
            self.assertEqual(rotation.count('maxsize 200M'), 2)
            self.assertEqual(rotation.count('copytruncate'), 1)
            self.assertIn('RemainAfterExit=yes', (folder / 'egress_unit').read_text())

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_raw_dnsmasq_is_merged_never_overwritten_and_a_foreign_log_file_is_left_alone(self):
        ours = OURS.split('\n')
        theirs = 'log-facility=/tmp/mine.log'
        cases = [
            # (what the bridge holds, the lines to add, the value to set, whether the operator logs elsewhere)
            (None, ours, OURS, False),
            ('', ours, OURS, False),
            ('address=/lan.test/10.1.2.3', ours, 'address=/lan.test/10.1.2.3\n' + OURS, False),
            ('address=/lan.test/10.1.2.3\nlog-queries=extra', ours[1:], 'address=/lan.test/10.1.2.3\nlog-queries=extra\n' + ours[1], False),
            (OURS, [], OURS, False),
            (ours[1] + '\naddress=/x/1.1.1.1\n' + ours[0], [], None, False),
            (theirs, ours, None, True),
            (theirs + '\n' + ours[0], ours[1:], None, True),
        ]
        for raw, missing, value, taken in cases:
            with self.subTest(raw=raw), tempfile.TemporaryDirectory() as folder:
                folder = Path(folder)
                result = render(folder, raw=raw)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertEqual(json.loads((folder / 'dnsmasq_missing').read_text()), missing)
                self.assertEqual(json.loads((folder / 'dnsmasq_taken').read_text()), taken)
                if value is not None:
                    self.assertEqual((folder / 'dnsmasq_raw').read_text(), value)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_the_checks_pass_on_the_expected_end_state_safety_damage_fails_and_the_rest_warns(self):
        checks = [task for task in egress_play()['tasks']
                  if task['name'].startswith(('Warn about', 'Check that', 'Print the warnings'))]
        acl = json.loads(end_state()['egress_acl']['results'][0]['stdout'])

        def with_acl(rules):
            return reads(json.dumps({'egress': rules}))

        def without(text):
            return end_state(egress_end_table={'stdout': TABLE.replace(text, 'x')})

        safety = [
            ('egress.bridge_dual_stack', end_state(egress_bridge=reads(bridge_config(OURS, **{'ipv6.address': 'fd42:1::1/64'})))),
            ('egress.acl_changed', end_state(egress_bridge=reads(bridge_config(OURS, **{'security.acls': 'other'})))),
            ('egress.acl_changed', end_state(egress_acl=with_acl([{**acl['egress'][0], 'destination': '10.0.0.0/8'}, acl['egress'][1]]))),
            ('egress.acl_changed', end_state(egress_acl=with_acl([acl['egress'][1], acl['egress'][0]]))),
        ]
        observation = [
            ('egress.rules_missing', end_state(egress_end_table={'stdout': ''})),
            ('egress.rules_missing', without('set seen')),
            ('egress.rules_missing', without('hook forward')),
            ('egress.rules_missing', without('policy accept')),
            ('egress.rules_missing', without('ct state new')),
            ('egress.rules_missing', without(' @seen return')),
            ('egress.rules_missing', without('log prefix "orbit-egress "')),
            ('egress.rules_missing', end_state(egress_end_table={'stdout': TABLE.replace('add @seen', 'drop')})),
            ('egress.rules_missing', end_state(egress_end_table={'stdout': TABLE.replace('counter return', 'counter reject')})),
            ('egress.dnsmasq_unset', end_state(egress_bridge=reads(bridge_config()))),
            ('egress.dnsmasq_unset', end_state(egress_bridge=reads(bridge_config('log-queries=extra')))),
            ('egress.dnsmasq_unset', end_state(bridge_before=reads(bridge_config('log-facility=/tmp/mine.log')),
                                               egress_bridge=reads(bridge_config('log-facility=/tmp/mine.log')))),
            ('egress.log_unreadable', end_state(egress_files=files(folder={'xoth': False}))),
            ('egress.log_unreadable', end_state(egress_files=files(dnsmasq={'roth': False}))),
            ('egress.log_unreadable', end_state(egress_files=files(dnsmasq={'mode': '0660'}))),
            ('egress.rules_missing', without('add @flood')),
            ('egress.rules_missing', without('counter')),
            ('egress.log_unreadable', end_state(egress_files=files(egress={'exists': False, 'roth': False}))),
            ('egress.config_missing', end_state(egress_config_files={'results': [{'stat': {'exists': False}}, {'stat': {'exists': True}}]})),
            ('egress.config_missing', end_state(egress_config_files={'results': [{'stat': {'exists': True}}, {'stat': {'exists': False}}]})),
            ('egress.service_inactive', end_state(egress_units={'results': [{'status': {'ActiveState': 'active'}}, {'status': {'ActiveState': 'inactive'}}]})),
            ('egress.service_inactive', end_state(egress_units={'results': [{'msg': 'not found'}, {'status': {'ActiveState': 'active'}}]})),
        ]
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)

            def run(state):
                return run_local(folder, checks, state)

            passed = run(end_state())
            self.assertEqual(passed.returncode, 0, passed.stdout + passed.stderr)
            self.assertNotIn('WARNING', passed.stdout)
            for code, state in safety:
                with self.subTest(code=code, state=state):
                    failed = run(state)
                    self.assertNotEqual(failed.returncode, 0)
                    self.assertIn(code, failed.stdout + failed.stderr)
            for code, state in observation:
                with self.subTest(code=code, state=state):
                    warned = run(state)
                    self.assertEqual(warned.returncode, 0, warned.stdout + warned.stderr)
                    self.assertIn(f'WARNING {code}', warned.stdout)
                    self.assertEqual(code in SAFETY, False)
            # A safety failure still prints the observation warnings first.
            both = run(end_state(egress_bridge=reads(bridge_config(OURS, **{'ipv6.address': 'fd42:1::1/64'})), egress_end_table={'stdout': ''}))
            self.assertNotEqual(both.returncode, 0)
            self.assertIn('WARNING egress.rules_missing', both.stdout)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_failed_setup_action_is_recorded_and_the_part_goes_on(self):
        block = next(task for task in egress_play()['tasks'] if task['name'] == 'Set up the log rotation')
        printing = [task for task in egress_play()['tasks'] if task['name'] == 'Print the warnings']
        broken = {'name': 'Write into a folder that is gone', 'ansible.builtin.copy': {'dest': '/nonexistent-egress-test/x/y', 'content': 'x'}}
        tasks = [{'block': [broken], 'rescue': block['rescue']}, {'name': 'Next', 'ansible.builtin.debug': {'msg': 'went on'}}, *printing]
        with tempfile.TemporaryDirectory() as folder:
            result = run_local(Path(folder), tasks, {})
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('WARNING egress.setup_failed: Write into a folder that is gone', result.stdout)
            self.assertIn('went on', result.stdout)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_failed_bridge_setting_names_the_error_text_of_the_tool_in_its_warning(self):
        # `incus network set` is a command: its `msg` is only "non-zero return code", the reason is on stderr and stdout.
        block = next(task for task in egress_play()['tasks'] if task['name'] == 'Set up the DNS log')
        warning = json.dumps(block['rescue'][0]['vars'])
        self.assertIn('ansible_failed_result.stderr', warning)
        self.assertIn('ansible_failed_result.stdout', warning)
        printing = [task for task in egress_play()['tasks'] if task['name'] == 'Print the warnings']
        failing = {'name': 'Log the DNS queries of the bridge',
                   'ansible.builtin.command': {'argv': ['sh', '-c', 'echo "Error: network is busy" >&2; echo "partial output"; exit 1']}}
        with tempfile.TemporaryDirectory() as folder:
            result = run_local(Path(folder), [{'block': [failing], 'rescue': block['rescue']}, *printing], {})
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('WARNING egress.setup_failed: Log the DNS queries of the bridge', result.stdout)
            self.assertIn('Error: network is busy', result.stdout)
            self.assertIn('partial output', result.stdout)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_rsyslog_rule_that_fails_its_check_keeps_the_old_rule_and_skips_nothing_else(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            root = folder / 'root'
            rule = root / 'etc/rsyslog.d/19-orbit-egress.conf'
            variables = {'host': {'network': 'orbittask0'}, 'bridge_before': {'stdout': bridge_config(OURS)}}
            printing = [task for task in egress_play()['tasks'] if task['name'] == 'Print the warnings']
            (root / 'etc/rsyslog.d').mkdir(parents=True)
            rule.write_text('# the old rule\n')
            refusing = ('sh', '-c', 'echo "could not open config file: Permission denied" >&2; exit 1', 'rsyslogd')
            failing = run_local(folder, local_file_tasks(root, validator=refusing) + printing, variables)
            self.assertEqual(failing.returncode, 0, failing.stdout + failing.stderr)
            self.assertEqual(rule.read_text(), '# the old rule\n')
            self.assertFalse((root / 'etc/rsyslog.d/19-orbit-egress.conf.new').exists())
            # The rotation and the log files were still set up. The warning names the task and carries the tool's own words.
            self.assertTrue((root / 'etc/logrotate.d/orbit-egress').is_file())
            self.assertTrue((root / 'var/log/orbit/dnsmasq-orbittask0.log').is_file())
            self.assertIn('WARNING egress.setup_failed: Check the staged rsyslog rule', failing.stdout)
            self.assertIn('could not open config file: Permission denied', failing.stdout)
            # A rule that passes replaces the old one, and the staged file is gone.
            passing = run_local(folder, local_file_tasks(root), variables)
            self.assertEqual(passing.returncode, 0, passing.stdout + passing.stderr)
            self.assertEqual(rule.read_text(), egress_play()['vars']['rsyslog_rules'])
            self.assertFalse((root / 'etc/rsyslog.d/19-orbit-egress.conf.new').exists())

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_staged_file_that_a_crash_left_is_removed_even_when_the_rule_is_unchanged(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            root = folder / 'root'
            variables = {'host': {'network': 'orbittask0'}, 'bridge_before': {'stdout': bridge_config(OURS)}}
            tasks = local_file_tasks(root)
            self.assertEqual(run_local(folder, tasks, variables).returncode, 0)
            leftover = root / 'etc/rsyslog.d/19-orbit-egress.conf.new'
            leftover.write_text('# left by a crash\n')
            rerun = run_local(folder, tasks, variables)
            self.assertEqual(rerun.returncode, 0, rerun.stdout + rerun.stderr)
            self.assertFalse(leftover.exists())
            self.assertEqual((root / 'etc/rsyslog.d/19-orbit-egress.conf').read_text(), egress_play()['vars']['rsyslog_rules'])
            # Removing the leftover is not a change of the part, so the run still reports none.
            self.assertRegex(rerun.stdout, r'changed=0\s')

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_second_run_of_the_file_tasks_changes_nothing_and_leaves_the_logs_world_readable(self):
        # The tasks that write files run for real below a temporary root, as an unprivileged user. The tasks that need Incus, systemd or root
        # (owners, the bridge setting, the services) are in the end checks above and in the live runs on a host.
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            root = folder / 'root'
            variables = {'host': {'network': 'orbittask0'}, 'bridge_before': {'stdout': bridge_config(OURS)}}
            tasks = local_file_tasks(root)
            first = run_local(folder, tasks, variables)
            self.assertEqual(first.returncode, 0, first.stdout + first.stderr)
            self.assertNotRegex(first.stdout, r'changed=0\s')
            expected = {'etc/orbit/egress-log.nft': 'nft_rules', 'etc/rsyslog.d/19-orbit-egress.conf': 'rsyslog_rules',
                        'etc/logrotate.d/orbit-egress': 'logrotate_rules', 'etc/systemd/system/orbit-egress-log.service': 'egress_unit'}
            # The rsyslog rule and the unit hold no template, so they are written as they stand; the others hold the bridge.
            for path, name in expected.items():
                written = (root / path).read_text()
                if name in ('rsyslog_rules', 'egress_unit'):
                    self.assertEqual(written, egress_play()['vars'][name], path)
                self.assertNotIn('{{', written, path)
            self.assertFalse((root / 'etc/rsyslog.d/19-orbit-egress.conf.new').exists())
            # The DNS log is 0664 so that dnsmasq, which fchmods a root-group file without the group write bit to 0660, leaves its mode.
            for path, mode in (('var/log/orbit', 0o755), ('var/log/orbit/task-egress.log', 0o644), ('var/log/orbit/dnsmasq-orbittask0.log', 0o664)):
                self.assertEqual((root / path).stat().st_mode & 0o777, mode, path)
            self.assertIn('table inet orbit_egress', (root / 'etc/orbit/egress-log.nft').read_text())
            dns = root / 'var/log/orbit/dnsmasq-orbittask0.log'
            dns.write_text('a query\n')
            # dnsmasq starts and gives the file to its own user: the owner changes and the mode stays. Another group of this user stands in
            # for `incus`; a rerun must neither flip the owner back nor touch the mode.
            others = [gid for gid in os.getgroups() if gid != dns.stat().st_gid]
            if others:
                os.chown(dns, -1, others[0])
                self.assertEqual(dns.stat().st_gid, others[0])
            before = {path: (root / path).stat().st_mtime_ns for path in list(expected) + ['var/log/orbit/dnsmasq-orbittask0.log']}
            second = run_local(folder, tasks, variables)
            self.assertEqual(second.returncode, 0, second.stdout + second.stderr)
            self.assertRegex(second.stdout, r'changed=0\s')
            self.assertEqual(dns.read_text(), 'a query\n')
            self.assertEqual(dns.stat().st_mode & 0o777, 0o664)
            if others:
                self.assertEqual(dns.stat().st_gid, others[0])
            self.assertEqual({path: (root / path).stat().st_mtime_ns for path in before}, before)

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
        for containers in (None, 'orbittaskc0'):
            with self.subTest(containers=containers), tempfile.TemporaryDirectory() as folder:
                folder = Path(folder)
                self.assertEqual(render(folder, containers=containers).returncode, 0)
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

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_host_without_containers_renders_the_files_it_had_before(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            result = render(folder)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertEqual((folder / 'nft_rules').read_text(), VM_ONLY_NFT)
            self.assertEqual((folder / 'logrotate_rules').read_text(), VM_ONLY_ROTATION)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_container_host_logs_both_bridges_with_one_table_and_one_rotation(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            result = render(folder, containers='orbittaskc0')
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            rules = (folder / 'nft_rules').read_text()
            both = '{ "orbittask0", "orbittaskc0" }'
            lines = [line.strip() for line in rules.splitlines() if 'iifname' in line]
            self.assertEqual(len(lines), 3)
            for line in lines:
                self.assertTrue(line.startswith(f'iifname {both} oifname != {both} ct state new '), line)
            self.assertEqual(rules.count('table inet orbit_egress {'), 1)
            rotation = (folder / 'logrotate_rules').read_text()
            for bridge in ('orbittask0', 'orbittaskc0'):
                self.assertIn(f'/var/log/orbit/dnsmasq-{bridge}.log {{', rotation)
            self.assertEqual(rotation.count('copytruncate'), 2)
            self.assertEqual(rotation.count('/var/log/orbit/task-egress.log {'), 1)
            # Each bridge's dnsmasq logs to its own file: the bridge of the loop item, here the container bridge.
            self.assertEqual((folder / 'dnsmasq_raw').read_text(), 'log-queries=extra\nlog-facility=/var/log/orbit/dnsmasq-orbittaskc0.log')

    def test_the_bridge_tasks_loop_over_both_bridges(self):
        tasks = tasks_by_name(egress_play())
        for name in ('Read the bridge', 'Read the bridge at the end', 'Read the ACL at the end'):
            self.assertEqual(tasks[name]['loop'], '{{ bridges }}', name)
        self.assertEqual(tasks['Log the DNS queries of the bridge']['loop'], '{{ bridge_before.results }}')
        self.assertEqual(tasks['Log the DNS queries of the bridge']['loop_control']['loop_var'], 'bridge_read')
        self.assertEqual(tasks['Warn about the DNS log setting']['loop'], '{{ egress_bridge.results }}')
        for name in ('Check that the bridge has no IPv6', 'Check that the bridge keeps its ACL'):
            self.assertEqual(tasks[name]['loop_control']['loop_var'], 'end_pair', name)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_the_end_checks_cover_the_container_bridge(self):
        checks = [task for task in egress_play()['tasks']
                  if task['name'].startswith(('Warn about', 'Check that', 'Print the warnings'))]
        bridges = ('orbittask0', 'orbittaskc0')
        container_log = 'log-queries=extra\nlog-facility=/var/log/orbit/dnsmasq-orbittaskc0.log'
        acl = end_state()['egress_acl']['results'][0]['stdout']

        def state(container=None, acls=None, **changes):
            config = container or bridge_config(container_log, bridge='orbittaskc0')
            three = files()
            three['results'].append({'stat': {'exists': True, 'roth': True, 'xoth': False, 'mode': '0664', **changes.pop('dnsmasq', {})}})
            return end_state(host={'network': 'orbittask0', 'containers': {'network': 'orbittaskc0'}},
                             egress_bridge=reads(bridge_config(OURS), config, bridges=bridges),
                             egress_acl=reads(acl, acls or acl, bridges=bridges), egress_files=three, **changes)

        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            passed = run_local(folder, checks, state())
            self.assertEqual(passed.returncode, 0, passed.stdout + passed.stderr)
            self.assertNotIn('WARNING', passed.stdout)
            dual = run_local(folder, checks, state(bridge_config(container_log, bridge='orbittaskc0', **{'ipv6.address': 'fd42:1::1/64'})))
            self.assertNotEqual(dual.returncode, 0)
            self.assertIn('egress.bridge_dual_stack', dual.stdout + dual.stderr)
            moved = run_local(folder, checks, state(acls=json.dumps({'egress': [{'action': 'allow', 'state': 'enabled'}]})))
            self.assertNotEqual(moved.returncode, 0)
            self.assertIn('egress.acl_changed', moved.stdout + moved.stderr)
            unset = run_local(folder, checks, state(bridge_config(bridge='orbittaskc0')))
            self.assertEqual(unset.returncode, 0, unset.stdout + unset.stderr)
            self.assertIn('WARNING egress.dnsmasq_unset: raw.dnsmasq of orbittaskc0 lacks', unset.stdout)
            self.assertNotIn('raw.dnsmasq of orbittask0 lacks', unset.stdout)
            unreadable = run_local(folder, checks, state(dnsmasq={'mode': '0660'}))
            self.assertEqual(unreadable.returncode, 0, unreadable.stdout + unreadable.stderr)
            self.assertIn('WARNING egress.log_unreadable', unreadable.stdout)

    @unittest.skipUnless(have_ansible(), 'ansible-playbook is not installed')
    def test_a_container_host_touches_a_world_readable_dns_log_per_bridge(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            root = folder / 'root'
            variables = {'host': {'network': 'orbittask0', 'containers': {'network': 'orbittaskc0'}}}
            result = run_local(folder, local_file_tasks(root), variables)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            for bridge in ('orbittask0', 'orbittaskc0'):
                self.assertEqual((root / f'var/log/orbit/dnsmasq-{bridge}.log').stat().st_mode & 0o777, 0o664, bridge)
            self.assertIn('dnsmasq-orbittaskc0.log {', (root / 'etc/logrotate.d/orbit-egress').read_text())


if __name__ == '__main__':
    unittest.main()
