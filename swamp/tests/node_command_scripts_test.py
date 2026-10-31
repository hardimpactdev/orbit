"""Shared SSH transport tests: real bounded pipes and remote supervisor, no fleet."""
import importlib.util
import io
import json
import os
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPTS = ROOT / 'extensions/behaviors/operations/node-command/scripts'
spec = importlib.util.spec_from_file_location('node_command', SCRIPTS / 'node_command.py')
command = importlib.util.module_from_spec(spec)
spec.loader.exec_module(command)


class NodeCommandTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='node-command-fixture-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.contract = json.loads(command.CONTRACT.read_text())
        self.target = self.contract['targets']['1']

    def test_strict_existing_key_host_and_no_private_argv(self):
        argv = command.launch(self.target, 60000)
        self.assertEqual(argv[:8], ['/usr/bin/sudo', '-n', '--', '/usr/bin/ssh', '-F', '/dev/null', '-T', '-o'])
        joined = ' '.join(argv)
        for option in ('IdentityAgent=none', 'ForwardAgent=no', 'ClearAllForwardings=yes',
                       'StrictHostKeyChecking=yes', 'HostKeyAlgorithms=ssh-ed25519',
                       'GlobalKnownHostsFile=/dev/null', '--kill-after=5s'):
            self.assertIn(option, joined)
        self.assertIn('orbit@10.44.0.1', argv)
        self.assertIn('/etc/orbit/node-proxy/id_ed25519', argv)
        self.assertNotIn('script', argv[-1])
        self.assertNotIn('private-value', joined)

    def placement_fixture(self, node=1):
        directory = self.root / 'proxy'
        directory.mkdir()
        (directory / f'{node}.json').write_text(json.dumps({key: self.contract['targets'][str(node)][key]
                                                         for key in ('node_id', 'host', 'user')}))
        (directory / 'id_ed25519').write_text('not-read-by-the-transport')
        (directory / 'id_ed25519').chmod(0o600)
        known = '\n'.join(value['host'] + ' ' + value['host_key']
                          for value in self.contract['targets'].values()) + '\n'
        (directory / 'known_hosts').write_text(known)
        return directory

    def test_placement_refuses_before_any_key_or_ssh_read(self):
        for cgroup in (b'0::/system.slice/orbit-node-worker.service\n',
                       b'0::/system.slice/orbit-node-proxy-worker-18.service\n',
                       b'0::/system.slice/orbit-node-proxy-worker-19.service\n',
                       b'prefix0::/system.slice/orbit-node-proxy-worker-1.service\n'):
            with self.subTest(cgroup=cgroup), patch.object(command, 'bounded_file', return_value=cgroup), \
                    patch.object(command, 'custody') as custody:
                with self.assertRaisesRegex(command.Refused, 'placement_required'):
                    command.placement(1)
                custody.assert_not_called()
        with patch.object(command, 'bounded_file', side_effect=PermissionError):
            with self.assertRaises(PermissionError):
                command.placement(1)

    def test_protected_selection_and_known_hosts_are_the_canonical_source(self):
        directory = self.placement_fixture()
        original = command.bounded_file
        def read(path, limit):
            if str(path) == '/proc/self/cgroup':
                return b'0::/system.slice/orbit-node-proxy-worker-1.service/dispatch\n'
            if str(path) == '/etc/orbit/agent/config.toml':
                return b'[swamp_worker]\nnode_id = 209\nuser = "orbit"\n'
            return original(path, limit)
        with patch.object(command, 'ROOT', directory), patch.object(command, 'bounded_file', side_effect=read), \
                patch.object(command, 'custody'), patch.object(command.subprocess, 'run',
                    return_value=SimpleNamespace(returncode=0, stdout=b'orbit-node-209\n')):
            self.assertEqual(command.placement(1), self.target)
            (directory / '1.json').write_text(json.dumps({'node_id': 1, 'user': 'orbit', 'host': '10.44.0.15'}))
            with self.assertRaisesRegex(command.Refused, 'selection_refused'):
                command.placement(1)
            (directory / '1.json').write_text(json.dumps({key: self.target[key] for key in ('node_id', 'host', 'user')}))
            (directory / 'known_hosts').write_text('wrong key')
            with self.assertRaisesRegex(command.Refused, 'pinned_key_refused'):
                command.placement(1)

    def test_root_only_identity_check_returns_no_config_or_private_material(self):
        parent = self.root / 'agent'
        parent.mkdir(mode=0o700)
        config = parent / 'config.toml'
        config.write_text('secret = "not-a-public-result"\n[swamp_worker]\nnode_id = 209\nuser = "orbit"\n')
        config.chmod(0o600)
        source = command.IDENTITY_CHECK.replace("'/etc/orbit/agent/config.toml'", repr(str(config)))
        source = source.replace("'/etc/orbit/agent'", repr(str(parent)))
        source = source.replace('info.st_uid != 0', 'info.st_uid != ' + str(os.getuid()))
        source = source.replace('info.st_gid != 0', 'info.st_gid != ' + str(os.getgid()))
        result = subprocess.run([sys.executable, '-I', '-c', source], capture_output=True, check=False)
        self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'orbit-node-209\n', b''))
        config.write_text('[swamp_worker]\nnode_id = 14\nuser = "orbit"\n')
        self.assertNotEqual(subprocess.run([sys.executable, '-I', '-c', source], capture_output=True).returncode, 0)

    @unittest.skipIf(os.getuid() == 0, 'requires an unprivileged caller')
    def test_fixed_sudo_identity_read_traverses_root_0700_without_changing_custody(self):
        available = subprocess.run(['/usr/bin/sudo', '-n', '/usr/bin/true'], capture_output=True)
        if available.returncode:
            self.skipTest('local sudo fixture unavailable')
        parent = self.root / 'root-agent'
        parent.mkdir(mode=0o700)
        config = parent / 'config.toml'
        config.write_text('[swamp_worker]\nnode_id = 209\nuser = "orbit"\n')
        config.chmod(0o600)
        source = command.IDENTITY_CHECK.replace("'/etc/orbit/agent/config.toml'", repr(str(config)))
        source = source.replace("'/etc/orbit/agent'", repr(str(parent)))
        subprocess.run(['/usr/bin/sudo', '-n', 'chown', '-R', '0:0', str(parent)], check=True)
        try:
            with self.assertRaises(PermissionError):
                config.read_bytes()
            result = subprocess.run(['/usr/bin/sudo', '-n', '--', '/usr/bin/python3', '-I', '-c', source],
                                    stdin=subprocess.DEVNULL, capture_output=True, env=command.SAFE_ENV,
                                    timeout=8, check=False)
            self.assertEqual((result.returncode, result.stdout, result.stderr), (0, b'orbit-node-209\n', b''))
            self.assertEqual(parent.stat().st_uid, 0)
            self.assertEqual(parent.stat().st_mode & 0o777, 0o700)
        finally:
            subprocess.run(['/usr/bin/sudo', '-n', 'chown', '-R', str(os.getuid()) + ':' + str(os.getgid()), str(parent)], check=True)

    def test_key_custody_rejects_symlink_owner_and_mode(self):
        for mode, uid in ((0o100644, 0), (0o100600, 1000), (0o120600, 0)):
            with patch.object(command.os, 'lstat', return_value=SimpleNamespace(st_uid=uid, st_gid=0, st_mode=mode)):
                with self.assertRaisesRegex(command.Refused, 'custody_refused'):
                    command.custody('/fixture', 0o600)

    def test_only_named_env_is_framed_and_secrets_never_become_argv(self):
        args = SimpleNamespace(env=['ORBIT_PUBLIC_VALUE'], secret_env=['ORBIT_DB_PASSWORD'], node_id=1, timeout_ms=60000)
        stdin = SimpleNamespace(buffer=io.BytesIO(b'printf ok\n'))
        with patch.object(command.sys, 'stdin', stdin), patch.dict(os.environ, {'ORBIT_PUBLIC_VALUE': 'public',
                'ORBIT_DB_PASSWORD': 'private-value', 'SWAMP_SERVER_TOKEN': 'never-forward-this'}):
            wire, values = command.request(args, self.target)
        program_size, remaining = wire.split(b'\n', 1)
        remaining = remaining[int(program_size):]
        length, body = remaining.split(b'\n', 1)
        self.assertEqual(int(length), len(body))
        request = json.loads(body)
        self.assertEqual(request['env'], {'ORBIT_PUBLIC_VALUE': 'public', 'ORBIT_DB_PASSWORD': 'private-value'})
        self.assertEqual(values, [b'private-value'])
        self.assertNotIn(b'never-forward-this', wire)
        self.assertNotIn('private-value', ' '.join(command.launch(self.target, 60000)))

    def test_shipped_python_asset_is_framed_as_contents_not_remote_cache_path(self):
        bundles = self.root / 'bundles'
        folder = bundles / ('a' * 64) / 'files/scripts'
        folder.mkdir(parents=True)
        runner = folder / 'node_command.py'
        runner.write_text('fixture runner path only')
        asset = folder / 'hub_peer.py'
        asset.write_text('print("public-asset-result")\n')
        args = SimpleNamespace(env=[], secret_env=[], node_id=1, timeout_ms=5000,
                               action_file=str(asset), interpreter='python3', as_root=True)
        with patch.object(command, '__file__', str(runner)):
            frame, _values = command.request(args, self.target)
            self.assertNotIn(str(asset).encode(), frame)
            program_size, remaining = frame.split(b'\n', 1)
            _length, body = remaining[int(program_size):].split(b'\n', 1)
            request = json.loads(body)
            self.assertEqual(request['script'], asset.read_text())
            self.assertEqual(request['interpreter'], 'python3')
            self.assertTrue(request['as_root'])
            with self.assertRaises((ValueError, command.Refused)):
                command.action_asset('/etc/passwd')
            link = folder / 'link.py'
            link.symlink_to(asset)
            with self.assertRaisesRegex(command.Refused, 'asset_refused'):
                command.action_asset(str(link))

    def test_python_asset_interpreter_receives_forwarded_env(self):
        frame = self.remote_frame('import os; print(os.environ["ORBIT_VALUE"])\n', env={'ORBIT_VALUE': 'python-result'})
        size, remaining = frame.split(b'\n', 1)
        program, remaining = remaining[:int(size)], remaining[int(size):]
        _length, body = remaining.split(b'\n', 1)
        body = json.loads(body)
        body['interpreter'] = 'python3'
        body = json.dumps(body).encode()
        frame = size + b'\n' + program + str(len(body)).encode() + b'\n' + body
        code, out, error = command.exchange([sys.executable, '-I', '-c', command.LOADER], frame, 65536, 5000)
        self.assertEqual((code, out, error), (0, b'python-result\n', b''))

    def test_duplex_writer_drains_output_before_large_input_finishes(self):
        body = b'x' * 400000
        frame = str(len(body)).encode() + b'\n' + body
        program = ('import sys;sys.stdout.buffer.write(b"y"*150000);sys.stdout.flush();'
                   'n=int(sys.stdin.buffer.readline());b=sys.stdin.buffer.read(n);'
                   'assert len(b)==n;sys.stderr.write("drained")')
        code, out, error = command.exchange([sys.executable, '-c', program], frame, 200000, 2000)
        self.assertEqual((code, len(out), error), (0, 150000, b'drained'))

    def test_output_overflow_refuses_and_stops_the_process(self):
        with self.assertRaisesRegex(command.Refused, 'output_oversized'):
            command.exchange([sys.executable, '-c', 'import sys;sys.stdout.write("x"*400000)'],
                             b'not-read', 1024, 2000)

    def remote_program(self, *, host='10.44.0.1', mask='masked'):
        ip = self.root / 'ip'
        ip.write_text('#!/bin/sh\nprintf \'%s\' \'[{"addr_info":[{"local":"' + host + '"}]}]\'\n')
        ip.chmod(0o755)
        systemctl = self.root / 'systemctl'
        systemctl.write_text('#!/bin/sh\ncase "$1" in is-enabled) printf "' + mask + '\\n" ;; *) printf "0\\n" ;; esac\n')
        systemctl.chmod(0o755)
        source = command.REMOTE.read_text()
        source = source.replace('if os.geteuid() != 0:', 'if os.geteuid() != ' + str(os.getuid()) + ':')
        source = source.replace("'/usr/sbin/ip'", repr(str(ip))).replace("'/usr/bin/systemctl'", repr(str(systemctl)))
        source = source.replace("pwd.getpwnam(request['user'])", 'pwd.getpwuid(os.getuid())')
        source = source.replace("extra_groups=[] if request['as_root'] else os.getgrouplist(account.pw_name, account.pw_gid)", "extra_groups=None")
        return source.encode()

    def remote_frame(self, script, *, env=None, timeout=5000, host='10.44.0.1'):
        program = self.remote_program(host=host)
        body = json.dumps({'schema_version': 1, 'node_id': 1, 'host': '10.44.0.1', 'user': 'orbit',
                           'script': script, 'env': env or {}, 'timeout_ms': timeout, 'interpreter': 'sh', 'as_root': False}).encode()
        return str(len(program)).encode() + b'\n' + program + str(len(body)).encode() + b'\n' + body

    def test_remote_supervisor_runs_only_the_remote_script_and_preserves_exit(self):
        frame = self.remote_frame('printf "%s" "$ORBIT_PRIVATE_VALUE"; exit 7\n', env={'ORBIT_PRIVATE_VALUE': 'synthetic-value'})
        code, out, error = command.exchange([sys.executable, '-I', '-c', command.LOADER], frame, 65536, 5000)
        self.assertEqual((code, out, error), (7, b'synthetic-value', b''))

    def test_remote_wrong_node_or_unmasked_worker_refuses_before_effect(self):
        proof = self.root / 'must-not-exist'
        script = 'printf unsafe > ' + str(proof)
        frame = self.remote_frame(script, host='10.44.0.15')
        code, out, error = command.exchange([sys.executable, '-I', '-c', command.LOADER], frame, 65536, 5000)
        self.assertNotEqual(code, 0)
        self.assertFalse(proof.exists())
        self.assertEqual(out, b'')
        self.assertIn(b'remote_refused', error)
        program = self.remote_program(mask='enabled')
        body = json.dumps({'schema_version': 1, 'node_id': 1, 'host': '10.44.0.1', 'user': 'orbit',
                           'script': script, 'env': {}, 'timeout_ms': 5000, 'interpreter': 'sh', 'as_root': False}).encode()
        frame = str(len(program)).encode() + b'\n' + program + str(len(body)).encode() + b'\n' + body
        code, _out, _error = command.exchange([sys.executable, '-I', '-c', command.LOADER], frame, 65536, 5000)
        self.assertNotEqual(code, 0)
        self.assertFalse(proof.exists())

    def test_channel_eof_cancels_the_remote_action_group(self):
        marker = self.root / 'child-pid'
        frame = self.remote_frame('echo $$ > ' + str(marker) + '\nsleep 30\n', timeout=30000)
        process = subprocess.Popen([sys.executable, '-I', '-c', command.LOADER], stdin=subprocess.PIPE,
                                   stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        self.addCleanup(lambda: command.stop(process))
        process.stdin.write(frame)
        process.stdin.flush()
        deadline = time.monotonic() + 5
        while not marker.exists() and time.monotonic() < deadline:
            time.sleep(.01)
        self.assertTrue(marker.exists())
        pid = int(marker.read_text())
        process.stdin.close()
        process.stdin = None
        _out, error = process.communicate(timeout=5)
        self.assertNotEqual(process.returncode, 0, error)
        with self.assertRaises(ProcessLookupError):
            os.kill(pid, 0)

    def test_exited_shell_and_term_ignoring_descendant_are_cleaned(self):
        marker = self.root / 'background-pid'
        script = ('/bin/sh -c \'trap "" TERM; echo $$ > "' + str(marker) + '"; exec sleep 30\' '
                  '>/dev/null 2>&1 &\n'
                  'while [ ! -s "' + str(marker) + '" ]; do :; done\nexit 0\n')
        frame = self.remote_frame(script)
        code, _out, error = command.exchange([sys.executable, '-I', '-c', command.LOADER], frame, 65536, 5000)
        self.assertEqual(code, 0, error)
        pid = int(marker.read_text())
        # A reparented killed process may briefly remain a zombie under container PID1.
        proc = Path('/proc') / str(pid) / 'stat'
        self.assertTrue(not proc.exists() or proc.read_text().split()[2] == 'Z')

    def test_secret_output_is_redacted_on_both_streams(self):
        stdin = SimpleNamespace(buffer=io.BytesIO(b'printf private\n'))
        stdout = SimpleNamespace(buffer=io.BytesIO())
        stderr = SimpleNamespace(buffer=io.BytesIO())
        with patch.object(command.sys, 'argv', ['node_command.py', '--node-id', '1', '--secret-env', 'ORBIT_PRIVATE']), \
                patch.object(command.sys, 'stdin', stdin), patch.object(command.sys, 'stdout', stdout), \
                patch.object(command.sys, 'stderr', stderr), patch.dict(os.environ, {'ORBIT_PRIVATE': 'private-value'}), \
                patch.object(command, 'placement', return_value=self.target), \
                patch.object(command, 'exchange', return_value=(0, b'private-value public', b'private-value')):
            self.assertEqual(command.main(), 0)
        self.assertEqual(stdout.buffer.getvalue(), b'[redacted] public')
        self.assertEqual(stderr.buffer.getvalue(), b'[redacted]')

    def test_remote_deadline_stops_the_action(self):
        frame = self.remote_frame('sleep 30\n', timeout=100)
        started = time.monotonic()
        code, _out, error = command.exchange([sys.executable, '-I', '-c', command.LOADER], frame, 65536, 2000)
        self.assertNotEqual(code, 0)
        self.assertLess(time.monotonic() - started, 5)
        self.assertIn(b'remote_refused', error)


if __name__ == '__main__':
    unittest.main()
