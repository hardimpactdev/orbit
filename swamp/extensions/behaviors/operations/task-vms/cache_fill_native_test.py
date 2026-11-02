"""Actual native cache-fill DAG with unmodified official SSH from 209 and vault-backed agent requests.

The fake `ssh` answers like the pinned agent's package-cache.host on the host. No host or fleet mutation.
"""
import json
import re
import shutil
import sys
import unittest
import uuid
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase

WORKFLOW = 'operation-task-vm-cache-fill'
HASH = 'sha256-' + 'a' * 64

# Records each call without the token, then answers one compact agent line in the agent's field order.
FAKE_SSH = r'''
import json, os, shlex, sys
from pathlib import Path
if '-M' in sys.argv or '-O' in sys.argv:
    sys.exit(0)
argv = shlex.split(sys.argv[-1])
body = sys.stdin.read()
call = {'argv': argv}
if body:
    request = json.loads(body)
    call['token_matches'] = (request['input'].pop('github_token') or '') == os.environ['FIXTURE_TOKEN']
    call['stdin'] = request
with Path(CALLS).open('a') as log:
    log.write(json.dumps(call) + '\n')
refuse = os.environ.get('FIXTURE_REFUSE', '')
hashes, failures = {}, {}
for artifact in call['stdin']['input']['artifacts']:
    if artifact['key'] == refuse:
        failures[artifact['key']] = 'Archive checksum mismatch'
    else:
        hashes[artifact['key']] = HASH
print(json.dumps({'ok': True, 'data': {'hashes': hashes, 'failures': failures}}, separators=(',', ':')))
'''


class CacheFillNative(NativeTestCase):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.token = 'ghs_private_' + uuid.uuid4().hex
        self.secrets = (self.token,)
        self.native_env.update(FIXTURE_TOKEN=self.token)
        package = ROOT / '.swamp/pulled-extensions/@swamp/ssh'
        if not package.is_dir():
            raise RuntimeError('Pull the pinned official @swamp/ssh package before this test.')
        destination = self.repo / '.swamp/pulled-extensions/@swamp/ssh'
        destination.parent.mkdir(parents=True)
        shutil.copytree(package, destination)
        inventory = json.loads((ROOT / 'extensions/models/upstream_extensions.json').read_text())
        index = self.repo / 'extensions/models/upstream_extensions.json'
        index.parent.mkdir(parents=True)
        index.write_text(json.dumps({'@swamp/ssh': inventory['@swamp/ssh']}))
        self.calls = self.repo / 'ssh-calls.jsonl'
        identity = self.repo / 'existing-key'
        identity.write_text('fixture identity: not used to authenticate')
        identity.chmod(0o600)
        fake = self.repo / 'fixture-ssh'
        fake.write_text('#!' + sys.executable + '\n' + FAKE_SSH.replace('CALLS', repr(str(self.calls))).replace('HASH', repr(HASH)))
        fake.chmod(0o700)
        workflow = yaml.safe_load((ROOT / 'workflows' / (f'workflow-{WORKFLOW}.yaml')).read_text())
        self.assertEqual([job['name'] for job in workflow['jobs']], ['fill'])
        for job in workflow['jobs']:
            self.assertNotIn('labels', job)
            for step in job['steps']:
                # Every step runs on a role=swamp worker on 209; no per-Node worker label.
                self.assertEqual(step.pop('labels'), {'role': 'swamp'}, step['name'])
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fake)
                    step['task']['globalArgs']['transport']['identityFile'] = str(identity)
        (self.repo / 'workflows' / f'workflow-{WORKFLOW}.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.batches = [[self.artifact('composer/acme/demo/' + 'b' * 40 + '.zip')], [self.artifact('npm/' + 'c' * 64 + '.tgz')]]
        self.conflicts = {}

    @staticmethod
    def artifact(key):
        return {'manager': 'npm', 'package': 'demo', 'version': '1.0.0', 'url': 'https://registry.npmjs.org/demo/-/demo-1.0.0.tgz',
                'integrity': None, 'key': key}

    def run_fill(self, success=True):
        # As the Gateway admits it: public request bytes in the inputs, only the token's JSON literal in the vault.
        member = '"github_token":' + json.dumps(self.token or None)
        self.cli('vault', 'put', 'orbit-control-inputs', 'task-vm-cache-fill-github-13', '--force', input=member)
        secrets = {'github_token': {'vault': 'orbit-control-inputs', 'key': 'task-vm-cache-fill-github-13'}}
        requests = [json.dumps({'agent_sha256': 'd' * 64, 'deadline_ms': 1500000, 'input': {
            'operation': 'fill', 'artifacts': artifacts, 'max_bytes': 1000000, 'github_token': None}}, separators=(',', ':'))[:-len('"github_token":null}}')]
            for artifacts in self.batches]
        ssh = {'control_node_id': 209, 'identity_file': '/etc/orbit/swamp-role-worker/ssh-identity', 'public_key': 'ssh-ed25519 PUBLIC',
               'target': {'name': 'target', 'host': '192.0.2.13', 'user': 'orbit', 'port': 22,
                          'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
        self.cli('workflow', 'validate', WORKFLOW)
        # A full batch exceeds one argv string, so the inputs go through a file as they go through a frame on serve.
        inputs = self.repo / 'inputs.json'
        inputs.write_text(json.dumps({
            'operation_id': 7, 'node_id': 13, 'kind': 'task_vm.fill_package_cache',
            'intent': {'ssh_execution': ssh, 'batches': list(range(len(requests))), 'requests': requests,
                       'request_end': '}}\n', 'conflicts': self.conflicts},
            'execution_secrets': secrets}))
        run = json.JSONDecoder().raw_decode(self.cli('workflow', 'run', WORKFLOW, '--input-file', str(inputs),
                                                     success=success).stdout.lstrip())[0]
        history = json.JSONDecoder().raw_decode(self.cli('workflow', 'history', 'get', run['id']).stdout.lstrip())[0]
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()] if self.calls.exists() else []
        self.assert_private_absent(json.dumps(history))
        return run, steps, calls

    def test_each_batch_is_one_pinned_agent_call_from_209_and_the_assert_passes(self):
        run, steps, calls = self.run_fill()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(steps['Check every archive']['status'], 'succeeded')
        self.assertEqual([call['argv'] for call in calls], [
            ['timeout', '1530', '/usr/bin/sudo', '-n', '--', '/usr/local/bin/orbit-agent', 'command', 'package-cache.host']] * 2)
        self.assertTrue(all(call['token_matches'] for call in calls))
        self.assertEqual([call['stdin']['input']['artifacts'] for call in calls], self.batches)
        # The Gateway reads each batch reply in full from the fill step's own stdout.
        reply = json.loads(steps['Fill the package cache-1']['outputs']['stdout'])
        self.assertEqual(reply['data']['hashes'], {self.batches[1][0]['key']: HASH})

    def test_a_refused_archive_fails_the_end_check_and_keeps_the_verified_replies(self):
        self.native_env.update(FIXTURE_REFUSE=self.batches[0][0]['key'])
        run, steps, _calls = self.run_fill(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(steps['Check every archive']['status'], 'failed')
        self.assertEqual(steps['Fill the package cache-0']['status'], 'succeeded')
        reply = json.loads(steps['Fill the package cache-0']['outputs']['stdout'])
        self.assertEqual(reply['data']['failures'], {self.batches[0][0]['key']: 'Archive checksum mismatch'})

    def test_a_full_batch_without_a_token_sends_null_from_the_small_vault_member(self):
        self.token = ''
        self.native_env.update(FIXTURE_TOKEN='')
        self.secrets = ()
        self.batches = [[self.artifact(f'npm/{index:064x}.tgz') for index in range(4096)]]
        run, _steps, calls = self.run_fill()
        self.assertEqual(run['status'], 'succeeded')
        self.assertEqual(len(calls[0]['stdin']['input']['artifacts']), 4096)
        self.assertTrue(calls[0]['token_matches'])

    def test_the_vault_is_read_only_for_the_github_token(self):
        # Serve redacts every vault value it reads with a RegExp built from the value; a whole agent request
        # as a vault value fails the step before SSH (Operation 8235). Only the small token may come from the vault.
        source = (ROOT / 'workflows' / f'workflow-{WORKFLOW}.yaml').read_text()
        reads = re.findall(r'vault\.get\(([^)]*)\)', source)
        self.assertEqual(reads, ['inputs.execution_secrets.github_token.vault, inputs.execution_secrets.github_token.key'])

    def test_an_admitted_integrity_conflict_fails_the_end_check(self):
        self.batches = [self.batches[0]]
        self.conflicts = {'npm/' + 'e' * 64 + '.tgz': 'IntegrityConflict'}
        run, steps, calls = self.run_fill(success=False)
        self.assertEqual(run['status'], 'failed')
        self.assertEqual(len(calls), 1)
        self.assertEqual(steps['Check every archive']['status'], 'failed')


if __name__ == '__main__':
    unittest.main()
