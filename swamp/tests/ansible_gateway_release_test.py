"""The Gateway release keeps its contract: one Swamp step per playbook phase, pinned host keys, and the token never in argv or output."""
import importlib.util
import json
import os
import re
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'
spec = importlib.util.spec_from_file_location('build_vars', ANSIBLE / 'build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)

PHASES = ['fetch', 'build', 'web', 'snapshot', 'drain', 'switch', 'verify', 'prune', 'previous']
TARGET = {'host': '10.44.0.2', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 gateway']}


def workflow(name):
    return yaml.safe_load((ROOT / f'swamp/workflows/{name}.yaml').read_text())


def phase_steps(name):
    """The tag each Swamp step passes to the playbook, in step order."""
    steps = [step for job in workflow(name)['jobs'] for step in job['steps']]
    tagged = []
    for step in steps:
        match = re.search(r'--tags (\w+) ', step['task'].get('inputs', {}).get('run', ''))
        if match:
            tagged.append((step['name'], match.group(1)))
    return tagged


class GatewayReleaseTest(unittest.TestCase):
    def test_the_playbook_has_one_play_per_phase(self):
        plays = yaml.safe_load((ANSIBLE / 'operation-gateway-release.yml').read_text())
        self.assertEqual([play['tags'] for play in plays], [[phase] for phase in PHASES])
        self.assertTrue(all(play['hosts'] == 'gateway' for play in plays))

    def test_every_swamp_step_runs_one_phase_and_the_two_runs_cover_all_of_them(self):
        release = [tag for _, tag in phase_steps('workflow-operation-gateway-release')]
        activate = [tag for _, tag in phase_steps('workflow-gateway-release-activate')]
        self.assertEqual(release, ['fetch', 'build', 'web', 'snapshot', 'previous'])
        # The switch-back is the switch phase again, with the previous release as its target.
        self.assertEqual(activate, ['drain', 'switch', 'verify', 'switch', 'prune'])
        self.assertEqual(sorted(set(release + activate)), sorted(PHASES))

    def test_the_token_reaches_ansible_as_a_private_file_and_never_in_a_command(self):
        text = (ROOT / 'swamp/workflows/workflow-operation-gateway-release.yaml').read_text()
        runs = [step['task']['inputs'].get('run', '') for job in workflow('workflow-operation-gateway-release')['jobs'] for step in job['steps']
                if step['task'].get('inputs')]
        self.assertFalse([run for run in runs if 'github_token' in run or 'vault.get' in run])
        self.assertEqual(text.count('vault.get('), 1)
        playbook = (ANSIBLE / 'operation-gateway-release.yml').read_text()
        self.assertEqual(playbook.count('secrets.github_token'), 2)
        self.assertGreaterEqual(playbook.count('no_log: true'), 2)

    def test_a_failed_load_or_hand_over_loads_the_previous_commit_back(self):
        steps = {step['name']: step for job in workflow('workflow-operation-gateway-release')['jobs'] for step in job['steps']}
        render, restore = steps['Render the previous grants'], steps['Restore Swamp on Node 209']
        # The render runs after a failed load (the hand-over is then skipped) or after a failed hand-over, never after a successful one.
        conditions = {dep['step']: {c['type'] for c in dep['condition']['conditions']} for dep in render['dependsOn']}
        self.assertEqual(conditions, {'Load Swamp on Node 209': {'failed', 'succeeded'}, 'Hand over the release': {'failed', 'skipped'}})
        self.assertEqual([dep['step'] for dep in restore['dependsOn']], ['Render the previous grants'])
        # A restore that fails never replaces the release failure the Gateway reads.
        self.assertTrue(render['allowFailure'] and restore['allowFailure'])
        loaded = restore['task']['inputs']['intent']
        self.assertEqual(restore['task']['workflowIdOrName'], 'operation-gateway-swamp-load')
        self.assertIn('steps["Render the previous grants"]', loaded['sha'])
        self.assertNotIn('inputs.intent.sha', json.dumps(loaded))
        self.assertIs(loaded['restore'], True)
        playbook = (ANSIBLE / 'operation-gateway-release.yml').read_text()
        self.assertIn('releases/{{ intent.previous }}/apps/gateway', playbook)
        # Swamp goes back only while the Gateway still runs the previous release.
        self.assertIn('gateway.release_restore_refused', playbook)
        # The load of an older commit skips the fast-forward guard, and only when asked.
        load = workflow('workflow-operation-gateway-swamp-load')
        self.assertIn('restore', load['inputs']['properties']['intent']['properties'])
        script = load['jobs'][0]['steps'][0]['task']['inputs']['run']
        self.assertIn('[ "$RESTORE" = true ] || git merge-base --is-ancestor', script)

    def test_every_variable_a_phase_reads_is_defined_in_its_step(self):
        for name in ('workflow-operation-gateway-release', 'workflow-gateway-release-activate'):
            for job in workflow(name)['jobs']:
                for step in job['steps']:
                    inputs = step['task'].get('inputs', {})
                    used = set(re.findall(r'\$\{?(ORBIT_[A-Z_]+)', inputs.get('run', '')))
                    self.assertLessEqual(used, set(inputs.get('env', {})), step['name'])

    def test_the_token_never_reaches_a_command_line(self):
        playbook = yaml.safe_load((ANSIBLE / 'operation-gateway-release.yml').read_text())
        text = (ANSIBLE / 'operation-gateway-release.yml').read_text()
        # git reads it from a private file named by GIT_CONFIG_GLOBAL, removed after the fetch, and no task puts it in an environment.
        self.assertIn('GIT_CONFIG_GLOBAL', text)
        self.assertNotIn('GIT_CONFIG_VALUE', text)
        self.assertIn('fetch-git-config', text)
        fetch = next(play for play in playbook if play['tags'] == ['fetch'])
        block = next(task for task in fetch['tasks'] if 'block' in task)
        self.assertEqual(block['always'][0]['ansible.builtin.file']['state'], 'absent')

    def test_the_clone_ref_exists_for_a_commit_the_shared_repository_already_holds(self):
        playbook = yaml.safe_load((ANSIBLE / 'operation-gateway-release.yml').read_text())
        fetch = next(play for play in playbook if play['tags'] == ['fetch'])
        names = [task['name'] for task in fetch['tasks']]
        # The pin is a task of the play, not of the fetch block, so a commit that is already known gets its ref too.
        self.assertIn('Pin the commit in the shared repository', names)
        self.assertGreater(names.index('Pin the commit in the shared repository'), names.index('Refuse a commit that origin does not hold'))

    def test_the_run_folder_is_removed_after_every_end(self):
        for name in ('workflow-operation-gateway-release', 'workflow-gateway-release-activate'):
            cleanup = next(job for job in workflow(name)['jobs'] if job['name'] == 'cleanup')
            self.assertTrue(all(dep['condition']['type'] == 'or' for dep in cleanup['dependsOn']))

    def test_the_inventory_pins_the_gateway_host_key_and_the_private_files_have_their_custody(self):
        previous = os.umask(0o022)
        self.addCleanup(os.umask, previous)
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            intent = {'sha': 'a' * 40, 'id': 'a' * 12, 'ssh': {'target': TARGET, 'identity_file': '/swamp/identity'}, 'swamp_ssh': {'x': 1},
                      'horizon_unit': '', 'manifest': '{{ not a template }}'}
            (folder / 'intent.json').write_text(json.dumps(intent))
            (folder / 'secret-github_token').write_text('ghs_token')
            builder.build(folder, 'gateway.release')
            hosts = json.loads((folder / 'inventory.yml').read_text())['all']['hosts']
            self.assertEqual(hosts['gateway-node'], {'ansible_host': '10.44.0.2', 'ansible_user': 'orbit', 'ansible_port': 22,
                                                'ansible_ssh_private_key_file': '/swamp/identity'})
            self.assertEqual(hosts['swamp']['ansible_connection'], 'local')
            self.assertEqual((folder / 'known_hosts').read_text(), '10.44.0.2 ssh-ed25519 gateway\n')
            self.assertFalse((folder / 'secret-github_token').exists())
            variables = (folder / 'vars.yml').read_text()
            self.assertIn('ghs_token', variables)
            self.assertIn('!unsafe "{{ not a template }}"', variables)
            self.assertNotIn('swamp_ssh', variables)
            self.assertEqual(oct((folder / 'vars.yml').stat().st_mode & 0o777), '0o600')

    def test_the_activate_run_has_no_token(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            (folder / 'intent.json').write_text(json.dumps({'sha': 'a' * 40, 'id': 'a' * 12, 'ssh': {'target': TARGET, 'identity_file': '/i'}}))
            builder.build(folder, 'gateway.release')
            self.assertIn('"secrets": {}', (folder / 'vars.yml').read_text())


if __name__ == '__main__':
    unittest.main()
