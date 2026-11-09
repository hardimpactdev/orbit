"""Keep expensive jobs on the managed pool without sending fork code to it."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POOL = "${{ vars.ORBIT_SABRE_RUNNER == 'true' && (github.event_name != 'pull_request' || github.event.pull_request.head.repo.full_name == github.repository) && fromJSON('[\"self-hosted\",\"orbit-shard\"]') || 'ubuntu-26.04' }}"


def job(workflow, name):
    source = (ROOT / '.github/workflows' / workflow).read_text()
    return re.search(r'^  ' + re.escape(name) + r':\n(.*?)(?=^  [\w-]+:|\Z)', source, re.M | re.S).group(1)


class RunnerPolicyTest(unittest.TestCase):
    def test_unit_job_keeps_the_pool_switch_and_same_repository_guard(self):
        for workflow, name in [('swamp-shape.yml', 'tests')]:
            with self.subTest(job=name):
                block = job(workflow, name)
                self.assertEqual(re.search(r'^    runs-on: (.+)$', block, re.M).group(1), POOL)
                self.assertNotIn('sudo -n true', block)
                self.assertNotIn('docker info', block)
                self.assertNotIn('shivammathur/setup-php', block)

    def test_light_jobs_and_validation_stay_hosted(self):
        for workflow, names in [('ci.yml', ['docs', 'web', 'api-reference']),
                                ('swamp-shape.yml', ['validate'])]:
            for name in names:
                with self.subTest(job=name):
                    self.assertIn('runs-on: ubuntu-26.04', job(workflow, name))

    def test_required_proof_never_runs_fork_code_on_a_node(self):
        block = job("ci.yml", "proof-run")
        self.assertIn("github.event_name == 'pull_request'", block)
        self.assertIn("github.event.pull_request.head.repo.full_name == github.repository", block)
        self.assertIn("cancel-in-progress: true", block)
        required = job("ci.yml", "required")
        self.assertIn("proof-run", required)

    def test_required_gate_includes_proof_and_has_no_capacity_skip(self):
        request = job('ci.yml', 'proof-run')
        required = job('ci.yml', 'required')
        self.assertIn('PROOF_RECEIPT:', request)
        self.assertIn('if: always()', request)
        self.assertIn("selected['proof-run']", required)
        self.assertIn('PROOF_REQUIRED:', required)
        self.assertNotIn('not_run_capacity', (ROOT / '.github/workflows/ci.yml').read_text())
        self.assertNotIn('PROOF_REPORT_ONLY', request)

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
