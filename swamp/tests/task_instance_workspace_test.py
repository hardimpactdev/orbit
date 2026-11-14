"""Real workspace proof; independent file with the shared, test-free harness."""
import json
import os
import runpy
import shutil
import subprocess
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))
COMMAND = SUPPORT["COMMAND"]
FIXTURE = SUPPORT["FIXTURE"]
ROOT = SUPPORT["ROOT"]


class TaskInstanceWorkspaceTest(SUPPORT["WorkspaceHarness"]):
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


if __name__ == "__main__":
    unittest.main()
