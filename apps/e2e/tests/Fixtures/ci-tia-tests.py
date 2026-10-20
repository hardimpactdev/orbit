"""Exercise bin/ci-tia against disposable Git repositories and the real Pest graph classes."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import threading
from urllib.parse import parse_qs, urlparse
import unittest
import xml.etree.ElementTree as ET

REPOSITORY = Path(sys.argv.pop(1)).resolve()
HELPER = REPOSITORY / 'bin/ci-tia'
PROJECT = 'apps/gateway'
# Two identifiers recorded from the real privileged listing, including PHPUnit's dataset suffix.
PRIVILEGED_LISTING = [
    r'P\Tests\Feature\Infrastructure\Tasks\RemoteTaskCheckRunnerTest::__pest_evaluable_it_keeps_a_lost_managed_supervisor_fenced_until_its_surviving_descendant_terminates',
    r'P\Tests\Feature\Infrastructure\Tasks\GitHubTaskBaseBranchFetcherTest::__pest_evaluable_it_retains_uncertainty_for_a_checkout_move_but_not_a_timed_out_merge_base_read"(true)"',
]
PEST = '''<?php

pest()->tia()->watch([
    'resources/tasks/check' => 'tests/Feature/Tasks/CheckTest.php',
    'resources/scripts/*.py' => 'tests/Feature/ScriptTest.php',
]);
'''
FINGERPRINT = r'''
require 'vendor/autoload.php';
echo json_encode(Pest\Plugins\Tia\Fingerprint::compute(getcwd()), JSON_THROW_ON_ERROR);
'''


class ActionsApi(BaseHTTPRequestHandler):
    """Answer the workflow-run listing with the newest completed run per event that a test sets."""
    runs = {}
    requests = []

    def do_GET(self):
        url = urlparse(self.path)
        query = parse_qs(url.query)
        type(self).requests.append((url.path, query, self.headers.get('Authorization')))
        runs = type(self).runs.get(query['event'][0])
        if runs == 'error':
            self.send_error(500)
            return
        body = json.dumps({'workflow_runs': runs if isinstance(runs, list) else [runs] if runs else []}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


def git(cwd, *args):
    return subprocess.run(['git', '-C', str(cwd), *args], check=True, capture_output=True, text=True).stdout.strip()


class CiTiaTest(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='orbit-ci-tia-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.project = self.root / PROJECT
        git(self.root, 'init', '-q', '-b', 'main')
        git(self.root, 'config', 'user.name', 'Orbit')
        git(self.root, 'config', 'user.email', 'orbit@example.test')
        self.write('.gitignore', '/apps/gateway/vendor\n/apps/gateway/.orbit-tia/\n/github-output\n/pest.log\n')
        self.write(f'{PROJECT}/composer.lock', '{}')
        self.write(f'{PROJECT}/tests/Pest.php', PEST)
        self.write(f'{PROJECT}/tests/ExampleTest.php', '<?php')
        self.write(f'{PROJECT}/app/Covered.php', '<?php')
        self.write(f'{PROJECT}/app/Uncovered.php', '<?php')
        self.write(f'{PROJECT}/app/Stale.php', '<?php')
        self.write(f'{PROJECT}/resources/tasks/check', 'watched')
        self.write(f'{PROJECT}/resources/compute/script.py', 'print(1)')
        # The helper loads Pest from the project, as CI does after composer install.
        (self.project / 'vendor').symlink_to(REPOSITORY / 'apps/e2e/vendor')
        self.base = self.commit('base')
        self.write_graph(self.base)
        self.output = self.root / 'github-output'

    def write(self, path, content):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content)

    def commit(self, message):
        git(self.root, 'add', '-A')
        git(self.root, 'commit', '-q', '-m', message)
        return git(self.root, 'rev-parse', 'HEAD')

    def fingerprint(self):
        return json.loads(subprocess.run(['php', '-r', FINGERPRINT], cwd=self.project, check=True,
                                         capture_output=True, text=True).stdout)

    def write_graph(self, sha, fingerprint=None, results=None):
        graph = {
            'schema': 1,
            'fingerprint': fingerprint or self.fingerprint(),
            # app/Stale.php keeps a file id although no test links it any more.
            'files': ['tests/ExampleTest.php', 'app/Covered.php', 'app/Stale.php'],
            'edges': {'tests/ExampleTest.php': [0, 1]},
            'baselines': {'main': {'sha': sha, 'tree': [], 'results': results if results is not None else {
                'Tests\\ExampleTest::it_works': {'status': 0, 'message': '', 'time': 0.1, 'assertions': 1,
                                                 'file': 'tests/ExampleTest.php'},
            }}},
        }
        self.write(f'{PROJECT}/.orbit-tia/graph.json', json.dumps(graph))

    def graph_sha(self):
        return json.loads((self.project / '.orbit-tia/graph.json').read_text())['baselines']['main']['sha']

    def run_helper(self, *args, environment=None):
        environment = {**os.environ, 'GITHUB_OUTPUT': str(self.output), **(environment or {})}
        return subprocess.run([str(HELPER), *args], cwd=self.project, env=environment, capture_output=True, text=True)

    def plan(self, event='push', key='prefix-main-sha-1-1', *extra, environment=None):
        self.output.write_text('')
        result = self.run_helper('plan', '--event', event, '--prefix', 'prefix', '--restored-key', key, *extra,
                                 environment=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        outputs = dict(line.split('=', 1) for line in self.output.read_text().splitlines())
        return outputs['mode'], outputs['base'], result.stdout

    def change(self, path, content='changed'):
        self.write(path, content)
        return self.commit(f'change {path}')

    def test_selects_affected_tests_since_the_graph_commit(self):
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        self.change(f'{PROJECT}/app/New.php', '<?php')
        self.change(f'{PROJECT}/src/New.php', '<?php')
        self.change(f'{PROJECT}/tests/OtherTest.php', '<?php')
        self.change(f'{PROJECT}/resources/tasks/check', 'changed')
        self.change(f'{PROJECT}/resources/scripts/probe.py', 'watched')
        self.change('docs/reference/page.md')
        self.change('apps/cli/app/Command.php', '<?php')

        mode, base, stdout = self.plan()

        self.assertEqual(('affected', self.base), (mode, base))
        self.assertIn(f'testing the changes since {self.base}', stdout)

    def test_runs_the_full_suite_for_changes_pest_cannot_see(self):
        for path in (f'{PROJECT}/resources/compute/script.py', f'{PROJECT}/app/Uncovered.php', f'{PROJECT}/app/Stale.php',
                     f'{PROJECT}/app/Commands/NewCommand.php', f'{PROJECT}/app/Console/Commands/Nested/NewCommand.php',
                     f'{PROJECT}/app/Listeners/NewListener.php', f'{PROJECT}/config/new.php',
                     f'{PROJECT}/database/migrations/2026_01_01_000000_create_things.php', f'{PROJECT}/routes/new.php',
                     f'{PROJECT}/resources/scripts/.hidden.py',
                     f'{PROJECT}/tests/Support/Helper.php', f'{PROJECT}/tests/Fixtures/data.json',
                     f'{PROJECT}/resources/scripts/nested/probe.py', 'docs/openapi.json',
                     'packages/php-sdk/fixtures/response.json', 'bin/tool', '.github/workflows/ci.yml',
                     'composer.json'):
            with self.subTest(path=path):
                git(self.root, 'reset', '-q', '--hard', self.base)
                self.change(path)

                mode, base, stdout = self.plan()

                self.assertEqual(('full', ''), (mode, base))
                self.assertIn(f'test-impact analysis cannot see {path}', stdout)

    def test_runs_the_full_suite_when_a_file_pest_cannot_link_is_removed(self):
        for path in (f'{PROJECT}/resources/tasks/check', f'{PROJECT}/app/Uncovered.php', f'{PROJECT}/app/Stale.php'):
            with self.subTest(path=path):
                git(self.root, 'reset', '-q', '--hard', self.base)
                git(self.root, 'rm', '-q', path)
                self.commit(f'remove {path}')

                mode, _, stdout = self.plan()

                self.assertEqual('full', mode)
                self.assertIn(f'test-impact analysis cannot see {path}', stdout)

        git(self.root, 'reset', '-q', '--hard', self.base)
        git(self.root, 'rm', '-q', f'{PROJECT}/app/Covered.php')
        self.commit('remove a covered file')
        self.assertEqual('affected', self.plan()[0])

    def test_runs_the_full_suite_without_a_usable_main_graph(self):
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        cases = {
            'a schedule run refreshes': lambda: self.plan(event='schedule'),
            'a workflow_dispatch run refreshes': lambda: self.plan(event='workflow_dispatch'),
            'no main graph with these test inputs was restored (none)': lambda: self.plan(key=''),
            'no main graph with these test inputs was restored (other-main-': lambda: self.plan(key='other-main-x'),
            'no main graph with these test inputs was restored (prefix-feature-': lambda: self.plan(key='prefix-feature-x'),
        }
        for reason, plan in cases.items():
            with self.subTest(reason=reason):
                self.assertEqual('full', plan()[0])
                self.assertIn(reason, plan()[2])

        self.write_graph(self.base, fingerprint={'structural': {'schema': 0}, 'environmental': {}})
        self.assertIn('other dependencies or another PHP version', self.plan()[2])
        self.write_graph(self.base, results={})
        self.assertIn('the graph has no results', self.plan()[2])
        self.write_graph(self.base, results={'x': {'status': 0, 'file': 'tests/OtherTest.php'}})
        self.assertIn('no result for 1 test files, such as tests/ExampleTest.php', self.plan()[2])
        self.write_graph('f' * 40)
        self.assertIn(f'the graph commit {"f" * 40} is not an ancestor', self.plan()[2])
        (self.project / '.orbit-tia/graph.json').write_text('{')
        self.assertIn('the graph is missing or unreadable', self.plan()[2])

    def test_runs_the_full_suite_until_a_full_run_on_main_passes_again(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), ActionsApi)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        ActionsApi.requests = []
        environment = {'GITHUB_API_URL': f'http://127.0.0.1:{server.server_address[1]}', 'GITHUB_TOKEN': 'token'}
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')

        def plan(nightly, manual):
            ActionsApi.runs = {'schedule': nightly, 'workflow_dispatch': manual}
            return self.plan('push', 'prefix-main-x', '--repository', 'acme/orbit', environment=environment)

        def run(conclusion, created):
            return {'conclusion': conclusion, 'created_at': created, 'html_url': f'https://example.test/{created}'}

        failed = plan(run('failure', '2026-10-08T03:17:00Z'), run('success', '2026-10-07T12:00:00Z'))
        # A cancelled newer run proves nothing about the failure before it.
        ActionsApi.runs = {}
        self.assertEqual('full', plan([run('cancelled', '2026-10-08T09:00:00Z'), run('failure', '2026-10-08T03:17:00Z')],
                                      [run('skipped', '2026-10-08T10:00:00Z')])[0])
        self.assertEqual('affected', plan([run('cancelled', '2026-10-08T09:00:00Z'), run('success', '2026-10-08T03:17:00Z')],
                                          None)[0])
        self.assertEqual('full', failed[0])
        self.assertIn('the newest full run on main did not pass (https://example.test/2026-10-08T03:17:00Z)', failed[2])
        self.assertEqual('full', plan(None, run('timed_out', '2026-10-08T03:17:00Z'))[0])
        self.assertIn('cannot be read', plan('error', None)[2])
        self.assertEqual('affected', plan(run('failure', '2026-10-08T03:17:00Z'), run('success', '2026-10-08T09:00:00Z'))[0])
        self.assertEqual('affected', plan(run('success', '2026-10-08T03:17:00Z'), None)[0])
        self.assertEqual('affected', plan(None, None)[0])
        self.assertEqual(('/repos/acme/orbit/actions/workflows/ci.yml/runs', 'Bearer token'),
                         (ActionsApi.requests[0][0], ActionsApi.requests[0][2]))
        self.assertEqual({'branch': ['main'], 'event': ['schedule'], 'status': ['completed'], 'per_page': ['20']},
                         ActionsApi.requests[0][1])

    def subprocess_tests(self, base):
        self.output.write_text('')
        result = self.run_helper('subprocess', '--base', base)
        self.assertEqual(0, result.returncode, result.stderr)
        return self.output.read_text(), result.stdout

    def test_runs_subprocess_tests_when_a_change_reaches_the_project(self):
        cases = {
            f'{PROJECT}/app/Covered.php': f'{PROJECT}/app/Covered.php changed',
            f'{PROJECT}/tests/OtherTest.php': f'{PROJECT}/tests/OtherTest.php changed',
            # A path outside the project that its tests read anyway.
            'docs/openapi.json': 'docs/openapi.json changed',
            # A path that bin/ci-tia does not list as unrelated.
            'composer.json': 'composer.json changed',
        }
        for path, reason in cases.items():
            with self.subTest(path=path):
                git(self.root, 'reset', '-q', '--hard', self.base)
                self.change('docs/reference/page.md')
                self.change(path)

                output, stdout = self.subprocess_tests(self.base)

                self.assertEqual('run=true\n', output)
                self.assertIn(reason, stdout)

    def test_skips_subprocess_tests_when_no_change_reaches_the_project(self):
        self.change('docs/reference/page.md')
        self.change('apps/cli/app/Command.php', '<?php')

        output, stdout = self.subprocess_tests(self.base)

        self.assertEqual('run=false\n', output)
        self.assertIn(f'no change since {self.base} reaches {PROJECT}', stdout)

    def test_compares_a_pull_request_with_its_merge_base(self):
        git(self.root, 'checkout', '-q', '-b', 'feature')
        self.change('docs/reference/page.md')
        git(self.root, 'checkout', '-q', 'main')
        # The base branch moved on with a project change that the pull request does not contain.
        main = self.change(f'{PROJECT}/app/Covered.php', '<?php // main')
        git(self.root, 'checkout', '-q', 'feature')

        self.assertEqual('run=false\n', self.subprocess_tests(main)[0])

        self.change(f'{PROJECT}/app/Uncovered.php', '<?php // feature')
        self.assertEqual('run=true\n', self.subprocess_tests(main)[0])

    def test_runs_subprocess_tests_when_the_changes_cannot_be_read(self):
        for base, reason in (('', 'the base commit is unknown (none)'), ('main', 'the base commit is unknown (main)'),
                             ('f' * 40, f'the changes since {"f" * 40} cannot be read')):
            with self.subTest(base=base):
                output, stdout = self.subprocess_tests(base)

                self.assertEqual('run=true\n', output)
                self.assertIn(reason, stdout)

    def test_records_the_tested_commit_when_no_test_was_affected(self):
        head = self.change('docs/reference/page.md')
        log = self.root / 'pest.log'
        log.write_text('  INFO  No affected tests found.\n')

        result = self.run_helper('finish', '--mode', 'affected', '--base', self.base, '--log', str(log))

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(head, self.graph_sha())
        self.assertIn(f'The graph describes {head}.', result.stdout)
        # Pest reads the graph it wrote back.
        self.assertEqual(('affected', head), self.plan()[:2])

    def shard_plan(self, classes, status=0, extra='', privileged=(), privileged_tests=''):
        """Plan shards with a stand-in Pest that lists two tests of each class, as `pest --no-tia --all --list-tests` does, and
        the privileged classes and single tests for `pest --no-tia --group=privileged --list-tests`."""
        def write_listing(path, names, tail=''):
            path.write_text('  ─ Experimental TIA mode enabled.\n\n   INFO  Available tests:\n\n' + ''.join(
                f' - P\\{name}::__pest_evaluable_it_works\n - P\\{name}::__pest_evaluable_it_fails_loudly\n'
                for name in names) + tail)
        listing = self.root / 'listing.txt'
        write_listing(listing, classes, extra)
        privileged_listing = self.root / 'privileged.txt'
        write_listing(privileged_listing, privileged, privileged_tests)
        pest = self.root / 'pest'
        pest.write_text(f'#!/bin/sh\ncase "$*" in\n'
                        f'  "--no-tia --all --list-tests --colors=never") cat {listing}; exit {status} ;;\n'
                        f'  "--no-tia --group=privileged --list-tests --colors=never") cat {privileged_listing} ;;\n'
                        f'  *) exit 9 ;;\nesac\n')
        pest.chmod(0o755)
        self.output.write_text('')
        plan = self.root / 'plan.json'
        result = self.run_helper('shards', '--plan', str(plan), '--pest', str(pest))
        outputs = dict(line.split('=', 1) for line in self.output.read_text().splitlines())
        return result, outputs, json.loads(plan.read_text()) if plan.exists() else None

    def privileged_shards(self, shards, whole=None, status=0):
        # Use Pest's real listing shape, including a dataset case. The stand-in only serves discovery, never selection.
        listings = {'whole': PRIVILEGED_LISTING if whole is None else whole, **shards}
        pest = self.root / 'pest'
        pest.write_text('#!/usr/bin/env python3\nimport json, sys\n'
                        f'listings = {listings!r}\n'
                        'assert all(option in sys.argv for option in '
                        '["--no-tia", "--group=privileged", "--fail-on-empty-test-suite", '
                        '"--list-tests", "--colors=never"])\n'
                        'key = next((arg.split("=", 1)[1] for arg in sys.argv if arg.startswith("--shard=")), "whole")\n'
                        'print("   INFO  Available tests:\\n")\n'
                        # Pest's INFO sentence appends a period to its final listed test, not to the test name.
                        'print("\\n".join(" - " + test for test in listings[key]) + ".")\n'
                        f'sys.exit({status})\n')
        pest.chmod(0o755)
        summary = self.root / 'summary.md'
        result = self.run_helper('privileged-shards', '--shards=2', '--pest', str(pest),
                                 environment={'GITHUB_STEP_SUMMARY': str(summary)})
        return result, summary.read_text() if summary.exists() else ''

    def test_checks_the_exact_union_of_stock_privileged_shards(self):
        root, acl = PRIVILEGED_LISTING

        result, summary = self.privileged_shards({'1/2': [root], '2/2': [acl]})

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('2 privileged tests', result.stdout)
        self.assertIn('| 1/2 | 1 |', summary)
        self.assertIn('| 2/2 | 1 |', summary)
        self.assertIn('Union: 2 / 2', summary)

    def test_rejects_incomplete_extra_overlapping_and_empty_privileged_shards(self):
        root, acl = PRIVILEGED_LISTING
        for shards, message in (
            ({'1/2': [root], '2/2': []}, 'empty'),
            ({'1/2': [root], '2/2': [root]}, 'overlap'),
            ({'1/2': [root], '2/2': [acl + ' changed']}, 'missing'),
            ({'1/2': [root], '2/2': [acl, acl + ' extra']}, 'extra'),
        ):
            with self.subTest(message=message):
                result, summary = self.privileged_shards(shards)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(message, result.stderr)
                self.assertEqual('', summary)

    def test_rejects_empty_or_failed_privileged_group_discovery(self):
        for whole, status, message in (([], 0, 'empty'), (None, 3, 'cannot list')):
            with self.subTest(message=message):
                result, summary = self.privileged_shards({}, whole=whole, status=status)
                self.assertNotEqual(0, result.returncode)
                self.assertIn(message, result.stderr)

    def test_privileged_workflow_runs_every_shard_on_disposable_runners(self):
        workflow = json.loads(subprocess.run([
            'php', '-r', 'require $argv[1]; echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[2]));',
            str(REPOSITORY / 'apps/e2e/vendor/autoload.php'), str(REPOSITORY / '.github/workflows/ci.yml'),
        ], check=True, capture_output=True, text=True).stdout)
        job = workflow['jobs']['privileged']
        self.assertEqual('ubuntu-26.04', job['runs-on'])
        self.assertNotIn('if', job)
        self.assertFalse(job['strategy']['fail-fast'])
        self.assertEqual([1, 2, 3, 4], job['strategy']['matrix']['shard'])
        commands = [step['run'] for step in job['steps'] if 'run' in step]
        execution = next(command for command in commands if command.startswith('vendor/bin/pest '))
        for option in ('--no-tia', '--group=privileged', '--fail-on-empty-test-suite',
                       '--shard=${{ matrix.shard }}/${{ strategy.job-total }}'):
            self.assertIn(option, execution)
        check = next(step for step in job['steps'] if 'privileged-shards' in step.get('run', ''))
        self.assertEqual('matrix.shard == 1', check['if'])
        self.assertIn('--shards=${{ strategy.job-total }}', check['run'])
        self.assertEqual('1', next(step for step in job['steps'] if step.get('run') == execution)['env']['ORBIT_TEST_DOCKER'])
        self.assertIn('privileged', workflow['jobs']['required']['needs'])
        self.assertIn('PRIVILEGED_RESULT', json.dumps(workflow['jobs']['required']['steps']))
        self.assertFalse(any('Homebrew' in step.get('uses', '') or 'setup-node' in step.get('uses', '')
                             for step in job['steps']))
        self.assertFalse(any('brew install' in command or 'docker pull' in command for command in commands))

    def test_plans_every_test_without_tia_even_for_a_narrow_diff(self):
        self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        classes = [f'Tests\\Feature\\Area{index}Test' for index in range(20)]

        result, outputs, plan = self.shard_plan(classes)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual('4', str(plan['shards']))
        tests = [f'P\\{name}::__pest_evaluable_it_works' for name in classes]
        for test in tests:
            self.assertEqual(1, sum(bool(re.search(pattern, test)) for pattern in plan['filters']))

    def test_refuses_an_empty_full_suite(self):
        result, outputs, plan = self.shard_plan([])

        self.assertNotEqual(0, result.returncode)
        self.assertIsNone(plan)

    def test_splits_a_large_pull_request_selection_into_balanced_shards(self):
        classes = [f'Tests\\Feature\\Area{index}Test' for index in range(300)]
        # The graph records the slow classes; a class it does not know counts as the median one.
        self.write_graph(self.base, results={
            f'P\\{name}::__pest_evaluable_it_works': {'status': 0, 'message': '', 'time': 30.0 if index < 3 else 1.0,
                                                      'assertions': 1, 'file': 'tests/ExampleTest.php'}
            for index, name in enumerate(classes[:250])
        })

        result, outputs, plan = self.shard_plan(classes)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(('4', '[2, 3, 4]'), (str(plan['shards']), outputs['matrix']))
        self.assertEqual((4, 300, 4), (plan['shards'], plan['classes'], len(plan['filters'])))
        self.assertEqual(self.shard_plan(classes)[2], plan, 'The same selection gives the same plan.')
        # PHPUnit matches each filter against Class::test. Every listed test runs in exactly one shard.
        tests = [f'P\\{name}::__pest_evaluable_it_works' for name in classes] + ['P\\Tests\\Feature\\Area1Test2::x',
                                                                                  'P\\Other\\Tests\\Feature\\Area1Test::x']
        shards = [[test for test in tests if re.search(test_filter, test, re.I)] for test_filter in plan['filters']]
        self.assertEqual(sorted(tests[:300]), sorted(sum(shards, [])))
        # Longest classes spread across separate workers rather than all landing in one shard.
        slow = [next(index for index, shard in enumerate(shards) if f'P\\{name}::__pest_evaluable_it_works' in shard)
                for name in classes[:3]]
        self.assertEqual(3, len(set(slow)))
        # Each class goes to the shard that would end first, by its process seconds over its Pest processes: 6 for the
        # first shard, 4 for the others. The first shard can therefore take more classes.
        self.assertGreater(len(shards[0]), max(len(shard) for shard in shards[1:]))
        self.assertIn('4 shards: 300 full-suite test classes.', result.stdout)

    def test_leaves_the_privileged_group_out_of_the_shards(self):
        # Pest turns TIA off for a group option, so the plan lists the privileged group on its own and subtracts it.
        classes = [f'Tests\\Feature\\Area{index}Test' for index in range(203)]
        privileged = classes[:3] + ['Tests\\Feature\\UnselectedPrivilegedTest']

        result, outputs, plan = self.shard_plan(classes, privileged=privileged)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(200, plan['classes'])
        self.assertEqual('4', str(plan['shards']))
        self.assertIn('4 shards: 200 full-suite test classes.', result.stdout)

    def test_keeps_the_other_tests_of_a_class_with_some_privileged_tests(self):
        # A class with only some privileged tests stays in a shard, which leaves out its privileged tests itself.
        classes = [f'Tests\\Feature\\Area{index}Test' for index in range(300)]

        result, outputs, plan = self.shard_plan(
            classes, privileged=classes[1:2],
            privileged_tests=f' - P\\{classes[0]}::__pest_evaluable_it_fails_loudly\n')

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(('4', 299), (str(plan['shards']), plan['classes']))
        shards = [test_filter for test_filter in plan['filters']
                  if re.search(test_filter, f'P\\{classes[0]}::__pest_evaluable_it_works', re.I)]
        self.assertEqual(1, len(shards))
        self.assertFalse(any(re.search(test_filter, f'P\\{classes[1]}::__pest_evaluable_it_works', re.I)
                             for test_filter in plan['filters']))

    def test_fails_when_a_selected_test_runs_in_no_shard(self):
        loader = importlib.machinery.SourceFileLoader('ci_tia', str(HELPER))
        helper = importlib.util.module_from_spec(importlib.util.spec_from_loader('ci_tia', loader))
        loader.exec_module(helper)
        selected = {'P\\Tests\\AreaTest::it_works', 'P\\Tests\\OtherTest::it_works', 'P\\Tests\\RootTest::it_works'}

        helper.require_coverage(selected, {'P\\Tests\\RootTest::it_works'}, [r'^(?:P\\)?(?:Tests\\AreaTest|Tests\\OtherTest)::'])
        with self.assertRaises(SystemExit) as failure:
            helper.require_coverage(selected, set(), [r'^(?:P\\)?(?:Tests\\AreaTest|Tests\\OtherTest)::'])
        self.assertEqual('1 selected tests run in no shard, such as: P\\Tests\\RootTest::it_works', str(failure.exception))

    def test_never_plans_a_shard_without_classes(self):
        # Even very fast classes fill all four shards.
        classes = [f'Tests\\Unit\\Fast{index}Test' for index in range(201)]
        self.write_graph(self.base, results={
            f'P\\{name}::__pest_evaluable_it_works': {'status': 0, 'message': '', 'time': 0.01, 'assertions': 1,
                                                      'file': 'tests/ExampleTest.php'} for name in classes})

        result, outputs, plan = self.shard_plan(classes)

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(('4', '[2, 3, 4]'), (str(plan['shards']), outputs['matrix']))
        tests = [f'P\\{name}::__pest_evaluable_it_works' for name in classes]
        shards = [[test for test in tests if re.search(test_filter, test, re.I)] for test_filter in plan['filters']]
        self.assertTrue(all(shards))
        self.assertEqual(sorted(tests), sorted(sum(shards, [])))

    def test_fails_when_a_listed_test_cannot_be_read(self):
        result, outputs, plan = self.shard_plan(['Tests\\Feature\\AreaTest'], extra=' - P\\Tests\\Ünit\\BadTest::x\n')

        self.assertEqual(1, result.returncode)
        self.assertIn('Cannot read the class of 1 listed tests, such as:  - P\\Tests\\Ünit\\BadTest::x', result.stderr)
        self.assertEqual(({}, None), (outputs, plan))

    def test_fails_when_pest_cannot_list_the_selection(self):
        result, outputs, plan = self.shard_plan(['Tests\\Feature\\AreaTest'], status=2)

        self.assertEqual(1, result.returncode)
        self.assertIn('Pest cannot list the full suite:', result.stderr)
        self.assertEqual(({}, None), (outputs, plan))

    def test_refuses_a_graph_that_does_not_describe_the_tested_commit(self):
        head = self.change(f'{PROJECT}/app/Covered.php', '<?php // first')
        log = self.root / 'pest.log'
        log.write_text('Tests: 1 passed\n')
        refusals = {
            'a run that tested something': ('affected', self.base, str(log)),
            'a full run': ('full', '', os.devnull),
            'another base': ('affected', 'e' * 40, str(log)),
        }
        for name, (mode, base, log_path) in refusals.items():
            with self.subTest(name=name):
                result = self.run_helper('finish', '--mode', mode, '--base', base, '--log', log_path)

                self.assertEqual(1, result.returncode)
                self.assertIn(f'The graph records {self.base}, not the tested commit {head}.', result.stderr)
                self.assertEqual(self.base, self.graph_sha())

        self.write_graph(head, results={'x': {'status': 0, 'file': 'tests/OtherTest.php'}})
        result = self.run_helper('finish', '--mode', 'full')
        self.assertEqual(1, result.returncode)
        self.assertIn('The graph is not complete: the graph has no result for 1 test files', result.stderr)

        self.write_graph(head)
        result = self.run_helper('finish', '--mode', 'full')
        self.assertEqual(0, result.returncode, result.stderr)


class GatewaySelectionTest(unittest.TestCase):
    """Run CI's commands with Gateway's real group configuration and installed Pest, never host sudo."""

    def setUp(self):
        temporary = tempfile.TemporaryDirectory(prefix='orbit-gateway-selection-')
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.project = self.root / PROJECT
        for directory in ('tests/Unit', 'tests/Feature', 'app', 'extensions'):
            (self.project / directory).mkdir(parents=True)
        # Pest resolves the project root from the real autoloader path, so a vendor symlink would run another suite.
        shutil.copytree(REPOSITORY / 'packages/php-sdk/vendor', self.project / 'vendor',
                        ignore=shutil.ignore_patterns('cache', 'pint.cache'))
        (self.root / '.gitignore').write_text('**/vendor\n**/.orbit-tia/\n**/.executed\n')
        # Keep the real test suites, source roots, and group selection. Only the Laravel bootstrap is replaced.
        configuration = ET.parse(REPOSITORY / 'apps/gateway/phpunit.xml')
        configuration.getroot().set('bootstrap', 'vendor/autoload.php')
        configuration.write(self.project / 'phpunit.xml')
        for manifest in ('composer.json', 'composer.lock'):
            (self.project / manifest).write_bytes((REPOSITORY / 'packages/php-sdk' / manifest).read_bytes())
        (self.project / 'tests/Pest.php').write_text(
            "<?php\npest()->tia()->directory(dirname(__DIR__).'/.orbit-tia');\n"
            "require_once dirname(__DIR__).'/app/Value.php';\n")
        self.value = self.project / 'app/Value.php'
        self.value.write_text('<?php\nfunction gatewaySelectionValue(): int { return 1; }\n')
        (self.project / 'tests/Unit/MixedTest.php').write_text('''<?php
it('runs an ordinary case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "ordinary\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
});
it('runs an ordinary subprocess case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "subprocess\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
})->group('subprocess');
it('runs a Unix user case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "privileged\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
})->group('privileged');
it('runs a privileged subprocess case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "privileged-subprocess\\n", FILE_APPEND);
    expect(gatewaySelectionValue())->toBe(1);
})->group('privileged', 'subprocess');
''')
        (self.project / 'tests/Unit/UnrelatedTest.php').write_text('''<?php
it('runs an unrelated ordinary case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "unrelated\\n", FILE_APPEND);
    expect(true)->toBeTrue();
});
''')
        (self.project / 'tests/Unit/PrivilegedOnlyTest.php').write_text('''<?php
it('runs a privileged only case', function (): void {
    file_put_contents(dirname(__DIR__, 2).'/.executed', "privileged-only\\n", FILE_APPEND);
    expect(true)->toBeTrue();
})->group('privileged');
''')
        for args in (('init', '-q', '-b', 'main'), ('config', 'user.name', 'Orbit'),
                     ('config', 'user.email', 'orbit@example.test'), ('remote', 'add', 'origin', str(self.root))):
            git(self.root, *args)
        self.base = self.commit('base')
        self.workflow = json.loads(subprocess.run([
            'php', '-r', 'require $argv[1]."/apps/e2e/vendor/autoload.php"; '
            'echo json_encode(Symfony\\Component\\Yaml\\Yaml::parseFile($argv[1]."/.github/workflows/ci.yml"));',
            str(REPOSITORY),
        ], check=True, capture_output=True, text=True).stdout)
        # These are independent Pest invocations, not workers of the outer E2E run.
        environment = {key: value for key, value in os.environ.items()
                       if not key.startswith('PEST_') and key not in
                       ('PARATEST', 'TEST_TOKEN', 'UNIQUE_TEST_TOKEN', 'LARAVEL_PARALLEL_TESTING')}
        self.environment = {**environment, 'RUNNER_TEMP': str(self.root), 'ORBIT_TIA_DIRECTORY': '',
                            'CI': '', 'GITHUB_ACTIONS': '', 'GITHUB_OUTPUT': str(self.root / 'output')}

    def commit(self, message):
        git(self.root, 'add', '-A')
        git(self.root, 'commit', '-q', '-m', message)
        head = git(self.root, 'rev-parse', 'HEAD')
        git(self.root, 'update-ref', 'refs/remotes/origin/main', head)
        git(self.root, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main')
        return head

    def command(self, step_name):
        step = next(step for step in self.workflow['jobs']['project']['steps'] if step.get('name') == step_name)
        # Resolve only the two expressions in these commands, as a Gateway job does, with two local workers.
        return re.sub(r'\$\{\{ runner.environment .*?\}\}', '2', step['run']).replace(
            "${{ matrix.project.directory == 'packages/php-sdk' && ' --do-not-fail-on-empty-test-suite' || '' }}", '')

    def run_command(self, command):
        result = subprocess.run(['bash', '-eo', 'pipefail', '-c', command], cwd=self.project,
                                env=self.environment, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        return result.stdout + result.stderr

    def executed(self):
        marker = self.project / '.executed'
        cases = marker.read_text().splitlines() if marker.exists() else []
        marker.unlink(missing_ok=True)
        return sorted(cases)

    def finish(self, mode, head):
        self.run_command(f'{HELPER} finish --mode {mode} --base {self.base} '
                         f'--log "{self.root}/orbit-tia-pest.log"')
        graph = json.loads((self.project / '.orbit-tia/graph.json').read_text())
        self.assertEqual(head, graph['baselines']['main']['sha'])
        self.assertEqual(3, len(graph['baselines']['main']['results']))
        self.assertEqual({'tests/Unit/MixedTest.php', 'tests/Unit/UnrelatedTest.php'}, set(graph['edges']))

    def test_full_ci_run_excludes_privileged_and_records_a_complete_graph(self):
        self.run_command(self.command('Run full test suite and refresh Pest TIA graph'))

        self.assertEqual(['ordinary', 'subprocess', 'unrelated'], self.executed())
        self.finish('full', self.base)

    def test_affected_ci_run_excludes_privileged_without_disabling_tia(self):
        self.run_command(self.command('Run full test suite and refresh Pest TIA graph'))
        self.executed()
        self.value.write_text(self.value.read_text().replace('return 1;', 'return 1 + 0;'))
        head = self.commit('change the dependency of the mixed class')
        self.run_command(f'{HELPER} plan --event push --prefix prefix --restored-key prefix-main-base')
        self.assertIn(f'mode=affected\nbase={self.base}\n', (self.root / 'output').read_text())

        self.run_command(self.command('Run tests affected since the main graph'))

        self.assertEqual(['ordinary', 'subprocess'], self.executed())
        self.finish('affected', head)

    def test_subprocess_ci_run_excludes_cases_in_both_groups(self):
        self.run_command(self.command('Run subprocess tests'))

        self.assertEqual(['subprocess'], self.executed())

    def test_explicit_privileged_selection_and_full_discovery_keep_every_privileged_case(self):
        job = self.workflow['jobs']['privileged']
        command = next(step['run'] for step in job['steps'] if step.get('run', '').startswith('vendor/bin/pest '))
        command = command.replace('${{ matrix.shard }}/${{ strategy.job-total }}', '1/1')
        self.run_command(command)

        self.assertEqual(['privileged', 'privileged-only', 'privileged-subprocess'], self.executed())
        loader = importlib.machinery.SourceFileLoader('ci_tia_selection', str(HELPER))
        module = importlib.util.module_from_spec(importlib.util.spec_from_loader('ci_tia_selection', loader))
        loader.exec_module(module)
        # full_tests invokes Pest in the current project, just as the PR plan does.
        previous = Path.cwd()
        try:
            os.chdir(self.project)
            whole, privileged = module.full_tests('vendor/bin/pest')
        finally:
            os.chdir(previous)
        self.assertEqual(6, len(whole))
        self.assertEqual(3, len(privileged))
        self.assertEqual(3, len(whole - privileged))


if __name__ == '__main__':
    unittest.main(verbosity=2)
