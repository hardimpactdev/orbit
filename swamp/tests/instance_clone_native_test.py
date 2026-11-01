"""Exercise the real native clone DAG with local Git/SSH fixtures and captured environment."""
import json
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class InstanceCloneNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.copy_behaviors('operations/instance-source-command', 'operations/instance-lifecycle', 'operations/tools')
        self.candidate = self.repo / 'candidate'
        self.candidate.mkdir()
        self.git('init', '-q')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('remote', 'add', 'origin', 'https://github.com/example/project.git')
        (self.candidate / '.gitignore').write_text('.env\n')
        (self.candidate / 'tracked').write_text('committed\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'fixture')
        self.git('branch', '-M', 'main')
        self.target = self.repo / 'apps/project/clone'
        ssh = self.repo / 'ssh'
        ssh.write_text('#!' + sys.executable + '\n' + '''import os,shlex,subprocess,sys
argv=shlex.split(sys.argv[-1])
if 'fetch.py' in ' '.join(argv):
    argv[-1]=os.environ['FIXTURE_REPOSITORY']
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
''')
        ssh.chmod(0o755)
        scp = self.repo / 'scp'
        scp.write_text('#!' + sys.executable + '\n' + '''import shlex,shutil,sys
from pathlib import Path
paths=[]
for value in sys.argv[-2:]:
    if '@' in value and ':' in value:value=shlex.split(value.split(':',1)[1])[0]
    paths.append(value)
source,destination=map(Path,paths)
if source.is_dir():shutil.copytree(source,destination/source.name,dirs_exist_ok=True)
else:shutil.copyfile(source,destination)
''')
        scp.chmod(0o755)
        self.native_env['FIXTURE_REPOSITORY'] = str(self.candidate)
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-clone.yaml').read_text())
        for job in self.workflow['jobs']:
            self.assertEqual(job.pop('target'), 'orbit-node-209')
            for step in job['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                task = step['task']
                if task.get('behaviorType') == '@swamp/ssh':
                    task['globalArgs'].update(sshBinary=str(ssh), scpBinary=str(scp))
                if step['name'] == 'mint':
                    task['inputs'] = {'run': 'true'}  # Existing mint primitive has separate authorization tests.
                if step['name'] == 'source-fetch':
                    task['inputs']['stdin'] = '{}'
                if step['name'] == 'environment':
                    task['inputs']['stdin'] = json.dumps({'content': 'PRIVATE_FIXTURE=retained\n', 'keys': ['PRIVATE_FIXTURE']})
        self.workflow_path = self.repo / 'workflows/workflow-operation-instance-clone.yaml'
        self.workflow_path.write_text(yaml.safe_dump(self.workflow, sort_keys=False))
        connection = {'identity_file': '/fixture/key', 'target': {'host': '192.0.2.9', 'port': 22, 'user': 'orbit',
            'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
        self.intent = {'instance_id': 8, 'project_id': 9, 'ssh_execution': connection,
            'source': {'checkout': str(self.target), 'root': str(self.repo / 'apps'), 'repository': 'https://github.com/example/project.git',
                'prepare_id': '4a2ae14b-93e3-4b16-97df-35f2e33e6027', 'seed_repository': None},
            'resolve': {'instance_name': 'clone', 'default_branch': 'main', 'branch_override': 'main', 'require_branch': True, 'seed_commit': None, 'network': True},
            'application': str(self.target), 'project_type': 'monorepo', 'php_versions': ['8.5'], 'skip_profile': True, 'retained_source': None,
            'environment_path': str(self.target), 'execution_references_json': '{}',
            'candidate': {'checkout': str(self.candidate), 'repository_identity': 'github.com/example/project', 'branch': 'main', 'ssh_execution': connection}}

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.candidate), *args], check=True, capture_output=True).stdout.decode().strip()

    def run_clone(self, success=True):
        argv = ['workflow', 'run', 'operation-instance-clone']
        for key, value in {'operation_id': 7004, 'node_id': 9, 'kind': 'instance.clone', 'intent': self.intent,
                           'execution_secrets': {'git_read': {'vault': 'orbit-control-inputs', 'key': 'git'},
                               'environment': {'vault': 'orbit-control-inputs', 'key': 'environment'}}}.items():
            argv += ['--input', key + '=' + (value if isinstance(value, str) else json.dumps(value))]
        run = json.loads(self.cli(*argv, success=success).stdout)
        return run, json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def test_clone_reads_candidate_and_prepares_independent_source_and_environment(self):
        run, history = self.run_clone()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual((self.target / 'tracked').read_text(), 'committed\n')
        self.assertEqual((self.target / '.env').read_text(), 'PRIVATE_FIXTURE=retained\n')
        self.assertEqual((self.target / '.env').stat().st_mode & 0o777, 0o640)
        self.assertTrue((self.target / '.git').is_dir())
        result = json.loads(history['jobs'][0]['steps'][-1]['outputs']['stdout'])
        self.assertEqual(result['commit'], self.git('rev-parse', 'HEAD'))
        self.assertEqual(result['candidate_commit'], result['commit'])
        self.assertTrue(result['ready'])
        self.assertFalse((self.candidate / '.env').exists())

    def test_missing_requested_branch_refuses_without_falling_back_or_writing_environment(self):
        self.intent['resolve']['branch_override'] = 'missing-branch'
        run, history = self.run_clone(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertFalse((self.target / '.env').exists())
        self.assertEqual(history['jobs'][0]['steps'][-1]['status'], 'skipped')

    def test_shared_source_builder_prepares_linked_worktree_without_replacing_seed(self):
        self.intent["source"]["seed_repository"] = str(self.candidate)
        self.intent["resolve"].update(seed_commit=self.git("rev-parse", "HEAD"), branch_override="feature", require_branch=False, network=False)
        run, _history = self.run_clone()
        self.assertEqual(run["status"], "succeeded")
        self.assertTrue((self.target / ".git").is_file())
        self.assertTrue((self.candidate / ".git").is_dir())
        self.assertEqual(self.git("branch", "--show-current"), "main")
