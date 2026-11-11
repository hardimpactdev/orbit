"""CI and local runs share one Swamp shape gate script, so they cannot drift."""
import io
import re
import runpy
import subprocess
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import Mock, patch

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
gate = runpy.run_path(str(ROOT / 'bin/swamp-shape-gate'))
review = runpy.run_path(str(ROOT / 'bin/review-check'))
BASE = 'a' * 40


def steps(only=None):
    return [step for step in gate['plan'](BASE, Path('/reports'), Path('/cache'), Path('/collections')) if only in (None, step.group)]


class WorkflowCallsTheScriptTest(unittest.TestCase):
    def test_both_jobs_call_the_script_and_keep_no_inline_steps(self):
        shape = WORKFLOW.split('\n  shape:', 1)[1].split('  fixtures:', 1)[0]
        validate = WORKFLOW.split('\n  validate:', 1)[1].split('\n  tests:', 1)[0]
        self.assertIn('python3 "$GITHUB_WORKSPACE/bin/swamp-shape-gate" --only control --strict --base "$SHAPE_BASE" --reports "$RUNNER_TEMP"', shape)
        self.assertIn('python3 "$GITHUB_WORKSPACE/bin/swamp-shape-gate" --only validate --strict', validate)
        for inline in ('pip install', 'ansible-lint', 'check-swamp-shape.ts', 'check-swamp-base-policy.py', 'deno test',
                       'check-frozen-agent-commands', 'validate-swamp-workflows.py', 'check-grant-patterns.py'):
            self.assertNotIn(inline, shape + validate)

    def test_the_artifacts_and_the_credential_scope_stay(self):
        self.assertIn('name: Swamp shape gate', WORKFLOW)
        self.assertIn('name: Swamp workflow validate', WORKFLOW)
        self.assertIn('${{ runner.temp }}/shape.json', WORKFLOW)
        for report in ('workflow-validation.json', 'grant-validation.json'):
            self.assertIn('${{ runner.temp }}/' + report, WORKFLOW)

    def test_full_fixtures_are_only_manual_or_scheduled_and_do_not_use_beast(self):
        fixtures = WORKFLOW.split('  fixtures:', 1)[1].split('\n  validate:', 1)[0]
        self.assertIn("if: ${{ !inputs.changed-only && (github.event_name == 'schedule' || github.event_name == 'workflow_dispatch') }}", fixtures)
        self.assertIn('runs-on: ubuntu-26.04', fixtures)
        self.assertIn('cancel-in-progress: true', fixtures)
        self.assertIn("cron: '17 */3 * * *'", WORKFLOW)
        required = (ROOT / '.github/workflows/ci.yml').read_text().split('  required:', 1)[1]
        self.assertNotIn('Full shape fixtures', required)

    def test_the_linter_pins_live_only_in_the_requirements_file(self):
        pins = (ROOT / 'swamp/ansible/shape-gate-requirements.txt').read_text()
        for pin in ('ruff==', 'shellcheck-py==', 'shfmt-py==', 'ansible-lint=='):
            self.assertIn(pin, pins)
            self.assertNotIn(pin, WORKFLOW)
            self.assertNotIn(pin, (ROOT / 'bin/swamp-shape-gate').read_text())
        self.assertIn("-r requirements.txt", pins)


class PlanTest(unittest.TestCase):
    def test_the_shape_group_runs_ci_s_checks_in_ci_s_order(self):
        names = [step.name for step in steps('shape')]
        self.assertEqual(names[:2], ['ansible collections', 'ansible-lint'])
        self.assertEqual(names[-4:], ['check-swamp-shape', 'check-swamp-base-policy', 'lint-behavior-names', 'frozen agent commands'])
        # Eight named suites plus the file commands, with three Instance-host groups.
        self.assertEqual(len([name for name in names if name.startswith('deno test')]), 8 + len(gate['deno_test_files']()))
        self.assertTrue(any('tests/ansible_instance_host_test.ts' in name for name in names))
        for step in steps('shape')[-4:-1]:
            self.assertEqual(step.argv[step.argv.index('--base') + 1], BASE)
        self.assertIn('/reports/shape.json', steps('shape')[-4].argv)

    def test_control_runs_all_four_static_rules_without_fixtures(self):
        control = [step for step in steps('shape') if not step.parallel and step.name != 'ansible collections']
        self.assertEqual([step.name for step in control],
                         ['check-swamp-shape', 'check-swamp-base-policy', 'lint-behavior-names', 'frozen agent commands'])
        code, calls, output = ExitCodeTest().run_gate(['--only', 'control', '--base', BASE], [])
        self.assertEqual(code, 0, output)
        self.assertEqual(len(calls), 4)
        self.assertEqual([argv[0] for argv in calls], [step.argv[0] for step in control])
        for failure in range(4):
            code, calls, _ = ExitCodeTest().run_gate(['--only', 'control', '--base', BASE], [0] * failure + [1])
            self.assertEqual(code, 1)
            self.assertEqual(len(calls), 4)

    def test_control_installs_the_pinned_tools_its_analyzers_call(self):
        scope = gate['main'].__globals__
        with tempfile.TemporaryDirectory() as scratch:
            install = Mock(return_value=Path(scratch))
            with patch.dict(scope, {'tool_path': install, 'execute': lambda *_args: []}), redirect_stdout(io.StringIO()):
                code = gate['main'](['--only', 'control', '--base', BASE, '--reports', scratch])
        self.assertEqual(code, 0)
        install.assert_called_once()

    def test_the_validation_group_needs_credentials_where_ci_does(self):
        needs = {step.name: step.needs for step in steps('validate')}
        self.assertEqual(needs['validate-swamp-workflows'], ('credentials',))
        self.assertEqual(needs['node enrollment trust'], ('credentials',))
        self.assertEqual(needs['check-grant-patterns'], ('credentials', 'php'))
        self.assertEqual(needs['workflow placement'], ())

    def test_credentials_decide_the_skip(self):
        validate = next(step for step in steps('validate') if step.name == 'validate-swamp-workflows')
        self.assertIn('no Swamp credential', gate['skip_reason'](validate, {'PATH': ''}))
        self.assertIsNone(gate['skip_reason'](validate, {'PATH': '', 'SWAMP_API_KEY': 'x', 'SWAMP_SIGNIN_TOKEN': 'y'}))


class ParallelTest(unittest.TestCase):
    def test_prerequisites_and_ratchets_stay_serial(self):
        shape = steps('shape')
        self.assertFalse(shape[0].parallel)
        self.assertTrue(all(step.parallel for step in shape[1:-4]))
        self.assertTrue(all(not step.parallel for step in shape[-4:]))
        self.assertTrue(all(not step.parallel for step in steps('validate')))

    def test_independent_steps_overlap_with_a_bounded_budget_and_isolated_outputs(self):
        import threading
        import sys
        scope = gate['execute'].__globals__
        barrier = threading.Barrier(2, timeout=10)
        lock = threading.Lock()
        active = 0
        peak = 0
        environments = []

        def run(argv, **options):
            nonlocal active, peak
            with lock:
                active += 1
                peak = max(peak, active)
                environments.append(options['env'])
            barrier.wait()
            options['stdout'].write(argv[-1] + '\n')
            with lock:
                active -= 1
            return subprocess.CompletedProcess(argv, 1 if argv[-1] == 'bad' else 0)

        commands = [gate['Step']('shape', name, [sys.executable, name], parallel=True)
                    for name in ('first', 'bad', 'third', 'fourth')]
        with patch.object(scope['subprocess'], 'run', run), redirect_stdout(io.StringIO()) as output:
            results = gate['execute'](commands, {'PATH': '/usr/bin'}, True, 2)
        self.assertEqual(peak, 2)
        self.assertEqual(len({env['TMPDIR'] for env in environments}), 4)
        self.assertTrue(all(all(env[key] == '1' for key in ('DENO_JOBS', 'ANSIBLE_FORKS', 'PYTHON_CPU_COUNT', 'RAYON_NUM_THREADS')) for env in environments))
        self.assertEqual([r[1] for r in results], ['passed', 'FAILED', 'passed', 'passed'])
        for name in ('first', 'bad', 'third', 'fourth'):
            self.assertIn(name + '\n', output.getvalue())

    def test_an_invalid_worker_budget_fails(self):
        with self.assertRaises(SystemExit):
            gate['main'](['--workers', '0'])


class BaseTest(unittest.TestCase):
    def test_a_given_base_is_used_and_an_empty_or_zero_base_means_the_previous_commit(self):
        self.assertEqual(gate['resolve_base'](BASE), BASE)
        previous = subprocess.CompletedProcess([], 0, stdout=f'{"b" * 40}\n')
        for empty in ('', '0' * 40):
            with patch.dict(gate['resolve_base'].__globals__, {'git': lambda *args: previous}):
                self.assertEqual(gate['resolve_base'](empty), 'b' * 40)

    def test_the_default_is_the_merge_base_with_origin_main(self):
        calls = []

        def git(*args):
            calls.append(args)
            return subprocess.CompletedProcess([], 0, stdout='c' * 40 + '\n')

        with patch.dict(gate['resolve_base'].__globals__, {'git': git}):
            self.assertEqual(gate['resolve_base'](None), 'c' * 40)
        self.assertEqual(calls, [('merge-base', 'HEAD', 'origin/main')])


class ExitCodeTest(unittest.TestCase):
    def run_gate(self, argv, results, environment=None):
        """Run main with every step replaced by a result: 0 passes, anything else fails."""
        outcomes = iter(results)
        scope = gate['main'].__globals__
        calls = []

        def run(argv_, **_options):
            calls.append(argv_)
            return subprocess.CompletedProcess(argv_, next(outcomes, 0))

        with tempfile.TemporaryDirectory() as scratch, patch.dict(scope['os'].environ, environment or {}, clear=True), \
                patch.dict(scope, {'tool_path': lambda tools: Path(scratch)}), \
                patch.object(scope['subprocess'], 'run', run), redirect_stdout(io.StringIO()) as output:
            scope['os'].environ['PATH'] = '/usr/bin'
            code = gate['main'](['--reports', scratch, '--tools', scratch, '--workers', '1', *argv])
        return code, calls, output.getvalue()

    def test_a_failed_step_fails_the_run_and_the_other_steps_still_run(self):
        code, calls, output = self.run_gate(['--only', 'shape', '--base', BASE], [0, 1])
        self.assertEqual(code, 1)
        self.assertEqual(len(calls), len(steps('shape')))
        self.assertIn('FAILED: ansible-lint', output)

    def test_validation_without_credentials_is_skipped_and_the_validation_run_exits_78(self):
        code, _calls, output = self.run_gate(['--only', 'validate'], [])
        self.assertEqual(code, 78)
        self.assertIn('skipped', output)
        code, _calls, _output = self.run_gate(['--only', 'shape', '--base', BASE], [])
        self.assertEqual(code, 0)

    def test_strict_runs_the_steps_that_need_credentials(self):
        _code, calls, _output = self.run_gate(['--only', 'validate', '--strict'], [])
        self.assertEqual(len(calls), len(steps('validate')))


class ReviewCheckTest(unittest.TestCase):
    def test_the_review_check_runs_the_script_when_the_change_selects_swamp(self):
        selected = dict(review['selected_projects'](['swamp/workflows/x.yaml'], ROOT, BASE))
        commands = selected['swamp']
        self.assertEqual([command[2:4] for command in commands], [('--only', 'shape'), ('--only', 'validate')])
        self.assertEqual(commands[0][-2:], ('--base', BASE))
        self.assertEqual(Path(commands[0][1]), ROOT / 'bin/swamp-shape-gate')

    def test_the_review_check_skips_the_script_without_swamp_inputs(self):
        projects = [project for project, _commands in review['selected_projects'](['apps/web/src/a.ts'], ROOT, BASE)]
        self.assertNotIn('swamp', projects)


if __name__ == '__main__':
    unittest.main()
