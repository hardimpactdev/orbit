"""Keep expensive jobs on the managed pool without sending fork code to it."""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
POOL = "${{ vars.ORBIT_SABRE_RUNNER == 'true' && (github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && fromJSON('[\"self-hosted\",\"orbit-shard\"]') || 'ubuntu-26.04' }}"


def job(workflow, name):
    source = (ROOT / '.github/workflows' / workflow).read_text()
    return re.search(r'^  ' + re.escape(name) + r':\n(.*?)(?=^  [\w-]+:|\Z)', source, re.M | re.S).group(1)


class RunnerPolicyTest(unittest.TestCase):
    def test_heavy_matrices_keep_the_pool_switch_and_same_repository_guard(self):
        for workflow, name in [('swamp-shape.yml', 'test-shards')]:
            with self.subTest(job=name):
                block = job(workflow, name)
                self.assertEqual(re.search(r'^    runs-on: (.+)$', block, re.M).group(1), POOL)
                self.assertNotIn('sudo -n true', block)
                self.assertNotIn('docker info', block)
                self.assertNotIn('shivammathur/setup-php', block)

    def test_light_jobs_and_validation_stay_hosted(self):
        for workflow, names in [('ci.yml', ['docs', 'web', 'api-reference']),
                                ('swamp-shape.yml', ['validate', 'tests'])]:
            for name in names:
                with self.subTest(job=name):
                    self.assertIn('runs-on: ubuntu-26.04', job(workflow, name))

    def test_self_hosted_php_is_verified_from_the_repository_root(self):
        action = (ROOT / '.github/actions/php/action.yml').read_text()
        installer, verifier = action.split('    - name: Verify the managed runner PHP build')
        self.assertIn("if: runner.environment != 'self-hosted'", installer)
        self.assertIn("if: runner.environment == 'self-hosted'", verifier)
        self.assertIn('working-directory: ${{ github.workspace }}', verifier)
        self.assertIn('python3 bin/php-build verify --profile ci-runner', verifier)

    def test_managed_tools_match_the_hosted_job_pins(self):
        setup = (ROOT / 'tools/ci-runners/setup.sh').read_text()
        for variable, workflow, key in [('BUN_VERSION', 'ci.yml', 'bun-version'),
                                         ('DENO_VERSION', 'swamp-shape.yml', 'deno-version')]:
            version = re.search(r'^' + variable + r'=(.+)$', setup, re.M).group(1)
            self.assertEqual(set(re.findall(key + r': v?([\d.]+)',
                             (ROOT / '.github/workflows' / workflow).read_text())), {version})


if __name__ == '__main__':
    unittest.main()
