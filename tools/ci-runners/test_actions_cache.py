"""Exercise the managed action archive cache with Git objects and a fake Incus boundary."""
import os
import shutil
import subprocess
import tarfile
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = Path(__file__).with_name('setup.sh').read_text()
PROGRAM = 'actions() (' + SOURCE.split('actions() (', 1)[1].split('\nregister()', 1)[0]


class ActionCacheTest(unittest.TestCase):
    def setUp(self):
        scratch = ROOT / '.orbit-artifacts/runner-cache-tests'
        scratch.mkdir(parents=True, exist_ok=True)
        temporary = tempfile.TemporaryDirectory(dir=scratch)
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.repo = self.root / 'repo'
        self.repo.mkdir()
        self.git('init', '-q')
        (self.repo / 'action.yml').write_text('name: pinned\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'Pinned action')
        self.pin = self.git('rev-parse', 'HEAD')
        (self.repo / 'action.yml').write_text('name: newer\n')
        self.git('commit', '-qam', 'Unpinned change')
        workflow = self.repo / '.github/workflows/test.yml'
        workflow.parent.mkdir(parents=True)
        workflow.write_text(f'  - uses: actions/example@{self.pin}\n'
                            f'  - uses: actions/example/subdir@{self.pin}\n'
                            f'  - uses: actions/another@{self.pin}\n'
                            '  - uses: ./.github/actions/php\n')
        self.git('add', '.')
        self.env_file = self.root / 'runner.env'
        self.env_file.write_text('KEEP=fixture\n')
        self.cache = self.root / 'cache'
        self.shims = self.root / 'shims'
        self.shims.mkdir()
        real_git = shutil.which('git')
        self.executable('git', f'''#!/bin/bash
set -euo pipefail
args=()
for arg; do
  case "$arg" in
    https://github.com/actions/example.git|https://github.com/actions/another.git)
      [ "${{REFUSE_FETCH:-}}" != true ] || exit 73
      arg="$FIXTURE_SOURCE" ;;
    https://*) exit 74 ;;
  esac
  args+=("$arg")
done
exec "{real_git}" "${{args[@]}}"
''')
        self.executable('incus', '''#!/bin/bash
set -euo pipefail
[ "$1 $2" = 'file push' ]
cp "$3" "${4#fixture}"
''')
        self.executable('chown', '#!/bin/bash\nexit 0\n')

    def executable(self, name, source):
        path = self.shims / name
        path.write_text(source)
        path.chmod(0o755)

    def git(self, *args):
        return subprocess.check_output(['git', '-c', 'user.name=Fixture', '-c',
            'user.email=fixture@example.test', '-c', 'commit.gpgsign=false', *args],
            cwd=self.repo, text=True, stderr=subprocess.DEVNULL).strip()

    def run_cache(self, refuse=False):
        program = PROGRAM.replace('/opt/actionarchivecache', str(self.cache)).replace(
            '/home/runner/actions-runner/.env', str(self.env_file))
        return subprocess.run(['bash', '-euo', 'pipefail', '-c',
            'in_ct() { shift; cat > /dev/null; "$@"; }\n' + program + '\nactions fixture'],
            env={**os.environ, 'PATH': f'{self.shims}:{os.environ["PATH"]}',
                 'REPOSITORY': str(self.repo), 'PROJECT': 'orbit-ci',
                 'FIXTURE_SOURCE': self.repo.as_uri(), 'REFUSE_FETCH': str(refuse).lower()},
            text=True, capture_output=True, check=False)

    def test_pinned_archive_survives_reruns_without_fetch_or_environment_drift(self):
        result = self.run_cache()
        self.assertEqual(result.returncode, 0, result.stderr)
        archive = self.cache / 'actions_example' / f'{self.pin}.tar.gz'
        with tarfile.open(archive) as cached:
            self.assertEqual(cached.extractfile('action/action.yml').read(), b'name: pinned\n')
        self.assertEqual(set(self.cache.rglob('*.tar.gz')),
                         {archive, self.cache / 'actions_another' / f'{self.pin}.tar.gz'})
        original = archive.read_bytes()
        result = self.run_cache(refuse=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(archive.read_bytes(), original)
        self.assertEqual(self.env_file.read_text(),
            f'KEEP=fixture\nACTIONS_RUNNER_ACTION_ARCHIVE_CACHE={self.cache}\n')
        self.assertEqual(list((self.repo / '.orbit-artifacts/runner-actions').iterdir()), [])
        self.assertIn('restart its runner service when idle', result.stdout)

    def test_missing_commit_fails_and_cleans_staging_without_enabling_an_empty_cache(self):
        result = self.run_cache(refuse=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.env_file.read_text(), 'KEEP=fixture\n')
        self.assertEqual(list((self.repo / '.orbit-artifacts/runner-actions').iterdir()), [])


if __name__ == '__main__':
    unittest.main()
