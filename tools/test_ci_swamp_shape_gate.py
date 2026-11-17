"""CI and local runs share one Swamp shape gate script, so they cannot drift."""
import io
import json
import re
import runpy
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
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
        shape = WORKFLOW.split('\n  shape:', 1)[1].split('\n  validate:', 1)[0]
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

    def test_no_after_merge_stage_runs_fixtures(self):
        # ADR 0262: fixtures run only on the pull request; no workflow runs them after a merge.
        self.assertNotIn('  fixtures:', WORKFLOW)
        self.assertIn("cron: '17 */3 * * *'", WORKFLOW)  # Validation retains its schedule.
        workflows = {path.name for path in (ROOT / '.github/workflows').glob('*.yml')}
        self.assertFalse(workflows & {'ci-deferred.yml', 'ci-full-set.yml', 'ci-main-proof.yml', 'ci-main-report.yml'})
        for path in (ROOT / '.github/workflows').glob('*.yml'):
            text = path.read_text()
            for name in ('Main full set', 'Main deferred fixtures', 'Main copy proof', 'ORBIT_PROOF_BLOCKING', 'ORBIT_SLOW_CHECKS_BLOCKING'):
                self.assertNotIn(name, text, path.name)

    def test_the_fixture_tools_cache_key_follows_the_interpreter(self):
        # A venv of another interpreter fails to load its libpython, so a new interpreter must miss the cache.
        import yaml
        steps = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']['fixtures']['steps']
        python = next(step for step in steps if step.get('uses', '').startswith('actions/setup-python@'))
        cache = next(step for step in steps if step.get('name') == 'Cache pinned fixture tools and collections')
        self.assertLess(steps.index(python), steps.index(cache))
        key = cache['with']['key']
        for output in ('python-version', 'python-path'):
            self.assertIn(f"${{{{ steps.{python['id']}.outputs.{output} }}}}", key)
        self.assertNotRegex(key, r'py\d', 'the key names no fixed Python version')

    def test_the_linter_pins_live_only_in_the_requirements_file(self):
        pins = (ROOT / 'swamp/ansible/shape-gate-requirements.txt').read_text()
        for pin in ('ruff==', 'shellcheck-py==', 'shfmt-py==', 'ansible-lint=='):
            self.assertIn(pin, pins)
            self.assertNotIn(pin, WORKFLOW)
            self.assertNotIn(pin, (ROOT / 'bin/swamp-shape-gate').read_text())
        self.assertIn("-r requirements.txt", pins)

    def test_ci_runs_the_local_lint_group_with_an_interpreter_bound_cache(self):
        import yaml
        lint = yaml.safe_load(WORKFLOW)['jobs']['lint']
        self.assertEqual(lint['if'], '${{ !inputs.changed-only || inputs.shape }}')
        steps = lint['steps']
        self.assertIn('bin/swamp-shape-gate --only lint --strict', [step.get('run') for step in steps])
        python = next(step for step in steps if step.get('uses', '').startswith('actions/setup-python@'))
        cache = next(step for step in steps if step.get('uses', '').startswith('actions/cache@'))
        for output in ('python-version', 'python-path'):
            self.assertIn(f"${{{{ steps.{python['id']}.outputs.{output} }}}}", cache['with']['key'])


class PlanTest(unittest.TestCase):
    def test_the_shape_group_runs_ci_s_checks_in_ci_s_order(self):
        names = [step.name for step in steps('shape')]
        self.assertEqual(names[:2], ['ansible collections', 'ansible-lint'])
        self.assertEqual(names[-5:], ['check-swamp-shape', 'check-swamp-base-policy', 'lint-behavior-names', 'frozen agent commands',
                                     'workflow placement'])
        # Eight named suites plus the file commands, with three Instance-host groups.
        self.assertEqual(len([name for name in names if name.startswith('deno test')]),
                         8 + len(gate['deno_test_files']()) + len(gate['DENO_CONTROL_TESTS']))
        self.assertTrue(any('tests/ansible_instance_host_test.ts' in name for name in names))
        for step in steps('shape')[-5:-2]:
            self.assertEqual(step.argv[step.argv.index('--base') + 1], BASE)
        self.assertIn('/reports/shape.json', steps('shape')[-5].argv)

    def test_control_runs_the_writes_audit_and_all_static_rules_without_fixtures(self):
        control = [step for step in steps('shape') if not step.parallel and step.name != 'ansible collections']
        self.assertEqual([step.name for step in control],
                         ['deno test tests/writes_declared_test.ts', 'check-swamp-shape', 'check-swamp-base-policy',
                          'lint-behavior-names', 'frozen agent commands', 'workflow placement'])
        code, calls, output = ExitCodeTest().run_gate(['--only', 'control', '--base', BASE], [])
        self.assertEqual(code, 0, output)
        self.assertEqual(len(calls), len(control))
        self.assertEqual([argv[0] for argv in calls], [step.argv[0] for step in control])
        self.assertEqual(calls[0], ['deno', 'test', '--allow-read', 'tests/writes_declared_test.ts'])
        for failure, step in enumerate(control):
            code, calls, output = ExitCodeTest().run_gate(['--only', 'control', '--base', BASE], [0] * failure + [1])
            self.assertEqual(code, 1)
            self.assertEqual(len(calls), len(control))
            self.assertIn('FAILED: ' + step.name, output)

    def test_full_shape_executes_the_writes_audit_once_with_read_only_permissions(self):
        code, calls, output = ExitCodeTest().run_gate(['--only', 'shape', '--base', BASE], [])
        self.assertEqual(code, 0, output)
        audit = [argv for argv in calls if 'tests/writes_declared_test.ts' in argv]
        self.assertEqual(audit, [['deno', 'test', '--allow-read', 'tests/writes_declared_test.ts']])

    def test_full_shape_uses_exactly_cis_selected_shards_and_runner(self):
        selected = [['swamp/tests/ansible_inputs_test.py', 'swamp/tests/instance_machine_name_test.py'],
                    ['swamp/tests/ansible_instance_host_runtime_test.ts#0/5', 'swamp/tests/ansible_instance_host_runtime_test.ts#4/5']]
        planned = {'shards': [{'units': selected[0]}], 'self_hosted_shards': [{'units': selected[1]}], 'unmapped': []}
        scope = gate['main'].__globals__
        # A two-lane CI plan must retain two lanes even on a one-CPU host or with --workers 1.
        for arguments, cpus in (([], 8), (['--workers', '1'], 8), ([], 1)):
            with self.subTest(arguments=arguments, cpus=cpus), tempfile.TemporaryDirectory() as scratch:
                with patch.dict(scope, {'tool_path': lambda _: Path('/tools')}), \
                        patch.object(scope['os'], 'cpu_count', return_value=cpus), \
                        patch.object(scope['shutil'], 'which', return_value='/tool'), \
                        patch.object(subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, stdout=json.dumps(planned))) as runner, \
                        redirect_stdout(io.StringIO()):
                    code = gate['main'](['--only', 'shape', '--reports', scratch, '--tools', '/custom-tools', '--base', BASE, *arguments])
                self.assertEqual(code, 0)
                calls = [call.args[0] for call in runner.call_args_list]
                self.assertEqual(calls[0], [sys.executable, str(ROOT / 'bin/ci-units'), 'plan',
                                 '--base', BASE, '--head', 'HEAD', '--plan-json', str(Path(scratch) / 'fixture-plan.json')])
                commands = [argv for argv in calls if argv[:3] == [sys.executable, str(ROOT / 'bin/ci-units'), 'run']]
                self.assertEqual([json.loads(argv[argv.index('--units') + 1]) for argv in commands], selected)
                for argv in commands:
                    self.assertEqual(argv[argv.index('--workers') + 1], '2')
                    self.assertEqual(argv[argv.index('--tools') + 1], '/custom-tools')
                    self.assertTrue(Path(argv[argv.index('--times') + 1]).is_absolute())

    def test_ci_capacity_failure_fails_the_gate_before_execution(self):
        scope = gate['main'].__globals__
        planned = {'shards': [], 'self_hosted_shards': [], 'unmapped': [], 'capacity': [{'unplaced': ['a-unit']}]}
        with patch.object(subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, stdout=json.dumps(planned))), \
                patch.dict(scope, {'tool_path': lambda _: Path('/tools')}), \
                patch.object(scope['shutil'], 'which', return_value='/tool'), \
                patch.dict(scope, {'execute': Mock(side_effect=AssertionError('must not run a rejected plan'))}), \
                tempfile.TemporaryDirectory() as scratch, redirect_stderr(io.StringIO()) as errors:
            code = gate['main'](['--reports', scratch, '--base', BASE])
        self.assertEqual(code, 1)
        self.assertIn('fails CI admission', errors.getvalue())

    def test_lint_runs_only_the_pinned_linters_and_collections_without_a_base(self):
        code, calls, output = ExitCodeTest().run_gate(['--only', 'lint'], [])
        self.assertEqual(code, 0, output)
        self.assertEqual([argv[0] for argv in calls], ['ansible-galaxy', 'ansible-lint', 'ruff'])

    def test_control_installs_the_pinned_tools_its_analyzers_call(self):
        scope = gate['main'].__globals__
        with tempfile.TemporaryDirectory() as scratch:
            install = Mock(return_value=Path(scratch))
            with patch.dict(scope, {'tool_path': install, 'execute': lambda *_args: []}), \
                    patch.object(scope['shutil'], 'which', return_value='/tool'), redirect_stdout(io.StringIO()):
                code = gate['main'](['--only', 'control', '--base', BASE, '--reports', scratch])
        self.assertEqual(code, 0)
        install.assert_called_once()

    def test_the_validation_group_needs_credentials_where_ci_does(self):
        needs = {step.name: step.needs for step in steps('validate')}
        self.assertEqual(needs['validate-swamp-workflows'], ('credentials',))
        self.assertEqual(needs['node enrollment trust'], ('credentials',))
        self.assertEqual(needs['check-grant-patterns'], ('credentials', 'php'))
        self.assertNotIn('workflow placement', needs)
        # It needs no credential, so the static controls of the shape job run it.
        self.assertEqual(next(step for step in steps('shape') if step.name == 'workflow placement').needs, ())

    def test_credentials_decide_the_skip(self):
        validate = next(step for step in steps('validate') if step.name == 'validate-swamp-workflows')
        self.assertIn('no Swamp credential', gate['skip_reason'](validate, {'PATH': ''}))
        self.assertIsNone(gate['skip_reason'](validate, {'PATH': '', 'SWAMP_API_KEY': 'x', 'SWAMP_SIGNIN_TOKEN': 'y'}))

    def test_missing_fixture_programs_name_the_tools_and_fail_without_strict(self):
        planned = {'shards': [], 'self_hosted_shards': [{'units': ['swamp/tests/ansible_instance_host_runtime_test.ts#4/5']}], 'unmapped': []}
        with patch.object(subprocess, 'run', return_value=subprocess.CompletedProcess([], 0, stdout=json.dumps(planned))):
            expanded = gate['fixture_commands'](steps('shape'), Path('/reports'), Path('/tools'), BASE)
        runtime = next(step for step in expanded if step.name == 'CI fixture shard 1')
        code, calls, output = ExitCodeTest().run_gate(['--only', 'shape', '--base', BASE], [],
                                                    selected_steps=[runtime], available=lambda *_args, **_kwargs: '/tool')
        self.assertEqual(code, 0, output)
        for strict in ([], ['--strict']):
            code, calls, output = ExitCodeTest().run_gate(['--only', 'shape', '--base', BASE, *strict], [],
                selected_steps=[runtime], available=lambda tool, **_: None if tool in ('zip', 'unzip') else '/tool')
            self.assertEqual(code, 1, output)
            self.assertEqual(calls, [])
            self.assertIn('required tools not found on PATH:', output)
            self.assertIn('zip', output)
            self.assertIn('unzip', output)


class ParallelTest(unittest.TestCase):
    def test_prerequisites_and_ratchets_stay_serial(self):
        shape = steps('shape')
        control_count = 5 + len(gate['DENO_CONTROL_TESTS'])
        self.assertFalse(shape[0].parallel)
        self.assertTrue(all(step.parallel for step in shape[1:-control_count]))
        self.assertTrue(all(not step.parallel for step in shape[-control_count:]))
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
    def run_gate(self, argv, results, environment=None, selected_steps=None, available=None):
        """Run main with every step replaced by a result: 0 passes, anything else fails."""
        outcomes = iter(results)
        scope = gate['main'].__globals__
        calls = []

        def run(argv_, **_options):
            calls.append(argv_)
            return subprocess.CompletedProcess(argv_, next(outcomes, 0))

        with tempfile.TemporaryDirectory() as scratch, patch.dict(scope['os'].environ, environment or {}, clear=True), \
                patch.dict(scope, {'tool_path': lambda tools: Path(scratch),
                                   'fixture_commands': lambda steps, reports, tools, base: selected_steps if selected_steps is not None else steps}), \
                patch.object(scope['shutil'], 'which', side_effect=available or (lambda *_args, **_kwargs: '/tool')), \
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

    def test_missing_credentials_do_not_hide_a_missing_php_tool(self):
        code, calls, output = self.run_gate(['--only', 'validate'], [],
            available=lambda tool, **_: None if tool == 'php' else '/tool')
        self.assertEqual(code, 1, output)
        self.assertEqual(calls, [])
        self.assertIn('required tools not found on PATH: php', output)

    def test_strict_fails_clearly_without_running_credential_dependent_steps(self):
        code, calls, output = self.run_gate(['--only', 'validate', '--strict'], [])
        self.assertEqual(code, 1)
        self.assertEqual(calls, [])
        self.assertIn('set SWAMP_API_KEY and SWAMP_SIGNIN_TOKEN', output)

    def test_a_missing_deno_fails_before_git_or_fixtures_with_an_install_hint(self):
        scope = gate['main'].__globals__
        with tempfile.TemporaryDirectory() as scratch, \
                patch.object(scope['shutil'], 'which', side_effect=lambda tool, **_: None if tool == 'deno' else '/git'), \
                patch.dict(scope, {'resolve_base': Mock(side_effect=AssertionError('must not invoke Git'))}), \
                redirect_stderr(io.StringIO()) as error:
            code = gate['main'](['--reports', scratch])
        self.assertEqual(code, 2)
        self.assertIn('Missing gate prerequisites: deno', error.getvalue())
        self.assertIn('Deno 2.9.8 on PATH', error.getvalue())

    def test_tool_setup_failure_explains_python_and_network_prerequisites(self):
        scope = gate['main'].__globals__
        with tempfile.TemporaryDirectory() as scratch, \
                patch.dict(scope, {'tool_path': Mock(side_effect=subprocess.CalledProcessError(1, 'venv'))}), \
                redirect_stderr(io.StringIO()) as error:
            code = gate['main'](['--only', 'lint', '--tools', scratch, '--reports', scratch])
        self.assertEqual(code, 2)
        self.assertIn('Python 3.13+ with venv/pip', error.getvalue())
        self.assertIn('PyPI', error.getvalue())


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
