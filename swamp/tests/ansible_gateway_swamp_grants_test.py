"""A proof fleet must use the candidate's grants, including after restore and a failed reload."""
import getpass
import json
import os
import shutil
import socket
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')


@unittest.skipUnless(Path(COMMAND).exists(), 'ansible-playbook is not installed')
class GatewaySwampGrantsTest(unittest.TestCase):
    def test_proof_release_setup_repairs_a_restored_gateway_and_is_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder / 'swamp/proof').mkdir(parents=True)
            (folder / 'apps/gateway').mkdir(parents=True)
            (folder / 'bin').mkdir()
            revision = 'a' * 40
            (folder / 'tested-head.txt').write_text(revision + '\n')
            (folder / 'swamp/proof/cli-release.json').write_text((ANSIBLE.parent / 'proof/cli-release.json').read_text())
            environment = folder / 'apps/gateway/.env'
            environment.write_text('APP_NAME=Orbit\n')
            (folder / 'bin/git').write_text('#!/bin/sh\ncat "$FIXTURE/tested-head.txt"\n')
            (folder / 'bin/php').write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$FIXTURE/config.log"\n')
            for name in ('git', 'php'):
                (folder / 'bin' / name).chmod(0o755)
            tasks = yaml.safe_load((ANSIBLE / 'tasks/proof-gateway-release.yml').read_text())
            for task in tasks:
                for action in ('ansible.builtin.copy', 'ansible.builtin.lineinfile'):
                    if action in task:
                        task[action].pop('owner')
                        task[action].pop('group')
            play = [{'hosts': 'localhost', 'gather_facts': False,
                     'vars': {'control': {'user': getpass.getuser(), 'repository': str(folder / 'swamp')}},
                     'tasks': tasks, 'handlers': [{'name': 'Restart proof Gateway application services',
                                                  'ansible.builtin.debug': {'msg': 'application restarted'}}]}]
            (folder / 'play.yml').write_text(json.dumps(play))
            env = {**os.environ, 'PATH': str(folder / 'bin') + ':' + os.environ['PATH'], 'FIXTURE': str(folder),
                   'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg')}

            def run():
                return subprocess.run([COMMAND, '-i', 'localhost,', '-c', 'local', '-e', 'ansible_become=false',
                                       '-e', 'ansible_python_interpreter=' + sys.executable, str(folder / 'play.yml')],
                                      capture_output=True, text=True, timeout=60, env=env, check=False)

            for restored in (False, True):
                if restored:
                    environment.write_text('APP_NAME=Orbit\nORBIT_CLI_RELEASE_STATIC_MANIFEST=/old/catalog.json\n')
                    (folder / 'REVISION').write_text('b' * 40 + '\n')
                repaired = run()
                self.assertEqual(repaired.returncode, 0, repaired.stdout + repaired.stderr)
                self.assertIn('application restarted', repaired.stdout)
                self.assertEqual((folder / 'REVISION').read_text(), revision + '\n')
                self.assertEqual(environment.read_text(), f'APP_NAME=Orbit\nORBIT_CLI_RELEASE_STATIC_MANIFEST={folder}/swamp/proof/cli-release.json\n')
                current = run()
                self.assertEqual(current.returncode, 0, current.stdout + current.stderr)
                self.assertIn('changed=0', current.stdout)
                self.assertNotIn('application restarted', current.stdout)
            self.assertEqual((folder / 'config.log').read_text().splitlines(), ['artisan config:clear'] * 4)
            (folder / 'tested-head.txt').write_text('dev\n')
            refused = run()
            self.assertNotEqual(refused.returncode, 0, refused.stdout + refused.stderr)
            self.assertEqual((folder / 'REVISION').read_text(), revision + '\n')

    def test_restored_grants_are_replaced_from_the_candidate_and_a_failed_reload_is_retried(self):
        with tempfile.TemporaryDirectory() as directory:
            server = socket.socket()
            self.addCleanup(server.close)
            server.bind(('127.0.0.1', 0))
            folder = Path(directory)
            repository = folder / 'swamp'
            (repository / 'grants').mkdir(parents=True)
            (repository / 'ansible').mkdir()
            (repository / 'ansible/requirements.yml').write_text((ANSIBLE / 'requirements.yml').read_text())
            (folder / 'bin').mkdir()
            stale = '{"grants": []}\n'
            current = json.dumps({'grants': [{'subject': 'user:orbit-gateway', 'actions': ['write', 'read'],
                                             'resource': 'vault:orbit-control-inputs',
                                             'condition': 'key.matches("^node-converge-operation-[1-9][0-9]*-agent-verifier$")'}]}) + '\n'
            grant = repository / 'grants/orbit-gateway.yaml'
            grant.write_text(stale)
            (repository / 'grants/orbit-serve.yaml').write_text(stale)
            (folder / 'plan.json').write_text(json.dumps({'grants': {'grants/orbit-gateway.yaml': current, 'grants/orbit-serve.yaml': stale}}))
            (folder / 'bin/php').write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$FIXTURE/render.log"\ncat "$FIXTURE/plan.json"\n')
            (folder / 'bin/swamp').write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$FIXTURE/reload.log"\ncat "$FIXTURE/reload.json"\n')
            (folder / 'bin/ansible-galaxy').write_text(
                '#!/bin/sh\nset -eu\nprintf "%s\\n" "$*" >> "$FIXTURE/collections.log"\n'
                'test "$4" = "$FIXTURE/swamp/ansible/requirements.yml"\n'
                'grep -q ansible.posix "$4"\n'
                'if test ! -f "$FIXTURE/collections-installed"; then\n'
                '  touch "$FIXTURE/collections-installed"\n  echo "Installing pinned candidate collections"\n'
                'else\n  echo "Nothing to do. All requested collections are already installed."\nfi\n')
            for name in ('php', 'swamp', 'ansible-galaxy'):
                (folder / 'bin' / name).chmod(0o755)
            # The host's account/group names differ on macOS. The fixture exercises real copies in its private directory;
            # production ownership stays in the imported tasks and is not applied to this developer's temporary files.
            tasks = yaml.safe_load((ANSIBLE / 'tasks/gateway-ansible-collections.yml').read_text())
            tasks[0]['ansible.builtin.command']['argv'][2] = str(folder / 'bin/ansible-galaxy')
            tasks += yaml.safe_load((ANSIBLE / 'tasks/gateway-swamp-grants.yml').read_text())
            for task in tasks:
                if 'ansible.builtin.wait_for' in task:
                    wait = task['ansible.builtin.wait_for']
                    self.assertEqual(wait, {'host': '127.0.0.1', 'port': 9418, 'timeout': 60})
                    # A private port and short deadline; the real wait_for module
                    # must refuse before the fake reload or snapshot is called.
                    wait.update(port=server.getsockname()[1], timeout=1)
                if 'ansible.builtin.copy' in task:
                    task['ansible.builtin.copy'].pop('owner')
                    task['ansible.builtin.copy'].pop('group')
            play = [{'hosts': 'localhost', 'gather_facts': False,
                     'vars': {'control': {'user': getpass.getuser(), 'repository': str(repository)}}, 'tasks': tasks}]
            (folder / 'play.yml').write_text(json.dumps(play))
            # Run through the complete product fleet reconcile and its refresh snapshot decision. Only the Incus boundary is fake;
            # the nested grants play uses real Ansible file changes and returns its real recap to the outer phase.
            (folder / 'stamps').mkdir()
            guests = {'gateway': 'nested-gateway', 'app-dev': 'nested-app-dev', 'app-prod': 'nested-app-prod'}
            fleet = [{'hosts': 'localhost', 'gather_facts': False,
                      'vars': {'intent': {'fleet': {'guests': guests}, 'direct': False},
                               'converge_stamps': {'operator': 'current'}, 'converge_stamps_dir': str(folder / 'stamps'), 'converge_due': {'operator': False, 'tokens': False},
                               'checkout_current': ['code', 'agent', 'migrations']},
                      'tasks': [{'ansible.builtin.import_tasks': str(ANSIBLE / 'tasks/task-vm-fleet.yml')},
                                *yaml.safe_load((ANSIBLE / 'task-vm-role.yml').read_text())[1]['tasks']]}]
            (folder / 'fleet.yml').write_text(json.dumps(fleet))
            (folder / 'bin/incus').write_text('#!' + sys.executable + '\n' + r"""
import hashlib, json, os, subprocess, sys
from pathlib import Path
folder = Path(os.environ['FIXTURE'])
args = sys.argv[1:]
if args == ['list', '--format', 'json']:
    print(json.dumps([{'name': name, 'status': 'Running'} for name in ['nested-gateway', 'nested-app-dev', 'nested-app-prod']]))
elif args[:2] == ['exec', 'nested-gateway'] and '--tags' in args and args[args.index('--tags') + 1] == 'grants':
    assert args[-1] == '/home/orbit/orbit/swamp/ansible/gateway-bootstrap.yml'
    result = subprocess.run([os.environ['FIXTURE_ANSIBLE'], '-i', 'localhost,', '-c', 'local', '-e', 'ansible_become=false',
                             '-e', 'ansible_python_interpreter=' + sys.executable, str(folder / 'play.yml')], check=False)
    sys.exit(result.returncode)
elif args[:4] == ['exec', 'nested-gateway', '--', 'sha256sum']:
    for name in args[4:]:
        path = folder / 'swamp/grants' / Path(name).name
        print(hashlib.sha256(path.read_bytes()).hexdigest() + '  ' + name)
elif args[:3] == ['snapshot', 'create', '--reuse'] and args[-1] == 'base':
    if (folder / 'fail-snapshot').exists() and args[-2] == 'nested-app-dev':
        sys.exit(2)
    with (folder / 'snapshots.log').open('a') as log:
        log.write(args[-2] + '\n')
else:
    sys.exit('Unexpected Incus invocation: ' + repr(args))
""")
            (folder / 'bin/incus').chmod(0o755)
            env = {**os.environ, 'PATH': str(folder / 'bin') + ':' + os.environ['PATH'], 'FIXTURE': str(folder), 'FIXTURE_ANSIBLE': COMMAND,
                   'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg')}

            def run(success):
                stamp = folder / 'stamps/grants-snapshot'
                if stamp.exists():
                    fleet[0]['vars']['converge_stamps']['grants-snapshot'] = stamp.read_text()
                (folder / 'fleet.yml').write_text(json.dumps(fleet))
                (folder / 'reload.json').write_text(json.dumps({'success': success}))
                return subprocess.run([COMMAND, '-i', 'localhost,', '-c', 'local', '-e', 'ansible_become=false',
                                       '-e', 'ansible_python_interpreter=' + sys.executable, str(folder / 'fleet.yml')],
                                      check=False, capture_output=True, text=True, timeout=60, env=env)

            unavailable = run(True)
            self.assertNotEqual(unavailable.returncode, 0, unavailable.stdout + unavailable.stderr)
            self.assertFalse((folder / 'reload.log').exists(), 'reload ran before serve was listening')
            self.assertFalse((folder / 'snapshots.log').exists(), 'unready serve was snapshotted')
            server.listen(128)

            failed = run(False)
            self.assertNotEqual(failed.returncode, 0, failed.stdout + failed.stderr)
            self.assertEqual(grant.read_text(), current)
            self.assertEqual(grant.stat().st_mode & 0o777, 0o640)
            self.assertTrue((folder / 'collections-installed').is_file())
            repaired = run(True)
            self.assertEqual(repaired.returncode, 0, repaired.stdout + repaired.stderr)
            # Even though the failed reload left the desired files on disk, serve must receive the retry.
            self.assertEqual(len((folder / 'reload.log').read_text().splitlines()), 2)
            self.assertEqual((folder / 'snapshots.log').read_text().splitlines(), list(guests.values()))
            current_run = run(True)
            self.assertEqual(current_run.returncode, 0, current_run.stdout + current_run.stderr)
            self.assertIn('changed=0', current_run.stdout)
            self.assertEqual(grant.read_text(), current)
            grant.write_text(stale)  # a later base restore must not reintroduce the same refusal
            restored = run(True)
            self.assertEqual(restored.returncode, 0, restored.stdout + restored.stderr)
            self.assertEqual(grant.read_text(), current)
            self.assertTrue(all('--checkout=' + str(repository) in line for line in (folder / 'render.log').read_text().splitlines()))
            self.assertEqual(len((folder / 'collections.log').read_text().splitlines()), 5)
            # Gateway code, migration and token stamps were current. The grants-only repair must still update every base snapshot.
            self.assertEqual((folder / 'snapshots.log').read_text().splitlines(), list(guests.values()) * 2)
            current_run = run(True)
            self.assertEqual(current_run.returncode, 0, current_run.stdout + current_run.stderr)
            self.assertIn('changed=0', current_run.stdout)
            self.assertEqual((folder / 'snapshots.log').read_text().splitlines(), list(guests.values()) * 2)

            # A partial snapshot failure must not commit the new grants digest.
            stamp = folder / 'stamps/grants-snapshot'
            previous_digest = stamp.read_text()
            newer = current.replace('write', 'read-secret')
            (folder / 'plan.json').write_text(json.dumps({'grants': {'grants/orbit-gateway.yaml': newer,
                                                                  'grants/orbit-serve.yaml': stale}}))
            (folder / 'fail-snapshot').touch()
            failed_snapshot = run(True)
            self.assertNotEqual(failed_snapshot.returncode, 0, failed_snapshot.stdout + failed_snapshot.stderr)
            self.assertEqual(stamp.read_text(), previous_digest)
            (folder / 'fail-snapshot').unlink()
            before = (folder / 'snapshots.log').read_text().splitlines()
            retried = run(True)
            self.assertEqual(retried.returncode, 0, retried.stdout + retried.stderr)
            self.assertEqual((folder / 'snapshots.log').read_text().splitlines(), before + list(guests.values()))
            self.assertNotEqual(stamp.read_text(), previous_digest)
            unchanged = run(True)
            self.assertEqual(unchanged.returncode, 0, unchanged.stdout + unchanged.stderr)
            self.assertIn('changed=0', unchanged.stdout)
            self.assertEqual((folder / 'snapshots.log').read_text().splitlines(), before + list(guests.values()))

    def test_the_grants_tag_uses_the_candidate_renderer_not_the_saved_plan_grants(self):
        plays = yaml.safe_load((ANSIBLE / 'gateway-bootstrap.yml').read_text())
        grants = next(play for play in plays if 'grants' in play.get('tags', []))
        self.assertEqual(grants['tasks'][0]['ansible.builtin.import_tasks'], 'tasks/gateway-swamp-grants.yml')
        apply = yaml.safe_load((ANSIBLE.parent / 'workflows/workflow-task-nested-apply.yaml').read_text())['jobs'][0]['steps']
        reconcile = next(step for step in apply if step['name'] == 'Reconcile the Swamp grants')
        self.assertEqual(reconcile['task']['inputs']['argv'][-3:], ['--tags', 'grants', '/home/orbit/orbit/swamp/ansible/gateway-bootstrap.yml'])
        self.assertEqual(reconcile['dependsOn'][0]['step'], 'Place the agent delivery')
        renewal = next(step for step in apply if step['name'] == 'Re-mint the Swamp tokens')
        self.assertEqual(renewal['guard'], '${{ !(has(inputs.remint) && inputs.remint) }}')
        self.assertEqual(renewal['dependsOn'], [{'step': 'Place the agent delivery', 'condition': {'type': 'succeeded'}}])
        self.assertEqual(reconcile['dependsOn'], [
            {'step': 'Place the agent delivery', 'condition': {'type': 'succeeded'}},
            {'step': 'Re-mint the Swamp tokens', 'condition': {'type': 'or', 'conditions': [{'type': 'succeeded'}, {'type': 'skipped'}]}},
        ])
        self.assertEqual(next(step for step in apply if step['name'] == 'Migrate')['dependsOn'],
                         [{'step': 'Reconcile the Swamp grants', 'condition': {'type': 'succeeded'}}])
        collections = next(play for play in plays if 'collections' in play.get('tags', []))
        self.assertEqual(collections['tasks'][0]['ansible.builtin.import_tasks'], 'tasks/gateway-ansible-collections.yml')


if __name__ == '__main__':
    unittest.main()
