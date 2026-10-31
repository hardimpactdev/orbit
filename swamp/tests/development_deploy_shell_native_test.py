"""Execute the real shell DAG against a disposable Git checkout and native vault."""
import hashlib
import json
import os
from pathlib import Path
import pwd
import shutil
import subprocess
import tempfile
import unittest

import yaml

from native_harness import ROOT, NativeTestCase


def reply(raw):
    for index, character in enumerate(raw):
        if character in '{[':
            try:
                return json.loads(raw[index:])
            except ValueError:
                pass
    raise AssertionError('Native JSON missing')


class DevelopmentDeployShellNativeTest(NativeTestCase):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/instance-deploy')
        # Only the pure public schema dependencies ship in the new dev DAG.
        source = self.repo / 'extensions/behaviors/operations/instance-deploy'
        keep = {'development_data.ts', 'vp_requirements.ts'}
        for path in source.iterdir():
            if path.name not in keep:
                if path.is_dir():
                    shutil.rmtree(path)
                else:
                    path.unlink()
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        # This local execution fixture keeps the exact action text and dependencies.
        # Remote placement is covered by the shared worker transport fixture.
        workflow['jobs'][0]['steps'] = [step for step in workflow['jobs'][0]['steps'] if step['name'] != 'production']
        for step in workflow['jobs'][0]['steps']:
            step.pop('labels', None)
        (self.repo / 'workflows/workflow-operation-instance-deploy-direct.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.target_temp = tempfile.TemporaryDirectory(prefix='dev-target-', dir=os.environ.get('TMPDIR'))
        self.addCleanup(self.target_temp.cleanup)
        target = Path(self.target_temp.name)
        self.origin, self.checkout = target / 'origin', target / 'checkout'
        self.git('init', '-q', '--initial-branch=main', str(self.origin))
        self.git('-C', str(self.origin), 'config', 'user.name', 'Fixture')
        self.git('-C', str(self.origin), 'config', 'user.email', 'fixture@example.test')
        (self.origin / 'tracked').write_text('first\n')
        self.git('-C', str(self.origin), 'add', 'tracked')
        self.git('-C', str(self.origin), 'commit', '-qm', 'first')
        self.git('clone', '-q', str(self.origin), str(self.checkout))
        (self.origin / 'tracked').write_text('second\n')
        self.git('-C', str(self.origin), 'commit', '-qam', 'second')
        self.commit = self.git('-C', str(self.origin), 'rev-parse', 'HEAD').strip()
        self.account = pwd.getpwuid(os.getuid()).pw_name
        self.cli('vault', 'create', 'local_encryption', 'orbit-control-inputs')
        self.cli('vault', 'put', 'orbit-control-inputs', 'git-read', input=json.dumps({'github_token': None}))

    def git(self, *args):
        return subprocess.check_output(['git', *args], text=True, stderr=subprocess.PIPE)

    def run_commands(self, scripts, required=None, success=True, timeout=30, protocols=None, variant="development", needs_vp=False):
        required = required or [True] * len(scripts)
        commands = []
        for index, script in enumerate(scripts):
            key = 'command-' + str(index)
            self.cli('vault', 'put', 'orbit-control-inputs', key, input=script)
            commands.append({'name': 'command-' + str(index), 'phase': 'before_activation',
                'order': index, 'step_name': 'application-' + str(index), 'required': required[index],
                'timeout_seconds': timeout, 'command_digest': hashlib.sha256(script.encode()).hexdigest(),
                'program_field': 'command_' + str(index)})
        inputs = {'operation_id': 991, 'kind': 'instance.deploy', 'instance_id': 315,
            'project_id': 46, 'node_id': 9, 'commit': self.commit,
            'deployment': {'variant': variant, 'node_user': self.account,
                'home': str(self.checkout), 'repository': str(self.origin), 'branch': 'main',
                'protocols': ['file'] if protocols is None else protocols, 'needs_vp': needs_vp, 'steps': commands},
            'execution_secrets': {'git_read': {'vault': 'orbit-control-inputs', 'key': 'git-read'},
                **{'command_' + str(i): {'vault': 'orbit-control-inputs', 'key': 'command-' + str(i)} for i in range(len(scripts))}}}
        inputs['intent'] = {key: inputs.pop(key) for key in ['instance_id', 'project_id', 'commit', 'deployment']}
        outcome = reply(self.cli('workflow', 'run', 'operation-instance-deploy-direct',
            '--input', json.dumps(inputs), success=success).stdout)
        history = reply(self.cli('workflow', 'history', 'get', outcome['id']).stdout)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        return history, steps

    def test_deploys_exact_commit_and_runs_commands_in_order_without_gateway(self):
        history, steps = self.run_commands(['printf first > order', 'test "$(cat order)" = first; printf second >> order'])
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual((self.checkout / 'order').read_text(), 'firstsecond')
        result = json.loads(steps['result']['outputs']['stdout'])
        self.assertEqual(result['commit'], self.commit)
        self.assertIsNone(result['failed_step'])
        self.assertEqual(self.git('-C', str(self.checkout), 'rev-parse', 'HEAD').strip(), self.commit)

    def test_required_failure_skips_later_effects_and_retains_public_result(self):
        history, steps = self.run_commands(['exit 7', 'touch should-not-run'], success=False)
        self.assertEqual(history['status'], 'failed')
        self.assertFalse((self.checkout / 'should-not-run').exists())
        self.assertEqual(steps['application-1']['status'], 'skipped')
        result = json.loads(steps['result']['outputs']['stdout'])
        self.assertEqual(result['failed_step'], 'before_activation.command-0')

    def test_optional_failure_allows_later_command_and_counts_warning(self):
        history, steps = self.run_commands(['exit 9', 'touch ran'], required=[False, True])
        self.assertEqual(history['status'], 'succeeded')
        self.assertTrue((self.checkout / 'ran').exists())
        self.assertEqual(json.loads(steps['result']['outputs']['stdout'])['warnings'], 1)

    def test_decodes_managed_dollar_escape_and_redacts_multiline_environment(self):
        secret = 'synthetic-private-' + os.urandom(16).hex()
        self.secrets = (secret,)
        (self.checkout / '.env').write_text('DOLLAR="a\\$b"\nMULTILINE="' + secret + '\\nother-line"\n')
        history, steps = self.run_commands(['test "$DOLLAR" = \'a$b\'; printf "%s\\n" "$MULTILINE"'])
        self.assertEqual(history['status'], 'succeeded')
        result = json.loads(steps['result']['outputs']['stdout'])
        self.assertNotIn(secret, json.dumps(history))
        self.assertIsNone(result['failed_step'])

    def test_verifies_checkout_after_commands_change_branch(self):
        history, steps = self.run_commands(['git checkout -qb unexpected'], success=False)
        self.assertEqual(history['status'], 'failed')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout'])['failed_step'], 'verify-checkout')

    def test_refuses_symlink_environment_without_running_application(self):
        target = self.checkout.parent / 'foreign-env'
        target.write_text('SAFE="value"\n')
        (self.checkout / '.env').symlink_to(target)
        history, steps = self.run_commands(['touch should-not-run'], success=False)
        self.assertFalse((self.checkout / 'should-not-run').exists())
        self.assertEqual(json.loads(steps['result']['outputs']['stdout'])['failed_step'], 'before_activation.command-0')

    def test_refuses_writable_environment_without_running_application(self):
        target = self.checkout / '.env'
        target.write_text('SAFE="value"\n')
        target.chmod(0o666)
        history, steps = self.run_commands(['touch should-not-run'], success=False)
        self.assertFalse((self.checkout / 'should-not-run').exists())
        self.assertEqual(json.loads(steps['result']['outputs']['stdout'])['failed_step'], 'before_activation.command-0')

    def test_discards_incomplete_secret_output_and_bounds_noisy_output(self):
        secret = 'private-' + os.urandom(16).hex()
        self.secrets = (secret,)
        (self.checkout / '.env').write_text('PRIVATE="' + secret + '"\n')
        history, steps = self.run_commands(['head -c 1200000 /dev/zero | tr "\\0" x; printf "\\n%s" "$PRIVATE"'])
        output = json.loads(steps['application-0']['outputs']['stdout'])
        self.assertTrue(output['truncated'])
        self.assertLessEqual(len(output['output_tail'].encode()), 2048)
        self.assertNotIn(secret, json.dumps(history))

    def test_supports_an_empty_command_list(self):
        history, steps = self.run_commands([])
        self.assertEqual(history['status'], 'succeeded')
        self.assertIsNone(json.loads(steps['result']['outputs']['stdout'])['failed_step'])

    def test_native_timeout_prevents_later_commands(self):
        history, steps = self.run_commands(['sleep 10', 'touch should-not-run'], success=False, timeout=1)
        self.assertEqual(history['status'], 'failed')
        self.assertFalse((self.checkout / 'should-not-run').exists())

    def test_refuses_protocol_outside_admitted_allowlist(self):
        history, steps = self.run_commands(['touch should-not-run'], success=False, protocols=['https'])
        self.assertFalse((self.checkout / 'should-not-run').exists())
        self.assertEqual(json.loads(steps['result']['outputs']['stdout'])['failed_step'], 'fetch-source')

    def test_production_reports_unavailable_without_node_effects(self):
        before = self.git('-C', str(self.checkout), 'rev-parse', 'HEAD')
        history, steps = self.run_commands(['touch should-not-run'], success=False, variant='production')
        self.assertFalse((self.checkout / 'should-not-run').exists())
        self.assertEqual(before, self.git('-C', str(self.checkout), 'rev-parse', 'HEAD'))
        self.assertEqual(json.loads(steps['result']['outputs']['stdout'])['error_code'], 'operation.kind_unavailable')

    def test_never_executes_repository_fsmonitor_during_git_inspection(self):
        sentinel = self.checkout.parent / 'unsafe-fsmonitor'
        self.git('-C', str(self.checkout), 'config', 'core.fsmonitor', 'touch ' + str(sentinel))
        history, steps = self.run_commands([])
        self.assertEqual(history['status'], 'succeeded')
        self.assertFalse(sentinel.exists())

    def test_does_not_read_incidental_vite_manifest_without_admitted_need(self):
        (self.checkout / 'package.json').write_text('{"devDependencies":{"vite-plus":"*"}}')
        history, steps = self.run_commands([])
        self.assertEqual(history['status'], 'succeeded')

    def test_vp_wrapper_can_close_inherited_descriptors_without_losing_command(self):
        store = self.checkout.parent / 'vp-store'
        (store / 'bin').mkdir(parents=True)
        wrapper = store / 'bin/vp'
        wrapper.write_text('#!/usr/bin/python3\nimport os,sys\ncommand=sys.argv[sys.argv.index("--")+1:]\nos.closerange(3,256)\nos.execvp(command[0],command)\n')
        wrapper.chmod(0o755)
        script = 'printf verified > fd-proof'
        self.cli('vault', 'put', 'orbit-control-inputs', 'command-0', input=script)
        source = yaml.safe_load((ROOT / 'workflows/workflow-operation-instance-deploy-direct.yaml').read_text())
        task = next(step['task'] for step in source['jobs'][0]['steps'] if step['name'] == 'application')
        task['behaviorName'] = 'fixture-fd-runner'
        task['inputs']['timeout'] = 30000
        task['inputs']['env'] = {'CHECKOUT': str(self.checkout), 'ACCOUNT': self.account,
            'EXPECTED_DIGEST': hashlib.sha256(script.encode()).hexdigest(),
            'ADMITTED_COMMAND': "${{ vault.get('orbit-control-inputs', 'command-0') }}",
            'VP_HOME': str(store), 'NODE_VERSION': '24.22.0', 'PACKAGE_MANAGER': 'npm@11.12.0',
            'SOURCE': json.dumps({'commit': self.commit})}
        workflow = {'id': 'cc7307fe-6845-460f-b75a-4c41af5e032a', 'name': 'fixture-fd-runner',
            'vaults': ['orbit-control-inputs'], 'jobs': [{'name': 'application', 'steps': [{'name': 'run', 'task': task}]}]}
        (self.repo / 'workflows/workflow-fixture-fd-runner.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        history = reply(self.cli('workflow', 'run', 'fixture-fd-runner').stdout)
        self.assertEqual(history['status'], 'succeeded')
        self.assertEqual((self.checkout / 'fd-proof').read_text(), 'verified')


if __name__ == '__main__':
    unittest.main()
