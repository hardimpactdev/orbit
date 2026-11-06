"""Keep expensive jobs on the managed pool without sending fork code to it."""
from pathlib import Path
import json
import os
import re
import subprocess
import sys
import unittest

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

    def test_proof_run_is_required_and_never_runs_fork_code_on_a_node(self):
        block = job("ci.yml", "proof-run")
        self.assertIn("github.event_name == 'pull_request'", block)
        self.assertIn("github.event.pull_request.head.repo.full_name == github.repository", block)
        self.assertIn("cancel-in-progress: true", block)
        required = job("ci.yml", "required")
        self.assertIn("proof-run", re.search(r'needs: \[(.*?)\]', required).group(1))
        self.assertIn("selected['proof-run']", required)

    def test_required_checks_needs_the_proof_run_only_for_a_same_repository_pull_request_that_selects_a_command(self):
        required = job("ci.yml", "required")
        code = re.search(r"python3 - <<'PYTHON'\n(.*?)\n\s*PYTHON\n", required, re.S).group(1)
        code = '\n'.join(line[10:] for line in code.split('\n'))
        jobs = ['php', 'gateway-static', 'cli', 'cli-static', 'sdk-static', 'e2e-static', 'docs', 'api-reference', 'web', 'swamp', 'proof']
        cases = [
            ('selected, passed', 'true', 'true', 'success', 0),
            ('selected, red', 'true', 'true', 'failure', 1),
            ('selected, skipped', 'true', 'true', 'skipped', 1),
            ('no command selected, skipped', 'false', 'true', 'skipped', 0),
            ('no command selected, ran red', 'false', 'true', 'failure', 1),
            ('selected, pull request from outside', 'true', 'false', 'skipped', 0),
            ('selected, push', 'true', 'false', 'skipped', 0),
        ]
        for name, selected, same_repository, result, status in cases:
            with self.subTest(case=name):
                needs = {'changes': {'result': 'success', 'outputs': {'jobs': json.dumps({j: False for j in jobs}), 'gateway_release': 'false', 'proof_run': selected}},
                         'gateway-release': {'result': 'skipped', 'outputs': {}}, 'proof-run': {'result': result, 'outputs': {}}}
                needs.update({j: {'result': 'skipped', 'outputs': {}} for j in jobs})
                done = subprocess.run([sys.executable, '-c', code], env={**os.environ, 'NEEDS': json.dumps(needs), 'PROOF_RUN_EVENT': same_repository},
                                      capture_output=True, text=True)
                self.assertEqual(done.returncode, status, done.stderr)

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
