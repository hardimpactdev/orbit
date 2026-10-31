"""Run the actual inline host programs on a disposable filesystem, never a fleet host."""
import hashlib
import json
import os
import pwd
import re
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / 'workflows/workflow-node-worker-upgrade.yaml'


class WorkerUpgradeStepsTest(unittest.TestCase):
    def setUp(self):
        self.workflow = yaml.safe_load(WORKFLOW.read_text())
        self.steps = {step['name']: step for step in self.workflow['jobs'][0]['steps']}
        self.temp = tempfile.TemporaryDirectory(prefix='worker-upgrade-fixture-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.user = pwd.getpwuid(os.getuid()).pw_name
        self.old = b'old worker artifact fixture\n'
        self.new = b'new official artifact fixture\n'
        self.sha = lambda data: hashlib.sha256(data).hexdigest()
        self.unit = b'unchanged worker unit fixture\n'
        self.inputs = {
            'node_id': 14, 'user': self.user,
            'artifact': {'version': '20261031.063736.0-sha.e459d219',
                         'sha256': self.sha(self.new), 'bytes': len(self.new)},
            'expected_current_sha256': self.sha(self.old),
            'expected_unit_sha256': self.sha(self.unit),
            'worker_units': ['orbit-node-worker.service'],
        }
        self.env = dict(os.environ, NODE_ID='14', WORKER_USER=self.user,
                        ARTIFACT_VERSION=self.inputs['artifact']['version'],
                        ARTIFACT_SHA256=self.sha(self.new), ARTIFACT_BYTES=str(len(self.new)),
                        EXPECTED_SHA256=self.sha(self.old), EXPECTED_UNIT_SHA256=self.sha(self.unit),
                        UNIT_NAMES='orbit-node-worker.service', SUDO_UID=str(os.getuid()))
        self.binary = self.file('/usr/local/bin/swamp', self.old, 0o755)
        self.file('/etc/systemd/system/orbit-node-worker.service', self.unit, 0o644)
        self.file('/etc/orbit/agent/config.toml',
                  f'[swamp_worker]\nnode_id = 14\nuser = "{self.user}"\n'.encode(), 0o644)
        self.file('/etc/orbit/swamp-worker/enrollment.token', b'preserved-enrollment', 0o600)
        self.file('/etc/orbit/swamp-worker/server.token', b'preserved-server', 0o600)
        self.path('/etc/orbit/swamp-worker').chmod(0o700)
        self.file('/proc/meminfo', b'MemAvailable: 9999999 kB\n')
        self.file('/proc/caller/cgroup', b'0::/system.slice/orbit-node-worker.service\n')
        self.path('/proc/1234').mkdir(parents=True)
        self.path('/proc/1234/exe').symlink_to(self.binary)
        self.systemctl = self.file('/fixture-systemctl', b'#!/bin/sh\ncase "$*" in\n*MainPID*) printf "1234\\n" ;;\n*show*)\n printf "UnitFileState=enabled\\nActiveState=active\\nSubState=running\\nUser=%s\\nFragmentPath=%s/etc/systemd/system/%s\\nDropInPaths=\\n" "$WORKER_USER" "$FIXTURE_ROOT" "$2" ;;\n*restart*) printf "%s\\n" "$*" >> "$FIXTURE_ROOT/restarts" ;;\n*) exit 1 ;;\nesac\n', 0o755)
        self.curl = self.file('/fixture-curl', b'#!/bin/sh\nset -eu\nwhile [ "$1" != "--output" ]; do shift; done\nshift\ncp "$FIXTURE_ROOT/download" "$1"\n', 0o755)
        self.file('/download', self.new)
        self.env['FIXTURE_ROOT'] = str(self.root)

    def path(self, path):
        return self.root / path.lstrip('/')

    def file(self, path, data, mode=0o644):
        path = self.path(path)
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        path.chmod(mode)
        return path

    def fixture_script(self, name):
        # Replace only destinations, executable fixtures and root uid/gid checks in
        # this disposable source copy. No installed file or fleet endpoint is used.
        script = self.steps[name]['task']['inputs']['run']
        script = script.replace('sudo -n ', '')
        script = script.replace('/usr/bin/systemctl', str(self.systemctl))
        script = script.replace('/usr/bin/curl', str(self.curl))
        script = re.sub(r"/(?:etc|proc|usr/local)(?=/|['\"])|/usr(?=['\"])",
                        lambda match: str(self.path(match.group())), script)
        script = script.replace('str(os.getppid())', "'caller'")
        script = script.replace('info.st_uid != 0', 'info.st_uid != os.getuid()')
        script = script.replace('info.st_gid != 0', 'info.st_gid != os.getgid()')
        return script

    def run_step(self, name, success=True, stage=None):
        script = self.fixture_script(name)
        env = self.env | ({'STAGE_RAW': stage} if stage else {})
        p = subprocess.run(['/bin/sh', '-c', script], check=False, env=env, text=True,
                           capture_output=True, timeout=12)
        self.assertEqual(p.returncode == 0, success, p.stdout + p.stderr)
        return p

    def test_verified_publication_changes_inode_preserves_credentials_and_unit(self):
        before = self.binary.stat().st_ino
        tokens = [self.path('/etc/orbit/swamp-worker/' + name + '.token')
                  for name in ('enrollment', 'server')]
        snapshots = [(path.read_bytes(), path.stat()) for path in tokens]
        self.assertEqual(self.run_step('inspect').stdout, self.sha(self.old))
        stage = self.run_step('stage').stdout
        for name in ('download', 'verify-artifact', 'executable-mode', 'publish'):
            self.run_step(name, stage=stage)
        self.assertEqual(self.binary.read_bytes(), self.new)
        self.assertNotEqual(self.binary.stat().st_ino, before)
        self.run_step('verify-installed')
        self.run_step('cleanup-stage', stage=stage)
        self.assertFalse(Path(stage.strip()).exists())
        self.env['UNIT_NAME'] = 'orbit-node-worker.service'
        self.run_step('restart')
        self.assertEqual((self.root / 'restarts').read_text(),
                         'restart --no-block orbit-node-worker.service\n')
        for path, snapshot in zip(tokens, snapshots):
            self.assertEqual((path.read_bytes(), path.stat()), snapshot)
        self.assertEqual(self.path('/etc/systemd/system/orbit-node-worker.service').read_bytes(), self.unit)
        self.env['BEFORE_SHA256'] = self.sha(self.old)
        result = json.loads(self.run_step('result').stdout)
        self.assertTrue(result['changed'])
        self.assertFalse(result['reconnection_proven'])

    def test_foreign_node_proxy_and_user_refuse_before_staging(self):
        for patch_env in ({'NODE_ID': '1'}, {'NODE_ID': '18'}, {'NODE_ID': '9'},
                          {'WORKER_USER': 'root'}, {'SUDO_UID': '0'}):
            with self.subTest(patch_env=patch_env), patch.dict(self.env, patch_env):
                self.run_step('inspect', success=False)
        self.assertEqual(self.binary.read_bytes(), self.old)
        self.assertFalse(list(self.path('/usr/local/bin').glob('.orbit-swamp-*')))

    def test_bad_custody_and_unit_digest_refuse(self):
        token = self.path('/etc/orbit/swamp-worker/enrollment.token')
        token.chmod(0o644)
        self.run_step('inspect', success=False)
        token.chmod(0o600)
        self.env['EXPECTED_UNIT_SHA256'] = '0' * 64
        self.run_step('inspect', success=False)

    def test_bad_artifact_or_drift_never_replaces_current_binary(self):
        self.run_step('inspect')
        stage = self.run_step('stage').stdout
        self.run_step('download', stage=stage)
        with patch.dict(self.env, ARTIFACT_SHA256='0' * 64):
            self.run_step('verify-artifact', success=False, stage=stage)
        self.assertEqual(self.binary.read_bytes(), self.old)
        self.run_step('verify-artifact', stage=stage)
        self.run_step('executable-mode', stage=stage)
        self.binary.write_bytes(b'concurrent change')
        self.run_step('publish', success=False, stage=stage)
        self.assertEqual(self.binary.read_bytes(), b'concurrent change')

    def test_publication_ack_loss_can_resume_without_the_moved_stage_file(self):
        stage = self.run_step('stage').stdout
        for name in ('download', 'verify-artifact', 'executable-mode', 'publish'):
            self.run_step(name, stage=stage)
        inode = self.binary.stat().st_ino
        self.assertFalse((Path(stage.strip()) / 'swamp').exists())
        self.run_step('publish', stage=stage)
        self.assertEqual(self.binary.stat().st_ino, inode)
        self.assertEqual(self.binary.read_bytes(), self.new)

    def test_restart_rejects_foreign_units(self):
        self.env['UNIT_NAME'] = 'orbit-agent.service'
        self.run_step('restart', success=False)
        self.assertFalse((self.root / 'restarts').exists())

    def test_public_input_has_no_secrets_or_arbitrary_repository(self):
        schema = self.workflow['inputs']
        self.assertFalse(schema['additionalProperties'])
        self.assertEqual(set(schema['properties']), set(self.inputs))
        download = self.steps['download']['task']['inputs']['run']
        self.assertIn('https://github.com/swamp-club/swamp/releases/download/', download)
        self.assertNotIn('inputs.artifact.url', WORKFLOW.read_text())
        self.assertEqual(self.workflow['vaults'], [])

    def test_every_action_is_placed_and_linted(self):
        for step in self.steps.values():
            with self.subTest(step=step['name']):
                self.assertIn('inputs.node_id', step['target'])
                self.assertIn('inputs.node_id', step['labels']['node'])
                inputs = step['task']['inputs']
                self.assertEqual(step['task']['behaviorType'], 'command/shell')
                self.assertEqual(step['task']['methodName'], 'execute')
                r = subprocess.run(['shellcheck', '-s', 'sh', '-'], check=False, input=inputs['run'],
                                   capture_output=True, text=True, timeout=10)
                self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
                for program in re.findall(r"<<'PYTHON'\n(.*?)\nPYTHON", inputs['run'], re.DOTALL):
                    compile(program, '<inline-action>', 'exec')
        self.assertNotIn('queueTimeout', self.workflow)
        self.assertNotIn('GatewayOperation', WORKFLOW.read_text())
        self.assertNotIn('orbit-agent', WORKFLOW.read_text())


if __name__ == '__main__':
    unittest.main()
