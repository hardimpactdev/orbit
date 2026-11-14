"""A task copy's publication: the export to the worker, the check commit without .env, and the publish workflow's copy steps.

Real Ansible/Jinja, Git and the pinned CEL engine on the harness in _support/task_instance_workspace.py; never live containers.
"""
import hashlib
import importlib.util
import json
import os
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

ROOT = base.ROOT
BEHAVIORS = base.BEHAVIORS
# The harness's CEL runner, which also reads one `env.NAME` value of a step's inputs.
CEL = base.CEL.replace('const raw = field === "guard" ? step.guard :',
                       'const raw = field.startsWith("env.") ? step.task.inputs.env[field.slice(4)] : field === "guard" ? step.guard :')
assert CEL != base.CEL


class CopyExportTest(unittest.TestCase):
    """The export phase with `worker_bundle`, on the workspace harness."""

    setUp = base.WorkspaceHarness.setUp
    git = base.WorkspaceHarness.git
    phase = base.WorkspaceHarness.phase

    def assert_node_scratch_removed(self):
        """The Node folders this test's own export made, by the targets its Incus shim recorded; never another unit's folder."""
        folders = {str(Path(json.loads(line)[-1]).parent) for line in self.calls.read_text().splitlines()
                   if json.loads(line)[2:4] == ['file', 'pull'] and json.loads(line)[-1].startswith('/var/tmp/orbit-task-export-')}
        self.assertTrue(folders, 'the export made no Node scratch')
        self.assertEqual([folder for folder in folders if Path(folder).exists()], [], 'Node export scratch leaked')

    def test_a_publication_export_fetches_the_bundle_to_the_worker_and_keeps_no_node_scratch(self):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Candidate')
        self.intent['head'] = self.git('rev-parse', 'HEAD')
        value, _ = self.phase('preflight')
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        self.intent['candidate'] = {'head': self.intent['head'], 'tree': self.git('rev-parse', 'HEAD^{tree}')}
        scratch = Path('/tmp/orbit-task-publish-' + uuid.uuid4().hex)
        scratch.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, scratch, True)
        self.intent['worker_bundle'] = str(scratch / 'candidate.bundle')
        exported, _ = self.phase('export')
        self.assertEqual(exported['bundle'], self.intent['worker_bundle'])
        self.assertNotIn('node_scratch', exported)
        self.assertEqual(hashlib.sha256(Path(exported['bundle']).read_bytes()).hexdigest(), exported['sha256'])
        self.assert_node_scratch_removed()
        self.assertFalse((self.scratch / 'export.bundle').exists())
        # The production evidence reader accepts exactly this result.
        folder = Path('/tmp/orbit-ansible-' + str(uuid.uuid4()))
        folder.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, folder, True)
        (folder / 'result.json').write_text(json.dumps(exported))
        script = self.root / 'reader.ts'
        script.write_text('import { behavior } from ' + json.dumps((BEHAVIORS / 'task-instance-workspace/assets.ts').as_uri()) + ';\n'
                          'const value = await behavior.methods.result.execute({folder: Deno.args[0], phase: "export"}, '
                          '{writeResource: async (_spec, _name, value) => value}); console.log(JSON.stringify(value));')
        read = subprocess.run(['deno', 'run', '-A', str(script), str(folder)], capture_output=True, text=True, check=True, timeout=60)
        self.assertEqual(json.loads(read.stdout), {'phase': 'export', 'evidence': exported})


    def publication(self):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Candidate')
        self.intent['head'] = self.git('rev-parse', 'HEAD')
        value, _ = self.phase('preflight')
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        self.intent['candidate'] = {'head': self.intent['head'], 'tree': self.git('rev-parse', 'HEAD^{tree}')}
        scratch = Path('/tmp/orbit-task-publish-' + uuid.uuid4().hex)
        scratch.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, scratch, True)
        self.intent.update({'worker_bundle': str(scratch / 'candidate.bundle'), 'environment_path': 'apps/web/.env',
                            'worker_environment': str(scratch / 'environment')})
        return scratch

    def test_a_publication_export_fetches_the_copy_environment_file_to_the_worker_and_never_returns_it(self):
        (self.checkout / 'apps/web').mkdir(parents=True)
        (self.checkout / 'apps/web/.gitignore').write_text('.env\n')
        (self.checkout / 'apps/web/.env').write_bytes(b'APP_KEY=base64:not-a-real-key\r\n')
        scratch = self.publication()
        exported, output = self.phase('export')
        self.assertEqual((scratch / 'environment').read_bytes(), b'APP_KEY=base64:not-a-real-key\r\n')
        self.assertNotIn('not-a-real-key', json.dumps(exported) + output)
        self.assert_node_scratch_removed()

    def test_a_publication_export_refuses_a_copy_without_a_regular_environment_file(self):
        (self.checkout / 'apps/web').mkdir(parents=True)
        (self.checkout / 'apps/web/.gitignore').write_text('.env\n')
        self.publication()
        self.phase('export', ok=False)
        self.assert_node_scratch_removed()
        (self.checkout / 'apps/web/.env').symlink_to('/etc/hostname')
        self.phase('export', ok=False)
        self.assert_node_scratch_removed()
        bad = dict(self.intent, environment_path='../outside/.env')
        self.phase('export', intent=bad, ok=False)


class CopyCheckTest(unittest.TestCase):
    """The container check child, on the check harness."""

    setUp = base.CheckHarness.setUp
    git = base.CheckHarness.git
    phase = base.CheckHarness.phase
    cel = base.CheckHarness.cel
    ran = base.CheckHarness.ran
    write_input = base.CheckHarness.write_input
    ansible_intent = base.CheckHarness.ansible_intent
    behavior = base.CheckHarness.behavior
    run_phase = base.CheckHarness.run_phase
    evidence = base.CheckHarness.evidence
    checked = base.CheckHarness.checked
    finished = base.CheckHarness.finished

    def test_the_candidate_holds_no_environment_file_that_the_create_excluded(self):
        # As the create leaves a copy: the .env Orbit wrote, and its line in info/exclude; the Project's .gitignore does not name it.
        (self.checkout / '.env').write_text('APP_KEY=base64:not-a-real-key\n')
        with (self.checkout / '.git/info/exclude').open('a') as stream:
            stream.write('/.env\n')
        _, local = self.checked()
        self.assertEqual(local['outcome'], 'passed', local)
        candidate, _ = self.finished()
        self.assertNotIn('.env', self.git('ls-tree', '-r', '--name-only', candidate['commit']).splitlines())
        self.assertEqual((self.checkout / '.env').read_text(), 'APP_KEY=base64:not-a-real-key\n')


PUBLISH = ROOT / 'workflows/workflow-operation-task-publish.yaml'


class TaskCopyPublishTest(unittest.TestCase):
    """A task copy's publication: the workflow's own CEL picks the copy steps, and its own script checks the .env Orbit wrote."""

    git = base.WorkspaceHarness.git

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='copy-publish-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / 'cel.ts').write_text(CEL)
        self.copy = {'commit': 'b' * 40, 'tree': 'c' * 40, 'node_id': 9, 'workspace': {
            'runtime': {'project': 'orbit-instances', 'name': 'instance-9'}, 'branch': 'task-1', 'snapshot_id': str(uuid.uuid4()),
            'start': 'a' * 40, 'environment_path': '.env'}}
        self.vm = {key: value for key, value in self.copy.items() if key != 'workspace'}

    def cel(self, step, field, intent, steps):
        file = self.root / 'context.json'
        file.write_text(json.dumps({'inputs': {'intent': intent}, 'steps': steps, 'run': {'id': str(uuid.uuid4())}}))
        done = subprocess.run(['deno', 'run', '-A', str(self.root / 'cel.ts'), str(PUBLISH), step, field, str(file)],
                              capture_output=True, text=True, check=False, timeout=60)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads(done.stdout)['value']

    def test_the_native_end_check_runs_after_a_success_on_either_path(self):
        """The Gateway settles tasks.publish only from "Confirm publication" (its native terminal result). On a success, a step that a
        path guards off is `skipped`, and Swamp's `completed` does not accept `skipped`: such a dependency must name it."""
        steps = {step['name']: step for job in yaml.safe_load(PUBLISH.read_text())['jobs'] for step in job['steps']}

        def accepts(condition, status):
            kind = condition['type']
            if kind == 'or':
                return any(accepts(item, status) for item in condition['conditions'])
            return status in {'completed': {'succeeded', 'failed'}, 'succeeded': {'succeeded'}, 'skipped': {'skipped'},
                              'failed': {'failed'}}[kind]

        for intent in (self.copy, self.vm):
            success = {'Mint the push token': {'status': 'succeeded'},
                       'Preflight the copy': {'status': 'succeeded' if 'workspace' in intent else 'skipped'}}
            for dependency in steps['Confirm publication']['dependsOn']:
                name = dependency['step']
                skipped = 'guard' in steps[name] and self.cel(name, 'guard', intent, success)
                status = 'skipped' if skipped else 'succeeded'
                self.assertTrue(accepts(dependency['condition'], status), (name, status, 'workspace' in intent))

    def test_a_copy_runs_its_export_and_only_a_succeeded_bundle_step_starts_the_import(self):
        for step in ['Preflight the copy', 'Export the copy', 'Check the environment file']:
            self.assertFalse(self.cel(step, 'guard', self.copy, {}), step)
            self.assertTrue(self.cel(step, 'guard', self.vm, {}), step)
        self.assertTrue(self.cel('Read the index', 'guard', self.copy, {}))
        self.assertFalse(self.cel('Read the index', 'guard', self.vm, {}))
        init = 'Init the bare repo'
        self.assertFalse(self.cel(init, 'guard', self.copy, {'Copy the bundle': {'status': 'skipped'}, 'Export the copy': {'status': 'succeeded'}}))
        self.assertTrue(self.cel(init, 'guard', self.copy, {'Copy the bundle': {'status': 'skipped'}, 'Export the copy': {'status': 'skipped'}}))
        self.assertFalse(self.cel(init, 'guard', self.vm, {'Copy the bundle': {'status': 'succeeded'}, 'Export the copy': {'status': 'skipped'}}))
        # A failure before the copy step skips it; the guard must not let that skip start the import.
        self.assertTrue(self.cel(init, 'guard', self.vm, {'Copy the bundle': {'status': 'skipped'}, 'Export the copy': {'status': 'skipped'}}))
        self.assertTrue(self.cel('Discard the copy scratch', 'guard', self.copy, {'Preflight the copy': {'status': 'failed'}}))
        self.assertFalse(self.cel('Discard the copy scratch', 'guard', self.copy, {'Preflight the copy': {'status': 'succeeded'}}))

    def environment_check(self, repository, start, path='.env'):
        run = self.cel('Check the environment file', 'run', self.copy, {})
        return subprocess.run(['/bin/sh', '-c', run], capture_output=True, text=True, check=False, timeout=60,
                              env={'PATH': os.environ['PATH'], 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1',
                                   'ORBIT_REPOSITORY': str(repository), 'ORBIT_START': start, 'ORBIT_PATH': path})

    def commit(self, work, message, files=None):
        for name, content in (files or {}).items():
            (work / name).parent.mkdir(parents=True, exist_ok=True)
            (work / name).write_text(content)
        self.git('add', '--all', cwd=work)
        self.git('commit', '--quiet', '--allow-empty', '-m', message, cwd=work)
        return self.git('rev-parse', 'HEAD', cwd=work)

    def candidate(self, work):
        """The worker's bare repository as the publication makes it: the export's branch bundle, imported by the workflow's own step."""
        bare = self.root / ('bare-' + uuid.uuid4().hex)
        bundle = self.root / (bare.name + '.bundle')
        self.git('bundle', 'create', str(bundle), 'refs/heads/task-1', cwd=work)
        self.git('init', '--quiet', '--bare', str(bare), cwd=self.root)
        run = self.cel('Import the bundle', 'run', self.copy, {})
        ref = self.cel('Import the bundle', 'env.ORBIT_REF', self.copy, {})
        self.assertEqual(ref, 'refs/heads/task-1')
        self.assertEqual(self.cel('Import the bundle', 'env.ORBIT_REF', self.vm, {}), 'HEAD')
        done = subprocess.run(['/bin/sh', '-c', run], capture_output=True, text=True, check=False, timeout=60,
                              env={'PATH': os.environ['PATH'], 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1',
                                   'ORBIT_REPOSITORY': str(bare), 'ORBIT_BUNDLE': str(bundle), 'ORBIT_REF': ref})
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(self.git('rev-parse', 'refs/heads/candidate', cwd=bare), self.git('rev-parse', 'HEAD', cwd=work))
        return bare

    def test_the_environment_file_never_leaves_a_copy_in_any_commit_of_the_range(self):
        work = self.root / 'work'
        work.mkdir()
        self.git('init', '--quiet', '--initial-branch=task-1', cwd=work)
        start = self.commit(work, 'seed', {'app.txt': 'seed\n'})
        self.commit(work, 'agent work', {'app.txt': 'changed\n'})
        clean = self.environment_check(self.candidate(work), start)
        self.assertEqual(clean.returncode, 0, clean.stdout + clean.stderr)
        # Added in one commit and removed in the next: the key stays in history, so the range refuses.
        self.commit(work, 'add the env', {'.env': 'APP_KEY=base64:not-a-real-key\n'})
        (work / '.env').unlink()
        self.commit(work, 'remove the env')
        refused = self.environment_check(self.candidate(work), start)
        self.assertEqual((refused.returncode, refused.stdout.strip()), (3, 'tasks.publish_secret_path'))
        self.assertNotIn('APP_KEY', refused.stdout + refused.stderr)
        # The path comes from the Instance: an application folder's .env.
        folder = self.root / 'folder'
        folder.mkdir()
        self.git('init', '--quiet', '--initial-branch=task-1', cwd=folder)
        base = self.commit(folder, 'seed', {'apps/web/app.txt': 'seed\n'})
        self.commit(folder, 'root env only', {'.env': 'X=1\n'})
        self.assertEqual(self.environment_check(self.candidate(folder), base, 'apps/web/.env').returncode, 0)
        self.commit(folder, 'folder env', {'apps/web/.env': 'APP_KEY=base64:not-a-real-key\n'})
        self.assertEqual(self.environment_check(self.candidate(folder), base, 'apps/web/.env').returncode, 3)
        # A start the bundle does not hold refuses too.
        self.assertNotEqual(self.environment_check(self.candidate(work), 'f' * 40).returncode, 0)



    def test_a_copy_mints_only_after_its_content_check_succeeded(self):
        step = 'Check the .env content'
        self.assertFalse(self.cel(step, 'guard', self.copy, {}))
        self.assertTrue(self.cel(step, 'guard', self.vm, {}))
        mint = 'Mint the push token'
        self.assertFalse(self.cel(mint, 'guard', self.copy, {step: {'status': 'succeeded'}}))
        for status in ['skipped', 'failed']:
            self.assertTrue(self.cel(mint, 'guard', self.copy, {step: {'status': status}}), status)
        self.assertTrue(self.cel(mint, 'guard', self.copy, {}))
        self.assertFalse(self.cel(mint, 'guard', self.vm, {step: {'status': 'skipped'}}))
        steps = {item['name']: item for job in yaml.safe_load(PUBLISH.read_text())['jobs'] for item in job['steps']}
        self.assertIn({'step': step, 'condition': {'type': 'or', 'conditions': [{'type': 'succeeded'}, {'type': 'skipped'}]}},
                      steps[mint]['dependsOn'])
        self.assertEqual(steps[step]['dependsOn'], [{'step': 'Check the environment file', 'condition': {'type': 'succeeded'}}])
        self.assertEqual(steps[step]['task']['inputs']['env']['ORBIT_ENVIRONMENT'],
                         '${{ vault.get(inputs.execution_secrets.environment.vault, inputs.execution_secrets.environment.key) }}')

    ENV = 'APP_NAME=Orbit\nAPP_DEBUG=true\nDB_PORT=8080\nexport APP_KEY="base64:c2VjcmV0LWtleS1ub3QtcmVhbA=="\r\nDB_PASSWORD=\'pa55-w0rd-xyz\'\nPEM="line-one-abcdefgh\\nline-two-ijklmnop"\n# old token-in-a-comment-123\n'

    def content_check(self, repository, start, copy=None, rendered='', pull_request=None):
        directory = Path(tempfile.mkdtemp(prefix='orbit-task-publish-', dir=self.root))
        (directory / 'environment').write_text(self.ENV if copy is None else copy)
        step = 'Check the .env content'
        intent = {**self.copy, 'pull_request': pull_request}
        run = self.cel(step, 'run', intent, {})
        # The pull request text comes through the step's own expressions, with the pinned CEL engine.
        pull = {name: self.cel(step, 'env.' + name, intent, {}) for name in ['ORBIT_PULL_TITLE', 'ORBIT_PULL_BODY']}
        return subprocess.run(['/bin/sh', '-c', run], capture_output=True, text=True, check=False, timeout=120,
                              env={'PATH': os.environ['PATH'], 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1',
                                   'ORBIT_REPOSITORY': str(repository), 'ORBIT_DIRECTORY': str(directory),
                                   'ORBIT_FILE': str(directory / 'environment'), 'ORBIT_START': start, 'ORBIT_ENVIRONMENT': rendered,
                                   **pull})

    def copy_repository(self, files=None):
        work = self.root / ('work-' + uuid.uuid4().hex)
        work.mkdir()
        self.git('init', '--quiet', '--initial-branch=task-1', cwd=work)
        start = self.commit(work, 'seed', {'app.txt': 'seed\n', '.env.example': 'APP_NAME=Orbit\nDB_PORT=8080\n', **(files or {})})
        return work, start

    def refused(self, done, *names):
        self.assertEqual(done.returncode, 3, done.stdout + done.stderr)
        self.assertTrue(done.stderr.startswith('tasks.publish_secret_content: '), done.stderr)
        for name in names:
            self.assertIn(name, done.stderr)
        for value in ['c2VjcmV0', 'pa55-w0rd', 'line-one', 'line-two', 'token-in-a-comment', 'rendered-only']:
            self.assertNotIn(value, done.stdout + done.stderr)

    def test_the_content_check_passes_a_clean_range_and_public_or_short_values(self):
        work, start = self.copy_repository()
        self.commit(work, 'agent work', {'app.txt': 'changed\n', 'docs/note.md': 'APP_DEBUG=true and port 8080, app Orbit\n',
                                         'config/example.env': 'APP_NAME=Orbit\nDB_PORT=8080\n', 'empty.txt': ''})
        done = self.content_check(self.candidate(work), start)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        # An empty .env and render check nothing, also when the range adds an empty file.
        self.assertEqual(self.content_check(self.candidate(work), start, copy='').returncode, 0)

    def test_the_content_check_refuses_the_whole_file_under_another_name_also_when_removed_later(self):
        work, start = self.copy_repository()
        add = self.commit(work, 'backup', {'env.backup': self.ENV})
        (work / 'env.backup').unlink()
        self.commit(work, 'remove the backup')
        self.refused(self.content_check(self.candidate(work), start), "the whole .env is the file 'env.backup'", add)

    def test_the_content_check_refuses_one_value_in_another_file_a_message_or_a_file_name(self):
        cases = {'value in a file': ({'config/local.txt': 'key: base64:c2VjcmV0LWtleS1ub3QtcmVhbA==\n'}, 'x', "file 'config/local.txt'"),
                 'single-quoted value': ({'tests/fixture.json': '{"password": "pa55-w0rd-xyz"}'}, 'x', "file 'tests/fixture.json'"),
                 'one line of a multi-line value': ({'key.pem': 'line-two-ijklmnop\n'}, 'x', "file 'key.pem'"),
                 'a comment line': ({'notes.txt': 'old token-in-a-comment-123\n'}, 'x', "file 'notes.txt'"),
                 'commit message': ({'app.txt': 'other\n'}, 'use pa55-w0rd-xyz', 'message or author of commit'),
                 'file name': ({'logs/pa55-w0rd-xyz.log': 'x\n'}, 'x', "file name in folder 'logs'")}
        for name, (files, message, text) in cases.items():
            with self.subTest(name):
                work, start = self.copy_repository()
                commit = self.commit(work, message, files)
                done = self.content_check(self.candidate(work), start)
                self.refused(done, text)
                if name != 'commit message':
                    self.assertIn(commit, done.stderr)

    def test_the_content_check_refuses_a_value_of_the_render_that_the_copy_file_no_longer_holds(self):
        work, start = self.copy_repository()
        self.commit(work, 'agent work', {'data.txt': 'DB_PASSWORD=rendered-only-value-9\n'})
        # The agent rewrote the copy's file; the render of the stored values still holds the value.
        self.refused(self.content_check(self.candidate(work), start, copy='APP_NAME=Orbit\n',
                                        rendered='DB_PASSWORD=rendered-only-value-9\n'), "file 'data.txt'")

    def test_the_content_check_passes_a_value_that_the_start_tree_already_holds(self):
        work, start = self.copy_repository({'docs/known.txt': 'pa55-w0rd-xyz\n'})
        self.commit(work, 'agent work', {'docs/again.txt': 'pa55-w0rd-xyz\n'})
        self.assertEqual(self.content_check(self.candidate(work), start).returncode, 0)

    def test_the_content_check_reads_values_as_the_renderer_and_a_default_file_write_them(self):
        """Escaped characters of a rendered value, inline comments, and keys in comment lines."""
        env = ('DB_PASSWORD="pa\\$\\$w0rd\\"xy\\\\z9"\n'
               'STRIPE=sk_live_abcdef123456 # prod key\n'
               'OTHER="quoted-secret-99" # note\n'
               '# OLD_PASSWORD=old-secret-value-77\n'
               '#export LEGACY="another-old-999"\n')
        for name, text in {'unescaped double-quoted value': 'pa$$w0rd"xy\\z9', 'unquoted value before a comment': 'sk_live_abcdef123456',
                           'quoted value before a comment': 'quoted-secret-99', 'key in a comment line': 'old-secret-value-77',
                           'export key in a comment line': 'another-old-999'}.items():
            with self.subTest(name):
                work, start = self.copy_repository()
                self.commit(work, 'agent work', {'leak.txt': 'x ' + text + ' y\n'})
                done = self.content_check(self.candidate(work), start, copy=env)
                self.assertEqual(done.returncode, 3, done.stderr)
                self.assertNotIn(text, done.stderr)

    def test_a_refusal_never_names_a_path_that_holds_a_value(self):
        for name, files in {'whole file': {'backups/env-pa55-w0rd-xyz.bak': self.ENV},
                            'value split over folders': {'pa55-w/0rd-xyz': 'base64:c2VjcmV0LWtleS1ub3QtcmVhbA==\n'}}.items():
            with self.subTest(name):
                work, start = self.copy_repository()
                commit = self.commit(work, 'x', files)
                done = self.content_check(self.candidate(work), start)
                self.refused(done, 'a path that holds a value', commit)

    def test_a_file_name_at_the_top_names_its_commit(self):
        work, start = self.copy_repository()
        commit = self.commit(work, 'x', {'pa55-w0rd-xyz.txt': 'x\n'})
        self.refused(self.content_check(self.candidate(work), start), "file name in folder '.'", commit)

    def test_the_content_check_refuses_a_value_in_the_pull_request_text(self):
        work, start = self.copy_repository()
        self.commit(work, 'agent work', {'app.txt': 'changed\n'})
        repository = self.candidate(work)
        self.assertEqual(self.content_check(repository, start, pull_request={'title': 'Fix', 'body': 'Done.', 'draft': True}).returncode, 0)
        for pull in [{'title': 'Use pa55-w0rd-xyz', 'body': 'Done.'}, {'title': 'Fix', 'body': 'Key base64:c2VjcmV0LWtleS1ub3QtcmVhbA== set'}]:
            self.refused(self.content_check(repository, start, pull_request=pull), 'pull request title or body')

    def test_an_error_in_the_content_check_refuses_without_the_content_code(self):
        work, start = self.copy_repository()
        self.commit(work, 'agent work', {'app.txt': 'changed\n'})
        repository = self.candidate(work)
        missing = self.content_check(repository, 'f' * 40)
        self.assertNotIn(missing.returncode, [0, 3])
        self.assertNotIn('tasks.publish_secret_content', missing.stderr)
        directory = Path(tempfile.mkdtemp(prefix='orbit-task-publish-', dir=self.root))
        run = self.cel('Check the .env content', 'run', self.copy, {})
        absent = subprocess.run(['/bin/sh', '-c', run], capture_output=True, text=True, check=False, timeout=60,
                                env={'PATH': os.environ['PATH'], 'ORBIT_REPOSITORY': str(repository), 'ORBIT_DIRECTORY': str(directory),
                                     'ORBIT_FILE': str(directory / 'environment'), 'ORBIT_START': start, 'ORBIT_ENVIRONMENT': '',
                                     'ORBIT_PULL_TITLE': '', 'ORBIT_PULL_BODY': ''})
        self.assertEqual(absent.returncode, 1)
        self.assertTrue(absent.stderr.startswith('tasks.publish_secret_unchecked'), absent.stderr)

if __name__ == '__main__':
    unittest.main()
