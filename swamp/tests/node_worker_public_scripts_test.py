"""Exercise the published checker with observed state; no host mutation."""
import runpy
import stat
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import mock_open, patch

SCRIPTS = Path(__file__).resolve().parents[1] / 'extensions/behaviors/operations/node-worker-assets/scripts'


class WorkerCheck(unittest.TestCase):
    def checker(self, memory='MemAvailable: 1 kB\n', token_mode=0o600):
        fn = runpy.run_path(str(SCRIPTS / 'worker_check.py'))['check']
        fake_os = SimpleNamespace(geteuid=lambda: 0, lstat=lambda path: SimpleNamespace(
            st_uid=0, st_mode=(stat.S_IFDIR | 0o700) if path == '/etc/orbit/swamp-worker'
            else (stat.S_IFREG | token_mode)))
        def run():
            with patch.dict(fn.__globals__, {'MINIMUM_MEMORY_BYTES': 1024, 'os': fake_os}), patch(
                    'builtins.open', mock_open(read_data=memory)):
                fn()
        return run

    def test_exact_floor_and_existing_root_only_files_pass(self):
        self.checker()()

    def test_low_or_ambiguous_memory_and_public_token_files_refuse(self):
        for memory in ('MemAvailable: 0 kB\n', 'MemAvailable: 1 kB\nMemAvailable: 2 kB\n', 'invalid\n'):
            with self.assertRaises(ValueError):
                self.checker(memory=memory)()
        with self.assertRaises(ValueError):
            self.checker(token_mode=0o644)()

    def test_first_visit_preflight_requires_existing_nonroot_x64_linux_account(self):
        fn = runpy.run_path(str(SCRIPTS / 'preflight.py'))['main']
        context = {'WORKER_USER': 'candidate', 'MINIMUM_MEMORY_BYTES': 1024,
                   'platform': SimpleNamespace(system=lambda: 'Linux', machine=lambda: 'x86_64'),
                   'pwd': SimpleNamespace(getpwnam=lambda name: SimpleNamespace(pw_uid=1000))}
        with patch.dict(fn.__globals__, context), patch('builtins.open', mock_open(read_data='MemAvailable: 1 kB\n')):
            fn()
        context['pwd'] = SimpleNamespace(getpwnam=lambda name: SimpleNamespace(pw_uid=0))
        with patch.dict(fn.__globals__, context), self.assertRaises(ValueError):
            fn()
        context['platform'] = SimpleNamespace(system=lambda: 'Linux', machine=lambda: 'aarch64')
        with patch.dict(fn.__globals__, context), self.assertRaises(ValueError):
            fn()


if __name__ == '__main__':
    unittest.main()
