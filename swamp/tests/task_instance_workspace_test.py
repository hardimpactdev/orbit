"""Real Ansible/Jinja and Git; a strict local Incus shim, never live containers."""
import copy
import hashlib
import json
import os
import shutil
import subprocess
import tempfile
import unittest
import uuid
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TASKS = ROOT / 'ansible/tasks/task-instance-workspace-phase.yml'
FIXTURE = json.loads((ROOT / 'tests/fixtures/task-instance-workspace.json').read_text())
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
    result = subprocess.run([sys.executable, *args[17:]], cwd=os.environ['T1_GUEST'], env={**os.environ}, check=False)
    sys.exit(result.returncode)
else:
    assert len(args) == 6 and args[4].startswith('proof-instance/tmp/orbit-task-workspace-'), args
    shutil.copyfile('/' + args[4].split('/', 1)[1], args[5])
    if os.environ.get('T1_CORRUPT') == '1':
        with open(args[5], 'ab') as bundle:
            bundle.write(b'corrupt')
'''


class TaskInstanceWorkspaceTest(unittest.TestCase):
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

    def phase(self, phase, intent=None, ok=True, corrupt=False, different_owner=False):
        variables = self.root / 'vars.json'
        result_path = self.root / 'result.json'
        result_path.unlink(missing_ok=True)
        variables.write_text(json.dumps({'intent': intent or self.intent, 'workspace_phase': phase,
                                         'workspace_result_path': str(result_path)}))
        result = subprocess.run([COMMAND, '-i', 'localhost,', '-e', '@' + str(variables), str(self.play)],
            capture_output=True, text=True, check=False, timeout=60,
            env={**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'T1_GUEST': str(self.checkout),
                 'T1_CALLS': str(self.calls), 'T1_CORRUPT': '1' if corrupt else '0',
                 'T1_ASSUME_DIFFERENT_OWNER': '1' if different_owner else '0',
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

    def test_tagged_playbook_pins_target_and_writes_failure_without_node_execution(self):
        # Execute the real two-play entry point locally. No SSH, Incus or sudo reaches a machine.
        run = self.root / 'run'
        run.mkdir()
        key = self.root / 'identity'
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(key)], check=True, timeout=10)
        intent = {**self.intent, 'ssh_execution': {'identity_file': str(key), 'target': {
            'host': 'workspace.invalid', 'port': 2222, 'user': 'fixture', 'host_keys': [key.with_suffix('.pub').read_text().strip()]}}}
        (run / 'intent.json').write_text(json.dumps(intent))
        extra = self.root / 'top-vars.json'
        extra.write_text(json.dumps({'ansible_connection': 'local', 'ansible_become': False,
                                     'ansible_python_interpreter': '/usr/bin/python3'}))
        command = [COMMAND, '-i', 'localhost,', '-e', '@' + str(extra), '--tags', 'preflight',
                   str(ROOT / 'ansible/task-instance-workspace.yml')]
        env = {**os.environ, 'PATH': str(self.bin) + ':' + os.environ['PATH'], 'T1_GUEST': str(self.checkout),
               'T1_CALLS': str(self.calls), 'ORBIT_RUN': str(run), 'ANSIBLE_FORCE_COLOR': '0',
               'ANSIBLE_CONFIG': str(ROOT / 'ansible/ansible.cfg')}
        passed = subprocess.run(command, capture_output=True, text=True, check=False, env=env, timeout=60)
        self.assertEqual(passed.returncode, 0, passed.stdout + passed.stderr)
        self.assertEqual(json.loads((run / 'result.json').read_text())['head'], FIXTURE['expected']['head'])
        self.assertIn('[workspace.invalid]:2222 ssh-ed25519 ', (run / 'known_hosts').read_text())
        self.calls.unlink()
        intent['runtime']['project'] = ''
        (run / 'intent.json').write_text(json.dumps(intent))
        failed = subprocess.run(command, capture_output=True, text=True, check=False, env=env, timeout=60)
        self.assertNotEqual(failed.returncode, 0)
        self.assertEqual(json.loads((run / 'result.json').read_text()), {'error_code': 'tasks.workspace_target_invalid'})
        self.assertFalse(self.calls.exists())

    def test_exact_checkout_trust_survives_isolated_git_config(self):
        value, _ = self.phase('preflight', different_owner=True)
        self.assertEqual(value['head'], FIXTURE['expected']['head'])
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        before, _ = self.phase('before', different_owner=True)
        self.assertEqual(before['tree'], FIXTURE['expected']['tree'])

    def test_snapshots_include_force_added_ignored_work_without_changing_index(self):
        path = self.checkout / 'ignored/staged-user.txt'
        path.write_text('original user staged data\n')
        self.git('add', '-f', 'ignored/staged-user.txt')
        index = (self.checkout / '.git/index').read_bytes()
        before = self.prepare()
        self.assertEqual(self.git('show', before['tree'] + ':ignored/staged-user.txt'),
                         'original user staged data')
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        path.write_text('later unstaged data\n')
        after, _ = self.phase('after')
        self.assertEqual(self.git('show', after['tree'] + ':ignored/staged-user.txt'), 'later unstaged data')
        self.assertEqual(self.git('show', after['index_tree'] + ':ignored/staged-user.txt'),
                         'original user staged data')
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.assertEqual(path.read_text(), 'later unstaged data\n')

    def test_captured_evidence_and_bounded_bound_receipt(self):
        index = (self.checkout / '.git/index').read_bytes()
        before = self.prepare()
        self.assertEqual(before['head'], FIXTURE['expected']['head'])
        self.assertEqual(before['tree'], FIXTURE['expected']['tree'])
        self.assertEqual(self.git('ls-tree', '-r', before['tree']), FIXTURE['expected']['ls_tree'].strip())
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.check_typed_result(before)
        repeated, _ = self.phase('before')
        self.assertEqual(before, repeated)
        receipt = {'snapshot_id': self.snapshot_id, 'candidate': self.intent['candidate'], 'receipt': {'verdict': 'pass'}}
        (self.scratch / 'receipt.json').write_text(json.dumps(receipt))
        value, _ = self.phase('receipt')
        self.assertEqual(value['receipt'], {'verdict': 'pass'})
        # The limit applies before UTF-8/JSON decode, including bytes hidden by multi-byte characters.
        raw = json.dumps(receipt).encode()
        (self.scratch / 'receipt.json').write_bytes(raw + b' ' * (65536 - len(raw)))
        value, _ = self.phase('receipt')
        self.assertEqual(value['bytes'], 65536)
        receipt['receipt']['message'] = 'é' * 33000
        (self.scratch / 'receipt.json').write_text(json.dumps(receipt, ensure_ascii=False))
        self.assertIn('tasks.workspace_receipt_invalid', self.phase('receipt', ok=False))
        del receipt['receipt']['message']
        receipt['candidate'] = {**self.intent['candidate'], 'head': '0' * 40}
        (self.scratch / 'receipt.json').write_text(json.dumps(receipt))
        self.assertIn('tasks.workspace_receipt_invalid', self.phase('receipt', ok=False))
        (self.scratch / 'receipt.json').unlink()
        (self.scratch / 'receipt.json').symlink_to(self.checkout / 'base.txt')
        self.assertIn('tasks.workspace_receipt_invalid', self.phase('receipt', ok=False))

    def test_immutable_after_evidence_and_discard_leave_checkout_unchanged(self):
        self.prepare()
        (self.checkout / 'base.txt').write_text('turn edit\n')
        (self.checkout / 'link').unlink()
        (self.checkout / 'link').symlink_to('staged.txt')
        (self.checkout / ':(glob)*.txt').write_text('literal filename\n')
        self.git('add', '--all')
        index = (self.checkout / '.git/index').read_bytes()
        after, output = self.phase('after')
        self.assertEqual(after['before_digest'], self.intent['before_digest'])
        self.assertEqual(self.git('show', after['tree'] + ':base.txt'), 'turn edit')
        self.assertEqual(self.git('show', after['tree'] + ':link'), 'staged.txt')
        self.assertEqual(self.git('show', after['tree'] + '::(glob)*.txt'), 'literal filename')
        self.assertIn('changed=0', output)
        (self.checkout / 'base.txt').write_text('later user edit\n')
        status = self.git('status', '--porcelain=v1', '-uall')
        repeated, output = self.phase('after')
        self.assertEqual(repeated, after)
        self.assertIn('changed=0', output)
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        for _ in range(2):
            value, output = self.phase('discard')
            self.assertEqual(value, {'discarded': True, 'changed': False})
            self.assertIn('changed=0', output)
            self.assertFalse(self.scratch.exists())
            self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
            self.assertEqual(self.git('status', '--porcelain=v1', '-uall'), status)
            self.assertEqual((self.checkout / 'base.txt').read_text(), 'later user edit\n')
            self.assertEqual((self.checkout / 'ignored/user.txt').read_text(), 'preserved ignored data\n')

    def test_snapshot_and_receipt_refuse_wrong_digest_or_candidate_without_checkout_writes(self):
        self.prepare()
        index = (self.checkout / '.git/index').read_bytes()
        status = self.git('status', '--porcelain=v1', '-uall')
        wrong = {**self.intent, 'before_digest': '0' * 64}
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('after', wrong, ok=False))
        self.assertFalse((self.scratch / 'after.json').exists())
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('receipt', wrong, ok=False))
        wrong = {**self.intent, 'candidate': {**self.intent['candidate'], 'head': '0' * 40}}
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('receipt', wrong, ok=False))
        self.assertEqual((self.checkout / '.git/index').read_bytes(), index)
        self.assertEqual(self.git('status', '--porcelain=v1', '-uall'), status)

    def test_refuses_missing_invalid_project_and_changed_checkout_identity(self):
        for runtime in [{'name': 'proof-instance'}, {'project': '', 'name': 'proof-instance'},
                        {'project': '../wrong', 'name': 'proof-instance'}]:
            self.assertIn('tasks.workspace_target_invalid', self.phase('preflight', {**self.intent, 'runtime': runtime}, ok=False))
        self.assertFalse(self.calls.exists(), 'invalid runtime contacted the transport')
        self.prepare()
        (self.checkout / '.git').rename(self.checkout / '.git-original')
        shutil.copytree(self.checkout / '.git-original', self.checkout / '.git')
        self.assertIn('tasks.workspace_identity_changed', self.phase('after', ok=False))
        shutil.rmtree(self.checkout / '.git')
        (self.checkout / '.git-original').rename(self.checkout / '.git')
        self.git('checkout', '--quiet', '-b', 'wrong-branch')
        self.assertIn('tasks.workspace_identity_changed', self.phase('after', ok=False))

    def test_exact_branch_export_and_corrupt_transfer_cleanup(self):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Candidate')
        self.intent['head'] = self.git('rev-parse', 'HEAD')
        value, _ = self.phase('preflight')
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        self.intent['candidate'] = {'head': self.intent['head'], 'tree': self.git('rev-parse', 'HEAD^{tree}')}
        exported, _ = self.phase('export')
        node = Path(exported['node_scratch'])
        self.addCleanup(shutil.rmtree, node, True)
        bundle = Path(exported['bundle'])
        self.assertEqual(hashlib.sha256(bundle.read_bytes()).hexdigest(), exported['sha256'])
        imported = self.root / 'imported'
        imported.mkdir()
        self.git('init', '--quiet', '--bare', cwd=imported)
        self.git('fetch', '--quiet', str(bundle), 'refs/heads/proof-branch:refs/heads/proof-branch', cwd=imported)
        self.assertEqual(self.git('rev-parse', 'proof-branch', cwd=imported), self.intent['head'])
        self.assertEqual(self.git('rev-parse', 'proof-branch^{tree}', cwd=imported), self.intent['candidate']['tree'])
        self.assertFalse((self.scratch / 'export.bundle').exists())
        self.assertIn('tasks.workspace_phase_failed', self.phase('export', ok=False, corrupt=True))
        self.assertFalse((self.scratch / 'export.bundle').exists())
        transfers = [json.loads(line) for line in self.calls.read_text().splitlines() if json.loads(line)[2:4] == ['file', 'pull']]
        self.assertFalse(Path(transfers[-1][-1]).parent.exists(), 'partial Node scratch leaked')
        bad = copy.deepcopy(self.intent)
        bad['candidate']['tree'] = '0' * 40
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('export', bad, ok=False))


if __name__ == '__main__':
    unittest.main()
