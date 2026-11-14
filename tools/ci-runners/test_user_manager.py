"""Start the runner's own persistent user manager without privilege inside jobs."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


class UserManagerTest(unittest.TestCase):
    def test_uses_runner_uid_and_proves_its_bus_without_restarting_actions(self):
        script = Path(__file__).with_name('user-manager.sh')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shim = root / 'shim'
            shim.write_text('''#!/usr/bin/env bash
set -euo pipefail
printf '%s %s\\n' "${0##*/}" "$*" >> "$MANAGER_LOG"
case ${0##*/} in
 id) test "$*" = '-u runner'; echo 1234 ;;
 loginctl) test "$*" = 'enable-linger runner' ;;
 systemctl) test "$*" = 'start user@1234.service' ;;
 runuser) test "$*" = '-u runner -- env XDG_RUNTIME_DIR=/run/user/1234 DBUS_SESSION_BUS_ADDRESS=unix:path=/run/user/1234/bus timeout 10 systemctl --user is-active default.target'; exit "${MANAGER_RESULT:-0}" ;;
 *) exit 2 ;;
esac
''')
            shim.chmod(0o755)
            for command in ('id', 'loginctl', 'systemctl', 'runuser'):
                (root / command).symlink_to(shim)
            environment = {**os.environ, 'PATH': f'{root}:{os.environ["PATH"]}', 'MANAGER_LOG': str(root / 'log')}
            for _ in range(2):
                subprocess.run(['bash', str(script)], env=environment, check=True, capture_output=True)
            rows = (root / 'log').read_text().splitlines()
            self.assertEqual(rows[:4], rows[4:])
            self.assertEqual(len(rows), 8)
            failed = subprocess.run(['bash', str(script)], env={**environment, 'MANAGER_RESULT': '3'}, capture_output=True)
            self.assertEqual(failed.returncode, 3)
        setup = script.with_name('setup.sh').read_text()
        self.assertIn('bash /root/orbit-php-build/tools/ci-runners/user-manager.sh', setup)
        self.assertIn('user_manager | verify)', setup)
