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
            # As Git does for a checkout that another user owns (an Instance container's workspace): it refuses unless this call trusts the checkout.
            (folder / 'bin/git').write_text('#!/bin/sh\n'
                                            '[ "$1 $2 $3 $4" = "-c safe.directory=$FIXTURE -C $FIXTURE" ] '
                                            '|| { echo "fatal: detected dubious ownership in repository at $FIXTURE" >&2; exit 128; }\n'
                                            'cat "$FIXTURE/tested-head.txt"\n')
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
