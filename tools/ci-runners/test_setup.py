"""Check runner PostgreSQL setup without changing packages, services, or containers."""
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


class PostgreSQLSetupTest(unittest.TestCase):
    def test_configures_before_server_install_and_disables_service_on_every_run(self):
        source = Path(__file__).with_name('setup.sh').read_text()
        start = source.index('apt-get install -y -qq --no-install-recommends --no-upgrade postgresql-common')
        end = source.index('\n# A shard container', start)
        program = source[start:end]
        for setting in ('#create_main_cluster = true\n', 'create_main_cluster = true\n', ''):
            with self.subTest(setting=setting), tempfile.TemporaryDirectory() as directory:
                state = Path(directory)
                config = state / 'createcluster.conf'
                config.write_text("# keep other settings\nstart_conf = 'manual'\n" + setting)
                commands = state / 'commands'
                common = state / 'common-installed'
                shim = state / 'shim'
                shim.write_text('''#!/usr/bin/env bash
set -euo pipefail
printf '%s %s\n' "${0##*/}" "$*" >> "$SETUP_COMMANDS"
case ${0##*/} in
  apt-get)
    case ${*: -1} in
      postgresql-common) touch "$SETUP_COMMON" ;;
      postgresql-client-18)
        test -f "$SETUP_COMMON"
        grep -qx 'create_main_cluster = false' "$SETUP_CONFIG"
        ! grep -Eq '^create_main_cluster[[:space:]]*=[[:space:]]*true' "$SETUP_CONFIG"
        ;;
      *) exit 1 ;;
    esac ;;
  systemctl) test "$*" = 'disable --now postgresql' ;;
  *) exit 1 ;;
esac
''')
                shim.chmod(0o755)
                for name in ('apt-get', 'systemctl'):
                    (state / name).symlink_to(shim)
                environment = {**os.environ, 'PATH': f'{state}:{os.environ["PATH"]}',
                               'SETUP_CONFIG': str(config), 'SETUP_COMMON': str(common),
                               'SETUP_COMMANDS': str(commands)}
                for _ in range(2):
                    subprocess.run(['bash', '-euo', 'pipefail', '-c',
                                    program.replace('/etc/postgresql-common/createcluster.conf', str(config))],
                                   env=environment, check=True, capture_output=True, text=True)
                    self.assertEqual(config.read_text(),
                                     "# keep other settings\nstart_conf = 'manual'\n"
                                     + ('\n' if not setting else '') + 'create_main_cluster = false\n')
                self.assertEqual(commands.read_text().splitlines(), [
                    'apt-get install -y -qq --no-install-recommends --no-upgrade postgresql-common',
                    'apt-get install -y -qq --no-install-recommends --no-upgrade postgresql-18 postgresql-client-18',
                    'systemctl disable --now postgresql',
                ] * 2)


if __name__ == '__main__':
    unittest.main()
