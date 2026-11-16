"""A task copy's publish states its base and refuses only a workflow tree that differs from the default head or a real conflict: the
workflow's own "Read the base" script on real Git repositories, its typed reader "Check the base" and the pull request plan, with the
pinned CEL engine for the wiring."""
import importlib.util
import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
import uuid
from pathlib import Path

import yaml

# The runner starts this file as `python3 -I`, which keeps its folder off the import path: load the harness by its path.
_spec = importlib.util.spec_from_file_location('task_instance_workspace_harness', Path(__file__).parent / '_support/task_instance_workspace.py')
base = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(base)

ROOT, BEHAVIORS = base.ROOT, base.BEHAVIORS
# The harness's CEL runner, which also reads one `env.NAME` value of a step's inputs.
CEL = base.CEL.replace('const raw = field === "guard" ? step.guard :',
                       'const raw = field.startsWith("env.") ? step.task.inputs.env[field.slice(4)] : field === "guard" ? step.guard :')
assert CEL != base.CEL
PUBLISH = ROOT / 'workflows/workflow-operation-task-publish.yaml'
READ, CHECK, PUSH, PLAN = 'Read the base', 'Check the base', 'Push the branch', 'Plan the PR'
WORKFLOW = '.github/workflows/x.yml'
GIT_ENV = {'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_AUTHOR_NAME': 'base', 'GIT_AUTHOR_EMAIL': 'base@example.test',
           'GIT_COMMITTER_NAME': 'base', 'GIT_COMMITTER_EMAIL': 'base@example.test'}


class TaskCopyPublishBaseTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='copy-publish-base-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'cel.ts').write_text(CEL)
        (self.root / 'runner.ts').write_text(base.RUNNER)
        self.copy = {'group_id': 1, 'instance_id': 2, 'node_id': 9, 'check': 4, 'checkout_path': '/srv/workspace', 'commit': 'b' * 40,
                     'tree': 'c' * 40, 'repository': 'example/trial', 'base': 'main', 'branch': 'task-1',
                     'remote': 'https://github.com/example/trial.git', 'pull_request': {'title': 'Export orders', 'body': 'Adds the export.'},
                     'workspace': {'runtime': {'project': 'orbit-instances', 'name': 'instance-9'}, 'branch': 'task-1',
                                   'snapshot_id': str(uuid.uuid4()), 'start': 'a' * 40, 'environment_path': '.env'}}
        self.vm = {key: value for key, value in self.copy.items() if key != 'workspace'}
        # origin: the seed on main. The copy's branch starts there.
        self.origin = self.root / 'origin.git'
        self.git('init', '--quiet', '--bare', '--initial-branch=main', str(self.origin), cwd=self.root)
        self.main = self.root / 'main'
        self.git('clone', '--quiet', str(self.origin), str(self.main), cwd=self.root)
        self.commit(self.main, 'seed', {'a.txt': 'one\ntwo\nthree\nfour\nfive\n', 'b.txt': 'b\n', WORKFLOW: 'on: push\n'})
        self.git('push', '--quiet', 'origin', 'HEAD:main', cwd=self.main)
        self.work = self.root / 'work'
        self.git('clone', '--quiet', str(self.origin), str(self.work), cwd=self.root)
        self.git('checkout', '--quiet', '-b', 'task-1', cwd=self.work)
        self.scratch = Path(tempfile.mkdtemp(prefix='orbit-task-publish-', dir='/tmp'))
        self.addCleanup(shutil.rmtree, self.scratch, True)

    def git(self, *args, cwd=None):
        return subprocess.run(['git', *args], cwd=cwd, check=True, capture_output=True, env={**os.environ, **GIT_ENV}).stdout.decode().strip()

    def commit(self, work, message, files):
        for name, content in files.items():
            (work / name).parent.mkdir(parents=True, exist_ok=True)
            (work / name).write_text(content)
        self.git('add', '--all', cwd=work)
        self.git('commit', '--quiet', '-m', message, cwd=work)
        return self.git('rev-parse', 'HEAD', cwd=work)

    def advance_main(self, *changes):
        for index, files in enumerate(changes):
            self.commit(self.main, f'main {index}', files)
        self.git('push', '--quiet', 'origin', 'HEAD:main', cwd=self.main)
        return self.git('rev-parse', 'HEAD', cwd=self.main)

    def cel(self, step, field, intent, steps=None):
        file = self.root / 'context.json'
        file.write_text(json.dumps({'inputs': {'intent': intent}, 'steps': steps or {}, 'run': {'id': str(uuid.uuid4())}}))
        done = subprocess.run(['deno', 'run', '-A', str(self.root / 'cel.ts'), str(PUBLISH), step, field, str(file)],
                              capture_output=True, text=True, check=False, timeout=60)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads(done.stdout)['value']

    def read_base(self):
        """The worker's scratch repository as the publish makes it, then the step's own script against origin."""
        repository = self.scratch / 'repository.git'
        shutil.rmtree(repository, ignore_errors=True)
        self.git('init', '--quiet', '--bare', str(repository), cwd=self.root)
        self.git('fetch', '--quiet', str(self.work), '+refs/heads/task-1:refs/heads/candidate', cwd=repository)
        # The step's fixed merge identity, as written; the intent values through the step's own expressions.
        identity = {name: value for name, value in self.step(READ)['task']['inputs']['env'].items() if name.startswith(('GIT_AUTHOR_', 'GIT_COMMITTER_'))}
        self.assertEqual(len(identity), 4)
        done = subprocess.run(['/bin/sh', '-c', self.cel(READ, 'run', self.copy)], capture_output=True, text=True, check=False, timeout=120,
                              env={'PATH': os.environ['PATH'], 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1', **identity,
                                   'ORBIT_REPOSITORY': str(repository), 'ORBIT_DIRECTORY': str(self.scratch), 'ORBIT_REMOTE': str(self.origin),
                                   **{name: self.cel(READ, 'env.' + name, self.copy) for name in ('ORBIT_BASE', 'ORBIT_BRANCH')}})
        return done.returncode

    @staticmethod
    def step(name):
        return next(item for job in yaml.safe_load(PUBLISH.read_text())['jobs'] for item in job['steps'] if item['name'] == name)

    def behavior(self, method, args):
        file = self.root / ('args-' + method + '.json')
        file.write_text(json.dumps(args))
        return subprocess.run(['deno', 'run', '-A', str(self.root / 'runner.ts'), (BEHAVIORS / 'task-repository/publication.ts').as_uri(),
                               method, str(file)], capture_output=True, text=True, check=False, timeout=60)

    def check_base(self, exit_code):
        done = self.behavior('base', {'directory': str(self.scratch), 'exit_code': exit_code})
        if done.returncode != 0:
            return None, done.stderr
        return json.loads(json.loads(done.stdout)['stdout']), ''

    def planned_body(self, statement, body='Adds the export.', proposed=None):
        """The production plan of the pull request write, with the statement as "Plan the PR" passes it. With `proposed`, the copy
        held a proposed body: "Add the waiver lines" wrote the text the .env check read, and the plan publishes that text."""
        (self.scratch / 'pulls.json').write_text('[]')
        for name in ('proposed-body', 'pull-title', 'pull-body'):
            (self.scratch / name).unlink(missing_ok=True)
        steps = {CHECK: {'status': 'succeeded', 'outputs': {'stdout': statement}}}
        self.assertEqual(self.cel(PLAN, 'base', self.copy, steps), statement)
        intent = {**self.vm, 'pull_request': {'title': 'Export orders', 'body': body}}
        if proposed is not None:
            (self.scratch / 'proposed-body').write_text(proposed)
            done = self.behavior('waivers', {'input': intent, 'directory': str(self.scratch)})
            self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        done = self.behavior('pull', {'directory': str(self.scratch), 'exit_code': 0, 'input': intent, 'base': statement})
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads((self.scratch / 'pull-body.json').read_text())['body']

    def stated(self, behind, overlap):
        code = self.read_base()
        self.assertEqual(code, 0)
        facts, _ = self.check_base(code)
        self.assertEqual(facts, {'base_commit': self.git('merge-base', 'task-1', 'origin/main', cwd=self.fetched()),
                                 'default_commit': self.git('rev-parse', 'refs/heads/main', cwd=self.origin),
                                 'behind_by': behind, 'overlapping_paths': overlap, 'overlapping_more': 0})
        # Nothing reached origin: its main is where the test put it, and it has no task branch.
        self.assertEqual(self.git('for-each-ref', '--format=%(refname)', cwd=self.origin), 'refs/heads/main')
        return facts

    def fetched(self):
        self.git('fetch', '--quiet', 'origin', cwd=self.work)
        return self.work

    def test_a_branch_level_with_main_is_zero_behind_and_overlaps_nothing(self):
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntwo\nthree\nfour\nfive\nsix\n'})
        facts = self.stated(0, [])
        body = self.planned_body(json.dumps(facts))
        self.assertTrue(body.startswith('Adds the export.\n\n<!-- orbit:base -->\n## Base\n'), body)
        self.assertIn('- Behind by: 0 commits\n- Overlapping paths: none\n', body)

    def test_a_branch_behind_without_a_shared_path_is_stated_and_publishes(self):
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntwo\nthree\nfour\nfive\nsix\n'})
        self.advance_main({'b.txt': 'b1\n'}, {'b.txt': 'b2\n'})
        self.stated(2, [])
        self.assertFalse(self.cel(PUSH, 'guard', self.copy, {CHECK: {'status': 'succeeded'}}))

    def test_a_branch_behind_with_a_shared_path_and_no_conflict_is_stated_and_publishes_once(self):
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntwo\nthree\nfour\nFIVE\n'})
        self.advance_main({'a.txt': 'ONE\ntwo\nthree\nfour\nfive\n'})
        facts = self.stated(1, ['a.txt'])
        self.assertFalse(self.cel(PUSH, 'guard', self.copy, {CHECK: {'status': 'succeeded'}}))
        body = self.planned_body(json.dumps(facts))
        self.assertIn('- Behind by: 1 commit\n- Overlapping paths:\n  - `a.txt`\n', body)
        # A re-publish whose caller body carries the earlier section states it once.
        self.assertEqual(self.planned_body(json.dumps(facts), body), body)
        self.assertEqual(body.count('## Base'), 1)
        # With the copy's proposed body, the PR gets the caller's text, then its waiver lines, then one Base section, also when
        # the caller's body carries the earlier section and the waiver line lands after it.
        waiver = 'Docs-unaffected: docs/reference/tasks.md: no change.'
        for caller in ('Adds the export.', body):
            waived = self.planned_body(json.dumps(facts), caller, proposed='Notes.\n' + waiver + '\n')
            self.assertEqual(waived.count('## Base'), 1, waived)
            self.assertTrue(waived.startswith('Adds the export.\n\n' + waiver + '\n\n<!-- orbit:base -->\n## Base\n'), waived)

    def test_a_real_conflict_is_refused_with_its_paths_before_the_push(self):
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntask\nthree\nfour\nfive\n', 'b.txt': 'task\n'})
        self.advance_main({'a.txt': 'one\nmain\nthree\nfour\nfive\n', 'b.txt': 'main\n'})
        code = self.read_base()
        self.assertEqual(code, 3)
        facts, error = self.check_base(code)
        self.assertIsNone(facts)
        self.assertIn('tasks.publish_conflict: "a.txt", "b.txt"', error)
        # The refused check fails its step, so the push guard skips the push; origin holds no task branch.
        self.assertTrue(self.cel(PUSH, 'guard', self.copy, {CHECK: {'status': 'failed'}}))
        self.assertEqual(self.git('for-each-ref', '--format=%(refname)', cwd=self.origin), 'refs/heads/main')

    def refused(self, error):
        """The base check refused before the push, and origin holds no task branch."""
        self.assertTrue(self.cel(PUSH, 'guard', self.copy, {CHECK: {'status': 'failed'}}))
        self.assertEqual(self.git('for-each-ref', '--format=%(refname)', cwd=self.origin), 'refs/heads/main')
        return error

    def no_workflow_change(self):
        """The base read lists no workflow path, and the check states the base against the real default head."""
        self.assertEqual(self.read_base(), 0)
        facts, error = self.check_base(0)
        self.assertIsNotNone(facts, error)
        self.assertEqual(facts['default_commit'], self.git('rev-parse', 'refs/heads/main', cwd=self.origin))
        self.assertEqual((self.scratch / 'base/workflows').read_bytes(), b'')
        return facts

    def test_a_branch_behind_a_default_commit_that_changed_a_workflow_is_refused_with_that_path(self):
        """Operation 24460: no commit of the branch changes a workflow, but main changed one since the branch's base. GitHub compares
        the trees, not the commits, so the push would be refused: the base read refuses it first."""
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntwo\nthree\nfour\nfive\nsix\n'})
        self.advance_main({WORKFLOW: 'on: [push, pull_request]\n'})
        self.assertEqual(self.read_base(), 0)
        facts, error = self.check_base(0)
        self.assertIsNone(facts)
        self.assertIn('tasks.publish_workflow_change: ".github/workflows/x.yml"\n', self.refused(error))

    def test_the_same_branch_after_it_merged_the_default_head_publishes(self):
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntwo\nthree\nfour\nfive\nsix\n'})
        self.advance_main({WORKFLOW: 'on: [push, pull_request]\n'})
        self.git('merge', '--quiet', '--no-edit', 'origin/main', cwd=self.fetched())
        facts = self.no_workflow_change()
        self.assertEqual((facts['behind_by'], facts['overlapping_paths']), (0, []))

    def test_a_default_commit_that_changed_a_workflow_carried_in_by_hand_is_no_change_of_the_copy(self):
        """Operation 24405: main changed a workflow after the last release, and the copy got that main by hand, not through its
        remote refs. The default head holds the commit, so the copy publishes."""
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntwo\nthree\nfour\nfive\nsix\n'})
        released = self.git('rev-parse', 'origin/main', cwd=self.work)
        self.advance_main({WORKFLOW: 'on: [push, pull_request]\n'})
        # Carried in by hand: the copy's origin/main stays the released main.
        self.git('fetch', '--quiet', str(self.main), '+HEAD:refs/heads/hand', cwd=self.work)
        self.git('merge', '--quiet', '--no-edit', 'hand', cwd=self.work)
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main', cwd=self.work), released)
        facts = self.no_workflow_change()
        self.assertEqual((facts['behind_by'], facts['overlapping_paths']), (0, []))

    def test_a_workflow_change_of_the_copy_that_a_later_commit_undoes_is_no_change(self):
        self.commit(self.work, 'agent work', {WORKFLOW: 'on: [push]\n'})
        self.commit(self.work, 'undo', {WORKFLOW: 'on: push\n'})
        self.no_workflow_change()

    def test_a_copy_commit_of_its_own_that_changes_a_workflow_is_refused_with_its_path(self):
        self.commit(self.work, 'agent work', {WORKFLOW: 'on: [push]\n', '.github/workflow-notes.md': 'x\n',
                                              '.github/workflows/y.yml': 'on: push\n'})
        self.commit(self.work, 'more agent work', {'a.txt': 'one\ntwo\nthree\nfour\nfive\nsix\n'})
        self.advance_main({'b.txt': 'b1\n'})
        self.assertEqual(self.read_base(), 0)
        facts, error = self.check_base(0)
        self.assertIsNone(facts)
        self.assertIn('tasks.publish_workflow_change: ".github/workflows/x.yml", ".github/workflows/y.yml"\n', self.refused(error))

    def test_a_merge_that_keeps_its_own_workflow_version_is_refused(self):
        self.commit(self.work, 'agent work', {'b.txt': 'task\n'})
        self.advance_main({WORKFLOW: 'on: [push, pull_request]\n'})
        self.git('fetch', '--quiet', 'origin', cwd=self.work)
        self.git('merge', '--quiet', '--no-commit', 'origin/main', cwd=self.work)
        self.commit(self.work, 'merge main', {WORKFLOW: 'on: [workflow_dispatch]\n'})
        self.assertEqual(self.git('rev-list', '--count', '--merges', 'HEAD~1..HEAD', cwd=self.work), '1')
        self.assertEqual(self.read_base(), 0)
        facts, error = self.check_base(0)
        self.assertIsNone(facts)
        self.assertIn('tasks.publish_workflow_change: ".github/workflows/x.yml"\n', self.refused(error))

    def test_a_workflow_change_refuses_before_a_conflict(self):
        self.commit(self.work, 'agent work', {'a.txt': 'one\ntask\nthree\nfour\nfive\n', WORKFLOW: 'on: [push]\n'})
        self.advance_main({'a.txt': 'one\nmain\nthree\nfour\nfive\n'})
        self.assertEqual(self.read_base(), 3)
        facts, error = self.check_base(3)
        self.assertIsNone(facts)
        self.assertIn('tasks.publish_workflow_change: ".github/workflows/x.yml"\n', self.refused(error))
        self.assertNotIn('tasks.publish_conflict', error)

    def test_a_read_that_fails_never_states_a_base_or_lets_the_push_run(self):
        self.copy['base'] = 'missing'
        code = self.read_base()
        self.assertNotIn(code, (0, 3))
        facts, error = self.check_base(code)
        self.assertIsNone(facts)
        self.assertIn('tasks.publish_failed', error)
        self.assertTrue(self.cel(PUSH, 'guard', self.copy, {CHECK: {'status': 'skipped'}}))

    def test_only_a_copy_reads_its_base_with_the_push_authorization_and_known_intent_keys(self):
        for step in (READ, CHECK):
            self.assertFalse(self.cel(step, 'guard', self.copy), step)
            self.assertTrue(self.cel(step, 'guard', self.vm), step)
        self.assertFalse(self.cel(PUSH, 'guard', self.vm))
        self.assertEqual(self.cel(PLAN, 'base', self.copy, {CHECK: {'status': 'skipped'}}), '')
        self.assertEqual(self.cel(PLAN, 'base', self.vm), '')
        steps = {item['name']: item for job in yaml.safe_load(PUBLISH.read_text())['jobs'] for item in job['steps']}
        read, check, push = steps[READ], steps[CHECK], steps[PUSH]
        self.assertEqual(read['dependsOn'], [{'step': 'Mint the push token', 'condition': {'type': 'succeeded'}}])
        self.assertIs(read['task']['inputs']['ignoreExitCode'], True)
        self.assertEqual(read['task']['inputs']['env']['GIT_CONFIG_VALUE_3'], push['task']['inputs']['env']['GIT_CONFIG_VALUE_3'])
        self.assertEqual(check['dependsOn'], [{'step': READ, 'condition': {'type': 'succeeded'}}])
        self.assertEqual((check['task']['behaviorType'], check['task']['methodName']), ('@nckrtl/task-publication-data', 'base'))
        self.assertIn({'step': CHECK, 'condition': {'type': 'or', 'conditions': [{'type': 'succeeded'}, {'type': 'skipped'}]}}, push['dependsOn'])
        # Only keys the publish intent already had: an older Gateway's run reaches these steps with the same intent.
        keys = set(re.findall(r'inputs\.intent\.(\w+)', json.dumps(read) + json.dumps(check)))
        self.assertEqual(keys, {'node_id', 'remote', 'base', 'branch'})


if __name__ == '__main__':
    unittest.main()
