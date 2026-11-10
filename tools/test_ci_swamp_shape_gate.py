"""CI and local runs share one Swamp shape gate script, so they cannot drift."""
import io
import json
import random
import shutil
from collections import Counter
import re
import runpy
import subprocess
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / '.github/workflows/swamp-shape.yml').read_text()
gate = runpy.run_path(str(ROOT / 'bin/swamp-shape-gate'))
review = runpy.run_path(str(ROOT / 'bin/review-check'))
BASE = 'a' * 40


def steps(only=None):
    return [step for step in gate['plan'](BASE, Path('/reports'), Path('/cache'), Path('/collections')) if only in (None, step.group)]


class WorkflowCallsTheScriptTest(unittest.TestCase):
    def test_both_jobs_call_the_script_and_keep_no_inline_steps(self):
        shape = WORKFLOW.split('  shape-shards:', 1)[1].split('  shape:', 1)[0]
        validate = WORKFLOW.split('  validate:', 1)[1].split('  tests:', 1)[0]
        self.assertIn('python3 "$GITHUB_WORKSPACE/bin/swamp-shape-gate" --only shape --strict --base "$SHAPE_BASE" --shard="$SHAPE_SHARD/3" --workers 3 --reports "$RUNNER_TEMP/shape-shard"', shape)
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
        self.assertEqual(names[0], 'ansible collections')
        self.assertIn('ansible-lint', names)
        self.assertEqual(names[-4:], ['check-swamp-shape', 'check-swamp-base-policy', 'lint-behavior-names', 'frozen agent commands'])
        # Eight named suites plus the file commands, with two balanced Instance-host groups.
        self.assertEqual(len([name for name in names if name.startswith('deno test')]), len(list(gate['expanded_unit_tests']())) + len(gate['deno_test_files']()))
        self.assertTrue(any('tests/ansible_instance_host_create_test.ts' in name for name in names))
        for step in steps('shape')[-4:-1]:
            self.assertEqual(step.argv[step.argv.index('--base') + 1], BASE)
        self.assertIn('/reports/shape.json', steps('shape')[-4].argv)

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
        self.assertIn('FAILED: ' + steps('shape')[1].name, output)

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


class ShardPartitionTest(unittest.TestCase):
    def signature(self, step):
        return (step.unit, tuple(step.argv), tuple(sorted(step.env.items())), step.needs, step.fixture_seconds)

    def test_three_shards_cover_every_file_exactly_once(self):
        # Build a temporary source tree from independent filesystem discovery, including nested tests.
        sources = [ROOT / 'swamp/tests', ROOT / 'swamp/bin']
        sources += [ROOT / 'swamp/extensions/behaviors' / name for name in
                    ('automatic-deploy', 'composer-maintenance', 'js-maintenance', 'projects')]
        with tempfile.TemporaryDirectory() as directory:
            tree = Path(directory)
            for source in sources:
                shutil.copytree(source, tree / source.relative_to(ROOT / 'swamp'))
            new = tree / 'tests/new/nested_test.ts'
            new.parent.mkdir(parents=True)
            new.write_text('Deno.test("new", () => {});\n')
            scope = gate['plan'].__globals__
            with patch.dict(scope, {'SWAMP': tree}):
                full = steps('shape')
                units = [step for step in full if step.parallel]
                parts = [gate['shard_units'](full, i) for i in range(1, 4)]
                actual = Counter(self.signature(step) for part in parts for step in part)
                self.assertEqual(actual, Counter(self.signature(step) for step in units))
                self.assertTrue(all(count == 1 for count in actual.values()))
                for i in range(3):
                    for j in range(i):
                        self.assertFalse({s.unit for s in parts[i]} & {s.unit for s in parts[j]})
                tests = [step for step in units if step.argv[0] in ('deno', 'python3')]
                planned = Counter(step.argv[-1] for step in tests)
                discovered = {str(path.relative_to(tree)) for source in sources for path in
                              (tree / source.relative_to(ROOT / 'swamp')).rglob('*')
                              if gate['DENO_TEST_FILE'].fullmatch(path.name)}
                # Bin includes tests owned by other gates. DENO_UNIT_TESTS explicitly owns its seven files.
                discovered = {p for p in discovered if not p.startswith('bin/') or p in
                              {a[-1] for a in gate['DENO_UNIT_TESTS'] if a[-1].startswith('bin/')}}
                discovered -= set(gate['DENO_TESTS_ELSEWHERE'])
                discovered |= {str(p.relative_to(tree)) for p in (tree / 'tests').rglob('ansible_*_test.py')}
                self.assertEqual(planned, Counter({path: 1 for path in discovered}))
                self.assertEqual(planned['tests/new/nested_test.ts'], 1)
                self.assertEqual({s.unit for s in units if s.argv[0] not in ('deno', 'python3')}, {'ruff', 'ansible-lint'})
                self.assertEqual(len([s for s in tests if 'ansible_instance_host_' in s.unit]), 13)
                self.assertTrue(all(len([arg for arg in s.argv if arg in discovered]) == 1 for s in tests))
                control = gate['select_steps'](full, control_only=True)
                self.assertEqual([s.name for s in control if s.name != 'ansible collections'],
                                 ['check-swamp-shape', 'check-swamp-base-policy', 'lint-behavior-names', 'frozen agent commands'])
                self.assertEqual([s for s in full if not s.parallel], control)
                # Discovery order must not affect assignment or execution order.
                rglob = Path.rglob
                def shuffled(path, pattern):
                    found = list(rglob(path, pattern))
                    random.Random(17).shuffle(found)
                    return iter(found)
                with patch.object(Path, 'rglob', shuffled):
                    shuffled_plan = steps('shape')
                    for i in range(1, 4):
                        self.assertEqual([self.signature(s) for s in gate['shard_units'](full, i)],
                                         [self.signature(s) for s in gate['shard_units'](shuffled_plan, i)])

    def test_invalid_shards_refuse_before_launching_any_child(self):
        for arguments in (['--shard', '0/3'], ['--shard', '4/3'], ['--shard', '1/2'],
                          ['--shard', 'x'], ['--shard', '1/3', '--control-only']):
            with self.subTest(arguments=arguments), patch.object(gate['main'].__globals__['subprocess'], 'run') as run:
                with self.assertRaises(SystemExit):
                    gate['main'](['--only', 'shape', *arguments])
                run.assert_not_called()
        for arguments in (['--shard', '1/3'], ['--only', 'validate', '--control-only']):
            with self.assertRaises(SystemExit):
                gate['main'](arguments)

    def test_manifest_identity_does_not_depend_on_checkout_path(self):
        identity = gate['manifest_id']()
        with patch.dict(gate['manifest_id'].__globals__, {'ROOT': Path('/another/checkout')}):
            self.assertEqual(identity, gate['manifest_id']())


class StableShapeResultTest(unittest.TestCase):
    def setUp(self):
        self.result = runpy.run_path(str(ROOT / 'bin/ci-swamp-shape-result'))['verify']
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.reports = Path(self.directory.name)
        self.identity = gate['manifest_id']()
        full = gate['plan'](BASE, Path('REPORTS'), Path('CACHE'), Path('COLLECTIONS'))
        for i in range(1, 4):
            directory = self.reports / str(i)
            directory.mkdir()
            report = {'sha': 'a' * 40, 'base': BASE, 'manifest_id': self.identity, 'shard': f'{i}/3',
                      'units': [{'unit': s.unit, 'argv': s.argv, 'outcome': 'passed'} for s in gate['shard_units'](full, i)]}
            (directory / 'shard.json').write_text(json.dumps(report))

    def verify(self, selection='true', plan='success', control='success', shards='success'):
        return self.result(selection, plan, control, shards, self.reports, BASE, 'a' * 40, self.identity)

    def test_selected_children_must_all_succeed_and_unselected_is_explicit(self):
        self.assertIn('passed', self.verify())
        for state in ('failure', 'cancelled', 'skipped', 'timed_out'):
            for job in ('plan', 'control', 'shards'):
                with self.subTest(state=state, job=job), self.assertRaises(ValueError):
                    self.verify(**{job: state})
        self.assertIn('unselected', self.verify(selection='false', control='skipped', shards='skipped'))
        for selection in ('', 'unknown'):
            with self.assertRaises(ValueError):
                self.verify(selection=selection)
        with self.assertRaises(ValueError):
            self.verify(selection='false')

    def test_missing_mismatched_failed_duplicate_and_omitted_reports_fail(self):
        file = self.reports / '1/shard.json'
        original = json.loads(file.read_text())
        for field, value in [('sha', 'b' * 40), ('base', 'b' * 40), ('manifest_id', 'wrong'), ('shard', '2/3')]:
            report = {**original, field: value}
            file.write_text(json.dumps(report))
            with self.subTest(field=field), self.assertRaises(ValueError):
                self.verify()
        for change in ('failed', 'duplicate', 'omitted', 'permissions'):
            report = json.loads(json.dumps(original))
            if change == 'failed':
                report['units'][0]['outcome'] = 'FAILED'
            elif change == 'duplicate':
                report['units'].append(report['units'][0])
            elif change == 'omitted':
                report['units'].pop()
            else:
                report['units'][0]['argv'].append('--allow-all')
            file.write_text(json.dumps(report))
            with self.subTest(change=change), self.assertRaises(ValueError):
                self.verify()
        file.unlink()
        with self.assertRaises(ValueError):
            self.verify()

    def test_final_check_keeps_its_name_and_does_not_wait_for_validation_or_proof(self):
        block = WORKFLOW.split('  shape:\n', 1)[1].split('  validate:\n', 1)[0]
        self.assertIn('name: Swamp shape gate', block)
        self.assertIn('needs: [shape-plan, shape-control, shape-shards]', block)
        self.assertIn('if: always()', block)
        self.assertIn('bin/ci-swamp-shape-result', block)
        caller = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn('    name: Swamp\n', caller)


if __name__ == '__main__':
    unittest.main()
