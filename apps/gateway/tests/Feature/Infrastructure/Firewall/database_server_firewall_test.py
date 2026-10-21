"""The managed filter program runs against fake commands and a private filesystem."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

PROGRAM = Path(__file__).resolve().parents[4] / 'app/Infrastructure/Firewall/database_server_firewall.py'
spec = importlib.util.spec_from_file_location('database_firewall', PROGRAM)
f = importlib.util.module_from_spec(spec)
spec.loader.exec_module(f)


class FirewallTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.patchers = []
        for name, suffix in [('DIRECTORY', 'orbit'), ('RULES', 'orbit/rules'), ('RUNNER', 'orbit/runner'),
                             ('HOOK', 'before.init'), ('DROPIN', 'docker.service.d/filter.conf')]:
            p = patch.object(f, name, self.root / suffix)
            p.start()
            self.addCleanup(p.stop)
        f.DIRECTORY.mkdir()
        self.raw = []
        self.local = []
        self.jumps = {'raw': ['-P PREROUTING ACCEPT'], 'filter': ['-P INPUT ACCEPT']}
        self.enabled = True
        self.fail_verify = False
        self.reject_candidate = False
        self.calls = []
        p = patch.object(f, 'run', self.run_command)
        p.start()
        self.addCleanup(p.stop)
        self.rules = ['-d 10.0.0.6/32 -p tcp --dport 5434 -i docker0 -j RETURN',
                      '-d 10.0.0.6/32 -p tcp --dport 5434 -s 10.0.0.4/32 -j RETURN',
                      '-d 10.0.0.6/32 -p tcp --dport 5434 -j DROP']

    def run_command(self, argv, data=None):
        self.calls.append((argv, data))
        if argv[0] == 'ufw':
            return 'Status: active' if self.enabled else 'Status: inactive'
        if argv[0] == 'systemctl':
            return ''
        if argv[0] == str(f.RUNNER):
            content = f.RULES.read_text()
            self.raw = [s for s in content.splitlines() if s.startswith('-A ' + f.CHAIN + ' ')]
            self.local = [s for s in content.splitlines() if s.startswith('-A ' + f.LOCAL_CHAIN + ' ')]
            for table, parent, chain in [('raw', 'PREROUTING', f.CHAIN), ('filter', 'INPUT', f.LOCAL_CHAIN)]:
                self.jumps[table] = ['-P ' + parent + ' ACCEPT', '-A ' + parent + ' -j ' + chain]
            if self.fail_verify:
                self.raw = []
                self.fail_verify = False
            return ''
        if argv[0] == 'iptables':
            table, chain = argv[4], argv[-1]
            if chain in ('PREROUTING', 'INPUT'):
                return '\n'.join(self.jumps[table])
            return '\n'.join(['-N ' + chain] + (self.raw if table == 'raw' else self.local))
        if argv[0] == 'iptables-restore':
            if '--test' in argv:
                if self.reject_candidate:
                    raise subprocess.CalledProcessError(1, argv)
            else:
                self.raw = [s for s in data.splitlines() if s.startswith('-A ' + f.CHAIN + ' ')]
                self.local = [s for s in data.splitlines() if s.startswith('-A ' + f.LOCAL_CHAIN + ' ')]
            return ''
        raise AssertionError(argv)

    def apply(self, rules=None, expand=False):
        with contextlib.redirect_stdout(io.StringIO()):
            f.reconcile({'rules': self.rules if rules is None else rules, 'expand': expand})

    def test_preserves_hook_and_installs_verified_filter_and_docker_gate(self):
        f.HOOK.write_text('#!/bin/sh\nset -e\n# unrelated\n')
        self.apply()
        self.apply()
        self.assertEqual(f.HOOK.read_text().count(f.BEGIN), 1)
        self.assertIn('set -e\n# unrelated\n', f.HOOK.read_text())
        self.assertIn('|| exit $?', f.HOOK.read_text())
        expected = ['-A ' + f.CHAIN + ' ' + r for r in self.rules]
        self.assertTrue(f.inspect(expected, f.render(expected)))
        self.assertIn('ExecStartPre=/etc/orbit/database-firewall docker', f.DROPIN.read_text())
        self.assertEqual(self.local, f.local_rules(expected))
        self.jumps['raw'].append('-A PREROUTING -j ' + f.CHAIN)
        self.assertFalse(f.inspect(expected, f.render(expected)))

    def test_expand_compares_normalized_rules_and_retains_old_sources(self):
        self.apply()
        self.raw = [line.replace('/32', '').replace('-p tcp', '-p tcp -m tcp') for line in self.raw]
        self.apply(expand=True)
        self.assertEqual(len(self.raw), 3)
        changed = [r.replace('10.0.0.4', '10.0.0.9') for r in self.rules]
        self.apply(changed, expand=True)
        self.assertEqual(len(self.raw), 4)
        self.assertTrue(any('10.0.0.4' in r for r in self.raw))
        self.assertTrue(any('10.0.0.9' in r for r in self.raw))
        self.apply(changed)
        self.assertEqual(len(self.raw), 3)
        self.assertFalse(any('10.0.0.4' in r for r in self.raw))

    def test_failed_verification_restores_exact_files_and_live_rules(self):
        self.apply()
        paths = (f.RULES, f.RUNNER, f.HOOK, f.DROPIN)
        prior = [(p.read_bytes(), p.stat().st_mode) for p in paths]
        raw, local = self.raw[:], self.local[:]
        self.fail_verify = True
        with self.assertRaisesRegex(RuntimeError, 'verification failed'):
            self.apply([r.replace('5434', '5435') for r in self.rules])
        self.assertEqual(prior, [(p.read_bytes(), p.stat().st_mode) for p in paths])
        self.assertEqual(raw, self.raw)
        self.assertEqual(local, self.local)

    def test_inactive_ufw_and_invalid_candidates_do_not_publish(self):
        self.enabled = False
        with self.assertRaisesRegex(RuntimeError, 'inactive firewall'):
            self.apply()
        self.assertFalse(f.RULES.exists())
        self.enabled = True
        self.reject_candidate = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.apply()
        self.assertFalse(f.HOOK.exists())
        self.assertFalse(f.DROPIN.exists())

    def test_inspection_has_no_filesystem_effects(self):
        f.DIRECTORY.rmdir()
        self.enabled = False
        with patch.object(sys, 'stdin', io.StringIO(json.dumps({'action': 'inspect', 'rules': self.rules}))), contextlib.redirect_stdout(io.StringIO()) as output:
            f.main()
        self.assertEqual(output.getvalue(), 'drift\n')
        self.assertFalse(f.DIRECTORY.exists())
        self.assertEqual(list(self.root.iterdir()), [])

    def test_runner_keeps_exactly_one_first_jump_and_blocks_docker_without_ufw(self):
        self.assertIn('Wants=ufw.service', f.DROPIN_CONTENT)
        self.assertIn('After=ufw.service', f.DROPIN_CONTENT)
        self.assertNotIn('Requires=', f.DROPIN_CONTENT)
        # Execute the real shell runner with fake iptables, never the host firewall.
        commands = self.root / 'bin'
        commands.mkdir()
        state = self.root / 'state.json'
        state.write_text(json.dumps({'raw': ['-P PREROUTING ACCEPT', '-A PREROUTING -j DOCKER'],
                                     'filter': ['-P INPUT ACCEPT', '-A INPUT -j ufw-before-input']}))
        fake = commands / 'iptables'
        fake.write_text('''#!/usr/bin/python3
import json,os,sys
from pathlib import Path
p=Path(os.environ['FILTER_TEST_STATE']); s=json.loads(p.read_text()); a=sys.argv[1:]; table=a[3]; parent=a[5]
if a[4]=='-S': print('\\n'.join(s[table]))
elif a[4]=='-I': s[table].insert(1, '-A '+parent+' -j '+a[-1])
elif a[4]=='-D': del s[table][int(a[-1])]
p.write_text(json.dumps(s))
''')
        fake.chmod(0o700)
        for name, body in [('iptables-restore', '#!/bin/sh\ncat >/dev/null\n'),
                           ('ufw', '#!/bin/sh\necho "Status: $FILTER_TEST_UFW"\n')]:
            path = commands / name
            path.write_text(body)
            path.chmod(0o700)
        f.RULES.write_text('fixture')
        f.RUNNER.write_text(f.RUNNER_CONTENT.replace('/etc/orbit/database-firewall.rules', str(f.RULES)))
        f.RUNNER.chmod(0o700)
        env = {**os.environ, 'PATH': str(commands) + ':' + os.environ['PATH'], 'FILTER_TEST_STATE': str(state), 'FILTER_TEST_UFW': 'active'}
        for action in ('start', 'start', 'docker'):
            subprocess.run([str(f.RUNNER), action], env=env, check=True)
        saved = json.loads(state.read_text())
        self.assertEqual(saved['raw'], ['-P PREROUTING ACCEPT', '-A PREROUTING -j ORBIT-DATABASE', '-A PREROUTING -j DOCKER'])
        self.assertEqual(saved['filter'], ['-P INPUT ACCEPT', '-A INPUT -j ORBIT-DATABASE-LOCAL', '-A INPUT -j ufw-before-input'])
        env['FILTER_TEST_UFW'] = 'inactive'
        result = subprocess.run([str(f.RUNNER), 'docker'], env=env)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(json.loads(state.read_text()), saved)


if __name__ == '__main__':
    unittest.main()
