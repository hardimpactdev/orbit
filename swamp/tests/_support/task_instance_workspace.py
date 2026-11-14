"""Real Ansible/Jinja and Git; a strict local Incus shim, never live containers."""
import copy
import json
import os
import shutil
import subprocess
import tempfile
import unittest
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TASKS = ROOT / 'ansible/tasks/task-instance-workspace-phase.yml'
FIXTURE = json.loads((ROOT / 'tests/fixtures/task-instance-workspace.json').read_text())
CHECK = FIXTURE['check']
BEHAVIORS = ROOT / 'extensions/behaviors/operations'
COMMAND = shutil.which('ansible-playbook') or '/opt/orbit/ansible/venv/bin/ansible-playbook'

# Validate the production Incus boundary, then map the fixed guest cwd to a disposable checkout.
# The Python program and every Git command are production code, evaluated by installed Ansible.
SHIM = '''#!/usr/bin/env python3
import json, os, shutil, subprocess, sys
from pathlib import Path
args = sys.argv[1:]
with open(os.environ['T1_CALLS'], 'a') as output:
    output.write(json.dumps(args) + '\\n')
assert args[:3] == ['--project', 'alternate-proof', 'exec'] or args[:4] == ['--project', 'alternate-proof', 'file', 'pull'], args
if args[2] == 'exec':
    assert args[3:12] == ['--disable-stdin', '--cwd', '/srv/workspace', 'proof-instance', '--', '/usr/sbin/runuser', '-u', 'agent', '--'], args
    assert args[12:17] == ['/usr/bin/env', '-i', 'HOME=/home/agent', 'PATH=/usr/local/bin:/usr/bin:/bin', '/usr/bin/python3'], args
    assert args[17:19] == ['-I', '-c'], args
    assert sys.stdin.buffer.read() == b'', 'Incus stdin must be closed'
    if os.environ.get('T1_ASSUME_DIFFERENT_OWNER') == '1':
        # Exercise Git's ownership check without chown or privileged commands.
        args[19] = args[19].replace('git_argv =', "env['GIT_TEST_ASSUME_DIFFERENT_OWNER'] = '1'\\n" + 'git_argv =', 1)
    if args[20] == 'export-cleanup' and os.environ.get('T1_CLEANUP_FAIL') == '1':
        sys.exit(1)
    if args[20] == 'export' and os.environ.get('T1_MOVE_TO'):
        # Move the real branch at the bundle-creation barrier, after candidate validation.
        args[19] = args[19].replace("git('bundle', 'create',",
            "git('update-ref', 'refs/heads/' + request['branch'], " + repr(os.environ['T1_MOVE_TO']) + ")\\n" +
            ' ' * 12 + "git('bundle', 'create',", 1)
    result = subprocess.run([sys.executable, *args[17:]], cwd=os.environ['T1_GUEST'], env={**os.environ}, check=False)
    sys.exit(result.returncode)
elif args[4] == '--recursive':
    assert len(args) == 7 and args[5].startswith('proof-instance/tmp/orbit-task-workspace-'), args
    source = '/' + args[5].split('/', 1)[1]
    shutil.copytree(source, os.path.join(args[6], os.path.basename(source)))
elif args[4].startswith('proof-instance/srv/workspace/'):
    # A file of the checkout, as the Node pulls it: a link stays a link.
    assert len(args) == 6, args
    shutil.copyfile(os.path.join(os.environ['T1_GUEST'], args[4][len('proof-instance/srv/workspace/'):]), args[5], follow_symlinks=False)
else:
    assert len(args) == 6 and args[4].startswith('proof-instance/tmp/orbit-task-workspace-'), args
    shutil.copyfile('/' + args[4].split('/', 1)[1], args[5])
    if os.environ.get('T1_CORRUPT') == '1':
        with open(args[5], 'ab') as bundle:
            bundle.write(b'corrupt')
'''


class WorkspaceHarness(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='t1-local-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.checkout = self.root / 'checkout'
        self.checkout.mkdir()
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        (self.bin / 'incus').write_text(SHIM)
        (self.bin / 'incus').chmod(0o755)
        self.calls = self.root / 'calls.jsonl'
        self.snapshot_id = str(uuid.uuid4())
        self.scratch = Path('/tmp/orbit-task-workspace-' + self.snapshot_id)
        self.addCleanup(shutil.rmtree, self.scratch, True)
        self.git('init', '--quiet', '--initial-branch=' + FIXTURE['branch'])
        for name, content in FIXTURE['base_files'].items():
            (self.checkout / name).write_text(content)
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Captured T1 base')
        for name, content in FIXTURE['changes'].items():
            (self.checkout / name).write_text(content)
        for name, target in FIXTURE['symlinks'].items():
            (self.checkout / name).symlink_to(target)
        self.git('add', *FIXTURE['staged'])
        (self.checkout / 'ignored').mkdir()
        (self.checkout / 'ignored/user.txt').write_text('preserved ignored data\n')
        self.intent = {'runtime': {'project': 'alternate-proof', 'name': 'proof-instance'},
                       'branch': FIXTURE['branch'], 'head': FIXTURE['expected']['head'], 'snapshot_id': self.snapshot_id}
        self.play = self.root / 'play.yml'
        self.play.write_text(json.dumps([{'name': 'Exercise the production phase', 'hosts': 'localhost', 'connection': 'local',
            'gather_facts': False, 'tasks': [{'name': 'Run workspace phase', 'ansible.builtin.import_tasks': str(TASKS)}]}]))

    def git(self, *args, cwd=None):
        return subprocess.run(['git', *args], cwd=cwd or self.checkout, check=True, capture_output=True,
                              env={**os.environ, **FIXTURE['git_env']}).stdout.decode().strip()

    def phase(self, phase, intent=None, ok=True, corrupt=False, different_owner=False, move_to=None, cleanup_fail=False,
              result_path=None):
        variables = self.root / 'vars.json'
        result_path = result_path or self.root / 'result.json'
        result_path.unlink(missing_ok=True)
        variables.write_text(json.dumps({'intent': intent or self.intent, 'workspace_phase': phase,
                                         'workspace_result_path': str(result_path)}))
        result = subprocess.run([COMMAND, '-i', 'localhost,', '-e', '@' + str(variables), str(self.play)],
            capture_output=True, text=True, check=False, timeout=120,
            env={**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'T1_GUEST': str(self.checkout),
                 'T1_CALLS': str(self.calls), 'T1_CORRUPT': '1' if corrupt else '0',
                 'T1_ASSUME_DIFFERENT_OWNER': '1' if different_owner else '0',
                 'T1_MOVE_TO': move_to or '', 'T1_CLEANUP_FAIL': '1' if cleanup_fail else '0',
                 'ANSIBLE_CONFIG': str(ROOT / 'ansible/ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
        output = result.stdout + result.stderr
        if ok:
            self.assertEqual(result.returncode, 0, output)
            return json.loads(result_path.read_text()), output
        self.assertNotEqual(result.returncode, 0, output)
        return output

    def prepare(self):
        value, _ = self.phase('preflight')
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        before, _ = self.phase('before')
        self.intent['before_digest'] = before['digest']
        self.intent['candidate'] = {'head': before['head'], 'tree': before['tree']}
        return before

    def check_typed_result(self, before):
        """Pass actual captured phase output through the production typed collector."""
        folder = Path('/tmp/orbit-ansible-' + self.snapshot_id)
        folder.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, folder, True)
        result = folder / 'result.json'
        result.write_text(json.dumps(before))
        script = self.root / 'collector.ts'
        source = ROOT / 'extensions/behaviors/operations/task-instance-workspace/assets.ts'
        script.write_text('import { behavior } from ' + json.dumps(source.as_uri()) + ';\n' +
                         'const value = await behavior.methods.result.execute({folder: Deno.args[0], phase: "before"}, '
                         '{writeResource: async (_spec, _name, value) => value}); console.log(JSON.stringify(value));')
        command = ['deno', 'run', '-A', str(script), str(folder)]
        checked = subprocess.run(command, capture_output=True, text=True, check=True, timeout=30)
        self.assertEqual(json.loads(checked.stdout), {'phase': 'before', 'evidence': before})
        result.write_bytes(b'x' * 524289)
        oversized = subprocess.run(command, capture_output=True, text=True, check=False, timeout=30)
        self.assertNotEqual(oversized.returncode, 0)
        self.assertIn('tasks.workspace_phase_failed', oversized.stderr)


    def export_candidate(self):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Candidate')
        self.intent['head'] = self.git('rev-parse', 'HEAD')
        value, _ = self.phase('preflight')
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        self.intent['candidate'] = {'head': self.intent['head'], 'tree': self.git('rev-parse', 'HEAD^{tree}')}


# Run the production check rules on the evidence: the same TypeScript that judges a task VM check.
RUNNER = """
const [module, method, file] = Deno.args;
const { behavior } = await import(module);
const args = JSON.parse(await Deno.readTextFile(file));
const value = await behavior.methods[method].execute(args, {writeResource: async (_spec, _name, value) => value});
console.log(JSON.stringify(value.dataHandles ? value.dataHandles[0] : value));
"""
# Evaluate one workflow expression with the pinned CEL engine, as Swamp evaluates it, against the real step outputs.
CEL = """
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
const [workflow, name, field, file] = Deno.args;
const flow = parse(await Deno.readTextFile(workflow));
const step = flow.jobs.flatMap((job) => job.steps).find((item) => item.name === name);
const raw = field === "guard" ? step.guard : field === "expr" ? step.task.expr : field === "workflow" ? step.task.workflowIdOrName : step.task.inputs[field];
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const expression = typeof raw === "string" && raw.startsWith("${{") ? raw.slice(3, -2).trim() : field === "expr" ? raw : null;
const value = expression === null ? raw : cel.evaluate(expression, JSON.parse(await Deno.readTextFile(file)));
console.log(JSON.stringify({ value }, (_key, item) => typeof item === "bigint" ? Number(item) : item));
"""
CHILD = ROOT / 'workflows/workflow-task-instance-check.yaml'
PARENT = ROOT / 'workflows/workflow-operation-task-check.yaml'


class CheckHarness(unittest.TestCase):
    """The container check child: production Ansible phases, real Git and the production TypeScript check rules."""

    git = WorkspaceHarness.git
    phase = WorkspaceHarness.phase

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='t2-local-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.bin = self.root / 'bin'
        self.bin.mkdir()
        (self.bin / 'incus').write_text(SHIM)
        (self.bin / 'incus').chmod(0o755)
        self.calls = self.root / 'calls.jsonl'
        self.snapshot_id = str(uuid.uuid4())
        self.scratch = Path('/tmp/orbit-task-workspace-' + self.snapshot_id)
        self.addCleanup(shutil.rmtree, self.scratch, True)
        self.play = self.root / 'play.yml'
        self.play.write_text(json.dumps([{'name': 'Exercise the production phase', 'hosts': 'localhost', 'connection': 'local',
            'gather_facts': False, 'tasks': [{'name': 'Run workspace phase', 'ansible.builtin.import_tasks': str(TASKS)}]}]))
        self.checkout = self.root / 'instance'
        self.checkout.mkdir()
        self.git('init', '--quiet', '--initial-branch=' + FIXTURE['branch'])
        for name, content in CHECK['base_files'].items():
            (self.checkout / name).write_text(content)
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Captured check base')
        self.base = self.git('rev-parse', 'HEAD')
        self.assertEqual(self.base, CHECK['expected']['base_head'])
        for files in [CHECK['candidate_files'], CHECK['installed'], CHECK['force_added']]:
            for name, content in files.items():
                (self.checkout / name).parent.mkdir(parents=True, exist_ok=True)
                (self.checkout / name).write_text(content)
        self.git('add', '-f', *CHECK['force_added'])
        # A Node directory at the admitted checkout path: it has no dependencies and a passing decoy test.
        self.decoy = self.root / 'node-checkout'
        for name, content in CHECK['decoy'].items():
            (self.decoy / name).parent.mkdir(parents=True, exist_ok=True)
            (self.decoy / name).write_text(content)
        self.check = copy.deepcopy(CHECK['intent'])
        self.check['base'] = self.base
        self.check['workspace']['snapshot_id'] = self.snapshot_id
        self.run_id = str(uuid.uuid4())
        self.folder = Path('/tmp/orbit-ansible-' + self.run_id)
        self.folder.mkdir(mode=0o700)
        self.addCleanup(shutil.rmtree, self.folder, True)
        (self.root / 'runner.ts').write_text(RUNNER)
        (self.root / 'cel.ts').write_text(CEL)
        self.steps = {}

    def cel(self, step, field, workflow=CHILD, check=None, steps=None):
        file = self.root / 'cel-context.json'
        file.write_text(json.dumps({'inputs': {'operation_id': CHECK['operation_id'], 'node_id': (check or self.check)['node_id'],
                                               'kind': 'tasks.check', 'intent': check or self.check, 'execution_secrets': {},
                                               'run_id': self.run_id},
                                    'steps': self.steps if steps is None else steps, 'run': {'id': self.run_id}}))
        done = subprocess.run(['deno', 'run', '-A', str(self.root / 'cel.ts'), str(workflow), step, field, str(file)],
                              capture_output=True, text=True, check=False, timeout=60)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads(done.stdout)['value']

    def ran(self, step, outputs):
        self.steps[step] = {'outputs': outputs}
        return outputs

    def write_input(self, step):
        """The production ansible-input step: its CEL intent, written where the playbook reads it."""
        self.assertEqual(self.cel(step, 'path'), str(self.folder / 'intent.json'))
        return self.cel(step, 'intent')

    def ansible_intent(self, check=None, **extra):
        check = check or self.check
        workspace = check['workspace']
        return {'runtime': workspace['runtime'], 'branch': workspace['branch'], 'snapshot_id': workspace['snapshot_id'],
                'operation_id': CHECK['operation_id'], 'ssh_execution': check['ssh_execution'], 'check': check, **extra}

    def behavior(self, module, method, args):
        file = self.root / ('args-' + method + '.json')
        file.write_text(json.dumps(args))
        done = subprocess.run(['deno', 'run', '-A', str(self.root / 'runner.ts'), (BEHAVIORS / module).as_uri(), method, str(file)],
                              capture_output=True, text=True, check=False, timeout=60)
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return json.loads(done.stdout)

    def run_phase(self, phase, intent, ok=True):
        return self.phase(phase, intent, ok=ok, result_path=self.folder / 'result.json')

    def evidence(self, phase):
        return self.behavior('task-instance-workspace/assets.ts', 'result', {'folder': str(self.folder), 'phase': phase})['evidence']

    def checked(self):
        """The child workflow's chain, wired by its own CEL: prepare, check phase, typed evidence and the production local rules."""
        self.assertFalse(self.cel('Check in the Instance', 'guard', PARENT))
        self.assertTrue(self.cel('Build every argv', 'guard', PARENT))
        self.assertTrue(self.cel('Push the candidate', 'guard', PARENT))
        self.assertEqual(self.cel('Check in the Instance', 'workflow', PARENT), 'task-instance-check')
        prepared = self.ran('prepare', self.behavior('task-repository/check.ts', 'prepare', {
            'intent': self.check, 'node_id': self.check['node_id'], 'root': self.cel('prepare', 'root')}))
        self.assertTrue(prepared['valid'], prepared)
        self.assertFalse(self.cel('input', 'guard'))
        intent = self.write_input('input')
        self.assertEqual(intent, self.ansible_intent())
        _, output = self.run_phase('check', intent)
        self.assertIn('changed=0', output)
        evidence = self.ran('checked', self.behavior('task-instance-workspace/assets.ts', 'result',
                                                     {'folder': self.cel('checked', 'folder'), 'phase': self.cel('checked', 'phase')}))['evidence']
        self.assertEqual(self.cel('local', 'outputs'), str(self.folder / 'output'))
        local = self.ran('local', self.behavior('task-repository/check.ts', 'local', {
            'intent': self.check, 'prepared': self.cel('local', 'prepared'), 'steps': self.cel('local', 'steps'),
            'outputs': self.cel('local', 'outputs')}))
        return evidence, local['result']

    def finished(self, commits=True):
        """The candidate, pin and finish steps by their CEL, then the parent's result read and end check. A clean checked tree
        makes no commit."""
        if not self.cel('Commit the candidate', 'guard'):
            _, output = self.run_phase('commit', self.write_input('input'))
            self.assertIn(f'changed={int(commits)}', output)
            self.ran('committed', self.behavior('task-instance-workspace/assets.ts', 'result', {'folder': str(self.folder), 'phase': 'commit'}))
        candidate = self.ran('candidate', self.behavior('task-repository/check.ts', 'candidate', {
            'intent': self.check, 'local': self.cel('candidate', 'local'), 'steps': self.cel('candidate', 'steps')}))['result']
        if not self.cel('pin input', 'guard'):
            self.run_phase('commit-ref', self.write_input('pin input'))
            self.ran('pinned', self.behavior('task-instance-workspace/assets.ts', 'result', {'folder': str(self.folder), 'phase': 'commit-ref'}))
        finish = self.behavior('task-repository/check.ts', 'finish', {'intent': self.check, 'local': self.cel('finish', 'local'),
                                                                       'steps': self.cel('finish', 'steps')})
        parent = {'Check in the Instance': {'outputs': {'finish': finish}}}
        self.assertEqual(self.cel('Read the result', 'result_json', PARENT, steps=parent), finish['result_json'])
        self.assertEqual(self.cel('Check the result', 'expr', PARENT, steps=parent), 'error_code' not in finish['result'])
        return candidate, finish
