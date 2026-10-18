"""Exercise the shared worker budget with isolated Linux and macOS resource readings."""
import importlib.machinery
import importlib.util
import os
import socket
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

REPOSITORY = Path(sys.argv.pop(1))
loader = importlib.machinery.SourceFileLoader('check_processes', str(REPOSITORY / 'bin/check-processes'))
spec = importlib.util.spec_from_loader(loader.name, loader)
budget = importlib.util.module_from_spec(spec)
loader.exec_module(budget)
GIB = 1024 ** 3
WORKER = 1280 * 1024 ** 2
RESERVE = 640 * 1024 ** 2


class CheckProcessesTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit-check-processes-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.proc = self.root / 'proc'
        self.cgroup = self.root / 'cgroup'
        (self.proc / 'self').mkdir(parents=True)
        (self.cgroup / 'user.slice/task.scope').mkdir(parents=True)
        (self.proc / 'self/cgroup').write_text('0::/user.slice/task.scope\n')
        (self.proc / 'meminfo').write_text(f'MemTotal: {64 * GIB // 1024} kB\nMemAvailable: {32 * GIB // 1024} kB\n')
        self.enter(patch.object(budget, 'PROC', self.proc, create=True))
        self.enter(patch.object(budget, 'CGROUP', self.cgroup, create=True))
        self.enter(patch.object(budget.sys, 'platform', 'linux'))
        self.enter(patch.object(budget.os, 'sched_getaffinity', return_value=set(range(16)), create=True))

    def enter(self, context):
        context.__enter__()
        self.addCleanup(context.__exit__, None, None, None)

    def limit(self, group='user.slice/task.scope', memory='max', current=0, cpu='max 100000'):
        directory = self.cgroup / group
        (directory / 'memory.max').write_text(str(memory))
        (directory / 'memory.current').write_text(str(current))
        (directory / 'cpu.max').write_text(cpu)

    def test_uses_all_available_cores_without_four_worker_cap(self):
        self.limit()
        self.assertEqual(budget.processes({}), 16)

    def test_subtracts_current_cgroup_memory(self):
        self.limit(memory=8 * WORKER + RESERVE, current=5 * WORKER)
        self.assertEqual(budget.processes({}), 3)

    def test_uses_bounded_parent_of_unlimited_leaf(self):
        self.limit()
        self.limit('user.slice', memory=8 * WORKER + RESERVE, current=6 * WORKER, cpu='350000 100000')
        self.assertEqual(budget.processes({}), 2)

    def test_uses_tightest_remaining_memory_in_hierarchy(self):
        self.limit(memory=8 * WORKER, current=WORKER)
        self.limit('user.slice', memory=16 * WORKER + RESERVE, current=13 * WORKER)
        self.assertEqual(budget.processes({}), 3)

    def test_unlimited_cgroup_uses_available_memory_not_total_memory(self):
        self.limit()
        (self.proc / 'meminfo').write_text(f'MemTotal: {64 * GIB // 1024} kB\nMemAvailable: {(3 * WORKER + RESERVE) // 1024} kB\n')
        self.assertEqual(budget.processes({}), 3)

    def test_reserves_gate_overhead_once_before_dividing_memory(self):
        self.limit(memory=3 * WORKER + RESERVE - 1)
        self.assertEqual(budget.processes({}), 2)
        self.limit(memory=3 * WORKER + RESERVE)
        self.assertEqual(budget.processes({}), 3)

    def test_cpu_quota_bounds_affinity(self):
        self.limit(cpu='350000 100000')
        self.assertEqual(budget.processes({}), 3)

    def test_parent_cpu_quota_bounds_leaf(self):
        self.limit(cpu='800000 100000')
        self.limit('user.slice', cpu='200000 100000')
        self.assertEqual(budget.processes({}), 2)

    def test_affinity_can_be_smaller_than_quota(self):
        self.limit(cpu='800000 100000')
        with patch.object(budget.os, 'sched_getaffinity', return_value={0, 1}):
            self.assertEqual(budget.processes({}), 2)

    def test_allows_one_worker_with_exhausted_memory_or_fractional_cpu(self):
        self.limit(memory=WORKER, current=2 * WORKER, cpu='50000 100000')
        self.assertEqual(budget.processes({}), 1)

    def test_lane_share_is_verbatim_and_does_not_read_resources(self):
        shutil.rmtree(self.proc)
        self.assertEqual(budget.processes({'ORBIT_CHECK_PROCESSES': '99'}), 99)
        for invalid in ('0', '-1', '1.5', ' 2', '2\n'):
            with self.subTest(invalid=invalid), self.assertRaises(ValueError):
                budget.processes({'ORBIT_CHECK_PROCESSES': invalid})

    def test_missing_memory_reading_fails(self):
        (self.proc / 'meminfo').write_text('MemTotal: 100000 kB\n')
        with self.assertRaises(ValueError):
            budget.processes({})

    def test_unreadable_cgroup_does_not_use_host_resources(self):
        shutil.rmtree(self.cgroup / 'user.slice/task.scope')
        with self.assertRaises(ValueError):
            budget.processes({})

    def test_macos_uses_available_pages_and_reported_page_size(self):
        pages = 'Mach Virtual Memory Statistics: (page size of 16384 bytes)\nPages free: 81920.\nPages inactive: 81920.\nPages speculative: 81920.\nPages wired down: 900000.\n'
        with patch.object(budget.sys, 'platform', 'darwin'), patch.object(budget.os, 'cpu_count', return_value=12), patch('subprocess.check_output', return_value=pages):
            self.assertEqual(budget.processes({}), 2)

    def test_root_suites_share_one_total_budget(self):
        for total in (1, 2, 4, 10):
            with self.subTest(total=total), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'bin').mkdir()
                (root / 'tools').mkdir()
                shutil.copy2(REPOSITORY / 'bin/test', root / 'bin/test')
                for helper in (REPOSITORY / 'tools').glob('check_budget.py'):
                    shutil.copy2(helper, root / 'tools' / helper.name)
                projects = ('apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk')
                for project in projects:
                    pest = root / project / 'vendor/bin/pest'
                    pest.parent.mkdir(parents=True)
                    pest.touch(mode=0o755)
                helper = root / 'bin/check-processes'
                helper.write_text(f'#!/bin/sh\necho {total}\n')
                helper.chmod(0o755)
                worker = root / 'bin/pest-plain'
                worker.write_text('''#!/usr/bin/env python3
import os, socket, sys
with socket.socket(socket.AF_UNIX) as client:
    client.connect(os.environ['WORKER_SOCKET'])
    share = next(argument.split('=')[1] for argument in sys.argv if argument.startswith('--processes='))
    client.sendall((share + '\\n').encode())
    client.recv(1)
''')
                worker.chmod(0o755)
                with socket.socket(socket.AF_UNIX) as server:
                    server.bind(str(root / 'workers.sock'))
                    server.listen(10)
                    server.settimeout(10)
                    environment = {**os.environ, 'WORKER_SOCKET': str(root / 'workers.sock')}
                    with subprocess.Popen([str(root / 'bin/test')], env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) as process:
                        completed = 0
                        peaks = []
                        try:
                            while completed < 5:
                                clients = []
                                try:
                                    client, _ = server.accept()
                                    clients.append(client)
                                    # Workers wait for the parent acknowledgement. Drain every concurrent start
                                    # before releasing a wave. The timeout bounds observation, not worker duration.
                                    server.settimeout(0.5)
                                    while True:
                                        try:
                                            client, _ = server.accept()
                                            clients.append(client)
                                        except socket.timeout:
                                            break
                                    shares = [int(client.recv(64)) for client in clients]
                                    peaks.append(sum(shares))
                                    completed += len(clients)
                                finally:
                                    for client in clients:
                                        client.sendall(b'x')
                                        client.close()
                                    server.settimeout(10)
                            stdout, stderr = process.communicate(timeout=10)
                        except BaseException:
                            process.kill()
                            raise
                    self.assertEqual(process.returncode, 0, stdout + stderr)
                    self.assertEqual(completed, 5)
                    self.assertLessEqual(max(peaks), total, peaks)

    def test_root_test_runner_ignores_removed_test_process_alias(self):
        (self.root / 'bin').mkdir()
        shutil.copy2(REPOSITORY / 'bin/test', self.root / 'bin/test')
        (self.root / 'tools').mkdir()
        for helper in (REPOSITORY / 'tools').glob('check_budget.py'):
            shutil.copy2(helper, self.root / 'tools' / helper.name)
        helper = self.root / 'bin/check-processes'
        helper.write_text('#!/usr/bin/env bash\nprintf "%s\\n" "${ORBIT_CHECK_PROCESSES:-10}"\n')
        helper.chmod(0o755)
        runner = self.root / 'bin/pest-plain'
        runner.write_text('#!/usr/bin/env bash\nexit 0\n')
        runner.chmod(0o755)
        for project in ('apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'):
            pest = self.root / project / 'vendor/bin/pest'
            pest.parent.mkdir(parents=True)
            pest.touch(mode=0o755)
        environment = {key: value for key, value in os.environ.items() if key != 'ORBIT_CHECK_PROCESSES'}
        completed = subprocess.run([str(self.root / 'bin/test')], env={**environment, 'ORBIT_TEST_PROCESSES': '50'}, capture_output=True, text=True, check=False)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertIn('5 suites within a budget of 10 Pest processes.', completed.stdout)


if __name__ == '__main__':
    unittest.main()
