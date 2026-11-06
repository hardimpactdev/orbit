"""The task VM runtime playbook keeps its phase tags, its secret custody and its pinned connection."""
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PLAYBOOK = ANSIBLE / 'task-vm-runtime.yml'
spec = importlib.util.spec_from_file_location('build_vars', ANSIBLE / 'build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


def steps(play):
    for task in play['tasks']:
        yield from task['block']


class TaskVmRuntimeTest(unittest.TestCase):
    def test_every_phase_is_one_tag_and_the_credentials_are_silent(self):
        play = yaml.safe_load(PLAYBOOK.read_text())[0]
        self.assertEqual({task['name']: task['tags'] for task in play['tasks']}, {'Install': ['install'], 'Check': ['check']})
        for step in steps(play):
            if 'pi_models' in str(step) or 'pi_token' in str(step):
                self.assertIs(step.get('no_log'), True, step['name'])
        # A task that fails after a file changed must still run the handlers it queued.
        self.assertIs(play['force_handlers'], True)
        self.assertEqual({handler['name'] for handler in play['handlers']}, {'Update the CA store', 'Restart the Pi server'})

    def test_the_app_dev_runtime_footprint_comes_from_the_intent(self):
        play = yaml.safe_load(PLAYBOOK.read_text())[0]
        loops = {step['name']: step.get('loop') for step in steps(play)}
        self.assertEqual(loops['Create the app-dev runtime folders'], '{{ intent.runtime.app_dev.directories | default([]) }}')
        self.assertEqual(loops['Write the app-dev runtime files'], '{{ intent.runtime.app_dev.files | default([]) }}')

    def test_the_runtime_intent_is_reached_with_its_pinned_host_key(self):
        intent = {'ssh_execution': {'identity_file': '/role/identity', 'target': {
            'host': '10.252.7.9', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 vm']}}, 'certificate': '{{ not a template }}'}
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'intent.json').write_text(json.dumps(intent))
            builder.build(folder, 'task_vm.runtime')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
            self.assertEqual(host['ansible_host'], '10.252.7.9')
            self.assertEqual(host['ansible_ssh_private_key_file'], '/role/identity')
            self.assertEqual((folder / 'known_hosts').read_text(), '10.252.7.9 ssh-ed25519 vm\n')
            self.assertIn('!unsafe "{{ not a template }}"', (folder / 'vars.yml').read_text())

    @unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                         'ansible-playbook is not installed')
    def test_the_playbook_passes_the_ansible_syntax_check(self):
        command = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
        result = subprocess.run([command, '--syntax-check', '-i', 'localhost,', '-e', 'pi_path=/x', '-e', '{"intent": {}}', str(PLAYBOOK)],
                                check=False, capture_output=True, text=True, timeout=60,
                                env={**os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg')})
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main()
