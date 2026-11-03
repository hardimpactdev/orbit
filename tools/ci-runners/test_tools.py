"""Exercise the managed tool installation and Node retirement with disposable command fakes."""
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

SOURCE = Path(__file__).with_name('setup.sh').read_text()


class ManagedToolsTest(unittest.TestCase):
    def test_installs_once_and_rejects_a_wrong_checksum(self):
        program = SOURCE.split('# Jobs use the managed tools', 1)[1].split('\n', 1)[1].split('if [ ! -x /home/runner/actions-runner/config.sh ]', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            binary = root / 'bin'
            binary.mkdir()
            environment = dict(os.environ, PATH=f'{binary}:{os.environ["PATH"]}',
                               DENO_VERSION='2.9.8', BUN_VERSION='1.4.2', TOOL_ROOT=str(root))
            for name, member, output in [('deno', 'deno', 'deno 2.9.8 (stable, release, x86_64-unknown-linux-gnu)'),
                                         ('bun', 'bun-linux-x64/bun', '1.4.2')]:
                archive = root / (name + '.zip')
                with zipfile.ZipFile(archive, 'w') as zipped:
                    zipped.writestr(member, f'#!/bin/sh\necho "{output}"\n')
                environment[name.upper() + '_SHA256'] = hashlib.sha256(archive.read_bytes()).hexdigest()
            stale = '#!/bin/sh\necho 0.0.0\n'
            for name in ['deno', 'bun']:
                (binary / name).write_text(stale)
                (binary / name).chmod(0o755)
            curl = binary / 'curl'
            curl.write_text('''#!/bin/sh
set -eu
case "$3" in *denoland*) name=deno;; *oven-sh*) name=bun;; *) exit 1;; esac
printf '%s\\n' "$name" >> "$TOOL_ROOT/downloads"
cp "$TOOL_ROOT/$name.zip" "$2"
''')
            curl.chmod(0o755)
            command = program.replace('/usr/local/bin/', str(binary) + '/')
            for _ in range(2):
                result = subprocess.run(['bash', '-euo', 'pipefail', '-c', command], env=environment,
                                        capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode, 0, result.stderr)
            self.assertEqual((root / 'downloads').read_text().splitlines(), ['deno', 'bun'])
            (binary / 'deno').write_text(stale)
            environment['DENO_SHA256'] = '0' * 64
            result = subprocess.run(['bash', '-euo', 'pipefail', '-c', command], env=environment,
                                    capture_output=True, text=True, timeout=30)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual((binary / 'deno').read_text(), stale)

    def test_node_retirement_refuses_packages_outside_the_node_stack(self):
        program = SOURCE.split('# These dedicated containers retire', 1)[1].split('\n', 1)[1].split('# Install and verify recipe Node', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            shim = root / 'shim'
            shim.write_text('''#!/bin/bash
set -eu
if [[ ${0##*/} == dpkg-query ]]; then printf installed; exit; fi
if [[ $1 == --simulate ]]; then printf '%s\\n' "$NODE_PLAN"; exit; fi
printf '%s\\n' "$*" > "$REMOVED"
''')
            shim.chmod(0o755)
            for name in ['dpkg-query', 'apt-get']:
                (root / name).symlink_to(shim)
            environment = dict(os.environ, PATH=f'{root}:{os.environ["PATH"]}', REMOVED=str(root / 'removed'))
            for plan, allowed in [('Remv nodejs\nRemv libnode127\nRemv node-corepack', True),
                                   ('Remv nodejs\nRemv python3', False), ('', False)]:
                environment['NODE_PLAN'] = plan
                (root / 'removed').unlink(missing_ok=True)
                result = subprocess.run(['bash', '-euo', 'pipefail', '-c', program], env=environment,
                                        capture_output=True, text=True, timeout=30)
                self.assertEqual(result.returncode == 0, allowed, result.stderr)
                self.assertEqual((root / 'removed').exists(), allowed)


if __name__ == '__main__':
    unittest.main()
