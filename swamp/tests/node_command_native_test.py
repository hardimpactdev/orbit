"""Official e459 remote-only dispatch and shipped SSH runner; harmless endpoint fixtures."""
import json
import os
import pwd
import secrets
import shutil
import socket
import subprocess
import sys
import time
import unittest
import urllib.request
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


def reply(raw):
    for index, char in enumerate(raw):
        if char in '{[':
            try:
                return json.loads(raw[index:])
            except ValueError:
                pass
    raise AssertionError('Missing native JSON')


class NodeCommandNativeTest(NativeTestCase):
    native_timeout = 120

    def test_official_remote_only_assets_identity_and_split_effects(self):
        self.copy_behaviors('operations/node-command')
        folder = self.repo / 'extensions/behaviors/operations/node-command/scripts'
        user = pwd.getpwuid(os.getuid()).pw_name
        fixture = self.repo / 'fixture'
        proxy = fixture / 'etc/orbit/node-proxy'
        proxy.mkdir(parents=True)
        (fixture / 'run').mkdir()
        (fixture / 'etc/orbit/agent').mkdir(mode=0o700)
        (fixture / 'cgroup').write_text('0::/system.slice/orbit-node-proxy-worker-1.service\n')
        (fixture / 'etc/orbit/agent/config.toml').write_text('[swamp_worker]\nnode_id = 209\nuser = "orbit"\n')
        contract = json.loads((folder / 'proxy_contract.json').read_text())
        target = contract['targets']['1']
        (proxy / '1.json').write_text(json.dumps({k: target[k] for k in ('node_id', 'host', 'user')}))
        (proxy / 'id_ed25519').write_text('unread fixture key')
        (proxy / 'id_ed25519').chmod(0o600)
        (proxy / 'known_hosts').write_text('\n'.join(t['host'] + ' ' + t['host_key'] for t in contract['targets'].values()) + '\n')
        ip = fixture / 'ip'
        ip.write_text('#!/bin/sh\nprintf \'%s\' \'[{"addr_info":[{"local":"10.44.0.1"}]}]\'\n')
        ip.chmod(0o755)
        unit = fixture / 'systemctl'
        unit.write_text('#!/bin/sh\ncase "$1" in is-enabled) printf "masked\\n" ;; *) printf "0\\n" ;; esac\n')
        unit.chmod(0o755)
        ssh = fixture / 'ssh'
        ssh.write_text('#!' + sys.executable + '\nimport json,os,sys\n'
                       'with open(' + repr(str(fixture / 'ssh-argv')) + ',"a") as f:f.write(json.dumps(sys.argv[1:])+"\\n")\n'
                       'os.execv(sys.executable,[sys.executable,"-I","-c",sys.argv[-1].split(" -c ",1)[1]])\n')
        # Parse the fixed remote command with shlex; do not execute its sudo/timeout
        # on the fixture host. The transmitted supervisor and frame remain real.
        ssh.write_text(ssh.read_text().replace('import json,os,sys', 'import json,os,sys,shlex')
                       .replace('sys.argv[-1].split(" -c ",1)[1]', 'shlex.split(sys.argv[-1])[-1]'))
        ssh.chmod(0o755)
        sender = folder / 'node_command.py'
        source = sender.read_text().replace("Path('/etc/orbit/node-proxy')", 'Path(' + repr(str(proxy)) + ')')
        source = source.replace("'/proc/self/cgroup'", repr(str(fixture / 'cgroup')))
        for path in ('/etc/orbit/agent/config.toml', '/etc/orbit/agent', '/etc/orbit', '/etc'):
            source = source.replace(repr(path), repr(str(fixture) + path))
        source = source.replace('info.st_uid != 0', 'info.st_uid != os.getuid()')
        source = source.replace('info.st_gid != 0', 'info.st_gid != os.getgid()')
        source = source.replace("['/usr/bin/sudo', '-n', '--', '/usr/bin/ssh',", '[' + repr(str(ssh)) + ',')
        # The fixture has no privileged host; invoke only the same fixed readonly
        # identity program directly. Production retains sudo and root custody.
        source = source.replace("['/usr/bin/sudo', '-n', '--', '/usr/bin/python3', '-I', '-c', IDENTITY_CHECK]",
                                "['/usr/bin/python3', '-I', '-c', IDENTITY_CHECK]")
        sender.write_text(source)
        remote = folder / 'proxy_remote.py'
        source = remote.read_text().replace('if os.geteuid() != 0:', 'if os.geteuid() != ' + str(os.getuid()) + ':')
        source = source.replace("'/usr/sbin/ip'", repr(str(ip))).replace("'/usr/bin/systemctl'", repr(str(unit)))
        source = source.replace("pwd.getpwnam(request['user'])", 'pwd.getpwuid(os.getuid())')
        source = source.replace("extra_groups=[] if request['as_root'] else os.getgrouplist(account.pw_name, account.pw_gid)", "extra_groups=None")
        remote.write_text(source)
        identity_script = folder / 'identity.py'
        source = identity_script.read_text().replace("['/usr/bin/sudo', '-n', '/usr/bin/id', '-u']", "['/usr/bin/printf', '0']")
        source = source.replace("pw_name != 'orbit'", "pw_name != " + repr(user))
        source = source.replace("'user': 'orbit'", "'user': " + repr(user))
        source = source.replace("print(json.dumps(", "assert os.environ['ORBIT_PRIVATE_VALUE']\nprint(json.dumps(", 1)
        identity_script.write_text(source)
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-node-proxy-canary.yaml').read_text())
        for step in workflow['jobs'][0]['steps']:
            inputs = step['task'].get('inputs', {})
            if 'run' in inputs:
                source = inputs['run'].replace('sudo -n ', '').replace("['/usr/bin/sudo', '-n', '/usr/bin/id', '-u']", "['/usr/bin/printf', '0']")
                source = source.replace('/run orbit-native-proxy-', str(fixture / 'run') + ' orbit-native-proxy-')
                source = source.replace('/run/orbit-native-proxy-', str(fixture / 'run/orbit-native-proxy-'))
                source = source.replace('info.st_uid != 0', 'info.st_uid != os.getuid()')
                source = source.replace("'user': 'orbit'", "'user': " + repr(user))
                source = source.replace("pw_name != 'orbit'", "pw_name != " + repr(user))
                inputs['run'] = source
        (self.repo / 'workflows/workflow-node-proxy-canary.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        secret = secrets.token_urlsafe(32)
        self.secrets = (secret,)
        self.cli('vault', 'create', 'local_encryption', 'fixture-vault')
        self.cli('vault', 'put', 'fixture-vault', 'fixture-secret', input=secret)
        identity = workflow['jobs'][0]['steps'][1]['task']['inputs']
        identity['env']['ORBIT_PRIVATE_VALUE'] = "${{ vault.get('fixture-vault', 'fixture-secret') }}"
        identity['run'] = identity['run'].replace(' --env ORBIT_EFFECT_NODE_ID', ' --env ORBIT_EFFECT_NODE_ID --secret-env ORBIT_PRIVATE_VALUE')
        workflow['vaults'] = ['fixture-vault']
        (self.repo / 'workflows/workflow-node-proxy-canary.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('workflow', 'validate', 'node-proxy-canary')
        # A second independent package proves cross-bundle asset-content forwarding.
        family = self.repo / 'extensions/behaviors/fixture-action'
        (family / 'scripts').mkdir(parents=True)
        (family / 'scripts/action.py').write_text('import json, os\nassert os.environ["ORBIT_PRIVATE_VALUE"]\nprint(json.dumps({"node_id": int(os.environ["ORBIT_EFFECT_NODE_ID"]), "asset": True}))\n')
        metadata = (self.repo / 'extensions/behaviors/operations/node-command/script_assets.ts').read_text()
        metadata = metadata.replace('@nckrtl/node-command-assets', '@fixture/action-assets')
        metadata = metadata.replace('runner', 'script').replace('scripts/node_command.py', 'scripts/action.py')
        (family / 'assets.ts').write_text(metadata)
        shutil.copy(folder / 'identity.py', family / 'scripts/identity.py')
        (family / 'manifest.yaml').write_text('manifestVersion: 1\nname: "@fixture/action-scripts"\nversion: 2026.10.31.1\nvisibility: private\nlicense: MIT\nbehaviors: [fixture-action/assets.ts]\nadditionalFiles: [scripts/action.py, scripts/identity.py]\n')
        placed = {'target': 'orbit-node-1', 'labels': {'node': '1'}}
        asset_workflow = {'id': '952d73a2-9001-44f6-b677-a7b980f2d9c1', 'name': 'asset-fixture', 'vaults': ['fixture-vault'],
            'jobs': [{'name': 'asset', **placed, 'steps': [
                {'name': 'transport', **placed, 'task': {'type':'behavior','behaviorType':'@nckrtl/node-command-assets','behaviorName':'fixture-transport','methodName':'read'}},
                {'name': 'family', **placed, 'task': {'type':'behavior','behaviorType':'@fixture/action-assets','behaviorName':'fixture-family','methodName':'read'}},
                {'name': 'effect', **placed, 'dependsOn':[{'step':name,'condition':{'type':'succeeded'}} for name in ('transport','family')],
                 'task': {'type':'behavior','behaviorType':'command/shell','behaviorName':'fixture-asset-effect','methodName':'execute','inputs':{
                    'run': '/usr/bin/python3 -I "${{ steps.transport.outputs.runner }}" --node-id "$ORBIT_EFFECT_NODE_ID" --action-file "${{ steps.family.outputs.script }}" --interpreter python3 --env ORBIT_EFFECT_NODE_ID --secret-env ORBIT_PRIVATE_VALUE',
                    'env': {'ORBIT_EFFECT_NODE_ID':'1','ORBIT_PRIVATE_VALUE':"${{ vault.get('fixture-vault', 'fixture-secret') }}"}}}}]}]}
        (self.repo / 'workflows/workflow-asset-fixture.yaml').write_text(yaml.safe_dump(asset_workflow, sort_keys=False))
        self.cli('workflow', 'validate', 'asset-fixture')
        self.cli('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
        admin = self.repo / 'admin'
        admin.write_text(reply(self.cli('access', 'token', 'reveal', 'fixture-admin', '--yes').stdout)['token'])
        admin.chmod(0o600)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        url = f'ws://127.0.0.1:{port}'
        cli = os.environ['SWAMP_BIN']
        env = dict(os.environ, SWAMP_NO_TELEMETRY='1', SWAMP_NO_UPDATE_CHECK='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE',
                    'SWAMP_REPO_DIR', 'SWAMP_CA_CERT', 'SWAMP_EXTENSIONS_DIR'):
            env.pop(key, None)
        processes = []
        with (self.repo / 'serve.log').open('w') as log, (self.repo / 'worker.log').open('w') as worker_log:
            try:
                processes.append(subprocess.Popen([cli, 'serve', '--host', '127.0.0.1', '--port', str(port),
                    '--auth-mode', 'token', '--admins', 'user:fixture', '--remote-only'],
                    cwd=self.repo, env=env, stdout=log, stderr=log))
                for _ in range(100):
                    try:
                        urllib.request.urlopen(f'http://127.0.0.1:{port}/ready', timeout=1).close()
                        break
                    except OSError:
                        self.assertIsNone(processes[0].poll(), (self.repo / 'serve.log').read_text()[-1000:])
                        time.sleep(.1)
                flags = ('--server', url, '--token-file', str(admin))
                enrollment = self.repo / 'enrollment'
                enrollment.write_text(reply(self.cli('worker', 'token', 'create', 'orbit-node-1', '--duration', '10m', *flags).stdout)['token'])
                enrollment.chmod(0o600)
                self.cli('access', 'token', 'mint', 'fixture-worker', '--principal', 'worker:orbit-node-1', *flags)
                access = self.repo / 'worker-access'
                access.write_text(reply(self.cli('access', 'token', 'reveal', 'fixture-worker', '--yes').stdout)['token'])
                access.chmod(0o600)
                processes.append(subprocess.Popen([cli, 'worker', 'connect', url, '--token-file', str(enrollment),
                    '--server-token-file', str(access), '--cache-dir', str(self.repo / 'worker-cache'), '--label', 'node=1'],
                    cwd=self.repo, env=env, stdout=worker_log, stderr=worker_log))
                result = reply(self.cli('workflow', 'run', 'node-proxy-canary', '--input', '{"node_id":1,"effect":true}', *flags).stdout)
                self.assertEqual(result['status'], 'succeeded', result)
                history = reply(self.cli('workflow', 'history', 'get', result['id'], *flags).stdout)
                steps = history['jobs'][0]['steps']
                self.assertEqual([s['name'] for s in steps], ['proxy-assets', 'identity', 'directory', 'write', 'read', 'remove-file', 'remove-directory', 'result'])
                self.assertTrue(all(s['status'] == 'succeeded' for s in steps))
                self.assertIn('worker-cache', steps[0]['outputs']['runner'])
                proof = json.loads(steps[-1]['outputs']['stdout'])
                self.assertEqual(proof['node_id'], 1)
                self.assertTrue(proof['effect'] and proof['cleaned'])
                self.assertFalse(list((fixture / 'run').iterdir()))
                calls = [json.loads(row) for row in (fixture / 'ssh-argv').read_text().splitlines()]
                self.assertEqual(len(calls), 7)
                self.assertTrue(all('orbit@10.44.0.1' in call for call in calls))
                asset_result = reply(self.cli('workflow', 'run', 'asset-fixture', *flags).stdout)
                self.assertEqual(asset_result['status'], 'succeeded', asset_result)
                asset_history = reply(self.cli('workflow', 'history', 'get', asset_result['id'], *flags).stdout)
                asset_steps = asset_history['jobs'][0]['steps']
                self.assertTrue(all(step['status'] == 'succeeded' for step in asset_steps))
                self.assertEqual(json.loads(asset_steps[-1]['outputs']['stdout']), {'node_id': 1, 'asset': True})
                self.assertNotEqual(asset_steps[0]['outputs']['runner'].split('/files/')[0], asset_steps[1]['outputs']['script'].split('/files/')[0])
                self.assert_private_absent(json.dumps(asset_history))
                evidence = os.environ.get('NODE_COMMAND_NATIVE_EVIDENCE')
                if evidence:
                    Path(evidence).write_text(json.dumps({'canary': history, 'asset': asset_history}, indent=2) + '\n')
            finally:
                for process in reversed(processes):
                    process.terminate()
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
