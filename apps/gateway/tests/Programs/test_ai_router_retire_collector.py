"""Run collector completion with rm restricted to a temporary copy of the two retired paths."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

PROGRAM = (Path(__file__).resolve().parents[2]
           / 'extensions/ai-router/resources/remote/ai-router/retire-collector.sh')


class RetireCollectorTest(unittest.TestCase):
    def test_complete_removes_only_retired_state_and_certificate_and_can_repeat(self):
        with tempfile.TemporaryDirectory(prefix='orbit-ai-router-complete-') as directory:
            root = Path(directory)
            retired = root / 'var/lib/orbit/proxycli'
            retired.mkdir(parents=True)
            (retired / 'collector.py').write_text('old collector')
            certificate = root / 'etc/caddy/orbit-proxycli-cert-current'
            certificate.parent.mkdir(parents=True)
            certificate.symlink_to('orbit-ai-router-cert-current')
            current = root / 'var/lib/orbit/ai-router/collector.py'
            current.parent.mkdir()
            current.write_text('current collector')
            current_certificate = root / 'etc/caddy/orbit-ai-router-cert-current'
            current_certificate.write_text('current certificate')
            unrelated = root / 'var/lib/orbit/unrelated'
            unrelated.write_text('keep')
            bin_directory = root / 'bin'
            bin_directory.mkdir()
            # Refuse any other rm arguments. The real rm sees only paths inside this fixture.
            (bin_directory / 'rm').write_text('''#!/bin/bash
set -eu
[[ "$#" == 4 && "$1" == -rf && "$2" == -- ]] || exit 64
[[ "$3" == /var/lib/orbit/proxycli && "$4" == /etc/caddy/orbit-proxycli-cert-current ]] || exit 64
exec /bin/rm -rf -- "$ROOT$3" "$ROOT$4"
''')
            (bin_directory / 'rm').chmod(0o755)
            (bin_directory / 'systemctl').write_text('#!/bin/bash\nexit 99\n')
            (bin_directory / 'systemctl').chmod(0o755)
            environment = {**os.environ, 'ROOT': str(root), 'PATH': f'{bin_directory}:{os.environ["PATH"]}'}

            for _ in range(2):
                completed = subprocess.run(['bash', '-seu', '--', '163', 'complete'], input=PROGRAM.read_text(),
                                           text=True, capture_output=True, env=environment, check=False)
                self.assertEqual(completed.returncode, 0, completed.stderr)
                self.assertEqual(completed.stdout, '')
                self.assertFalse(retired.exists())
                self.assertFalse(certificate.is_symlink())
                self.assertEqual(current.read_text(), 'current collector')
                self.assertEqual(current_certificate.read_text(), 'current certificate')
                self.assertEqual(unrelated.read_text(), 'keep')
