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
        node_before = set(Path('/var/tmp').glob('orbit-task-export-*'))
        exported, _ = self.phase('export')
        self.assertEqual(exported['bundle'], self.intent['worker_bundle'])
        self.assertNotIn('node_scratch', exported)
        self.assertEqual(hashlib.sha256(Path(exported['bundle']).read_bytes()).hexdigest(), exported['sha256'])
        self.assertEqual(set(Path('/var/tmp').glob('orbit-task-export-*')), node_before, 'Node export scratch leaked')
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



if __name__ == '__main__':
    unittest.main()
