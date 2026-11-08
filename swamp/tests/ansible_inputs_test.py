"""Check the private Ansible input contract without contacting Nodes."""
import importlib.util
import json
import os
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
ANSIBLE = ROOT / 'swamp/ansible'
spec = importlib.util.spec_from_file_location('build_vars', ANSIBLE / 'build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class AnsibleInputTest(unittest.TestCase):
    def test_admitted_identity_and_secret_file_custody(self):
        previous = os.umask(0o022)
        self.addCleanup(os.umask, previous)
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            intent = {
                'node_id': 12,
                'ssh': {'identity_file': '/role/identity', 'target': {
                    'host': '192.0.2.12', 'port': 2222, 'user': 'orbit', 'host_keys': ['ssh-ed25519 pinned'],
                }},
                'publications': [], 'exporters': [], 'prometheus': None, 'runtime': {'analytics': None},
                'configuration': '{{ upstream_template }}', 'setting': 'on',
            }
            (folder / 'intent.json').write_text(json.dumps(intent))
            (folder / 'plans.json').write_text('{}')
            (folder / 'secret-metrics_password').write_text('private fixture value')
            builder.build(folder, 'node.role_add')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['hosts']['n12']
            self.assertEqual(host['ansible_ssh_private_key_file'], '/role/identity')
            self.assertEqual(host['ansible_port'], 2222)
            self.assertEqual((folder / 'known_hosts').read_text(), '[192.0.2.12]:2222 ssh-ed25519 pinned\n')
            self.assertFalse((folder / 'secret-metrics_password').exists())
            text = (folder / 'vars.yml').read_text()
            self.assertIn('!unsafe "{{ upstream_template }}"', text)
            self.assertIn('"setting": "on"', text)
            self.assertIn('"metrics_password": "private fixture value"', text)
            for name in ['inventory.yml', 'vars.yml', 'known_hosts']:
                self.assertEqual((folder / name).stat().st_mode & 0o777, 0o600)

    def test_metrics_container_plan_preserves_the_admitted_state(self):
        with tempfile.TemporaryDirectory() as directory:
            folder = Path(directory)
            service = {
                'name': 'prometheus', 'image': 'prometheus@sha256:pinned',
                'check': {'restart': 'unless-stopped', 'volumes': ['data:/data'], 'ports': []},
                'volume_args': ['volume', 'create', '--label', 'orbit.role=metrics', 'data'],
                'argv': ['docker', 'run', '--label', 'orbit.role=metrics', '--env', 'VALUE=a=b',
                         '--log-driver', 'json-file', '--log-opt', 'max-size=10m', '--network', 'host',
                         '--health-cmd', 'wget localhost:9090', '--health-start-period', '30s',
                         '--health-interval', '10s', '--health-retries', '3', '--',
                         'prometheus@sha256:pinned', '--config.file=/etc/prometheus.yml'],
            }
            intent = {
                'node_id': 12, 'ssh': {'identity_file': '/key', 'target': {
                    'host': '192.0.2.12', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 pinned']}},
                'publications': [], 'exporters': [], 'prometheus': None,
                'runtime': {'analytics': None, 'metrics': {'plan': {'services': [service]}}},
            }
            (folder / 'intent.json').write_text(json.dumps(intent))
            (folder / 'plans.json').write_text('{}')
            builder.build(folder, 'node.role_add')
            container = json.loads((folder / 'vars.yml').read_text())['containers'][0]
            self.assertEqual(container['check'], service['check'])
            self.assertEqual(container['env'], {'VALUE': 'a=b'})
            self.assertEqual(container['labels'], {'orbit.role': 'metrics'})
            self.assertEqual(container['volume'], {'name': 'data', 'labels': {'orbit.role': 'metrics'}})
            self.assertEqual(container['command'], ['--config.file=/etc/prometheus.yml'])
            self.assertEqual(container['healthcheck']['retries'], 3)
            self.assertEqual(container['log_options'], {'max-size': '10m'})


class RoleAddPhasesTest(unittest.TestCase):
    def setUp(self):
        self.workflow = yaml.safe_load((ROOT / 'swamp/workflows/workflow-operation-node-role-add.yaml').read_text())
        self.jobs = {job['name']: job for job in self.workflow['jobs']}
        self.plays = yaml.safe_load((ANSIBLE / 'operation-node-role-add.yml').read_text())

    def test_required_machine_phases_use_the_existing_ansible_entry_point(self):
        expected = {'phase_13': 'tools', 'analytics': 'analytics', 'swamp_runtime_job': 'swamp',
                    'phase_52': 'php', 'phase_56': 'dns', 'analytics_cleanup': 'cleanup'}
        for job, tag in expected.items():
            step = self.jobs[job]['steps'][0]
            self.assertEqual(step['task']['behaviorType'], 'command/shell')
            command = step['task']['inputs']['run']
            self.assertIn('/opt/orbit/ansible/venv/bin/ansible-playbook', command)
            self.assertIn(f'--tags {tag} ', command)
            self.assertIn('operation-node-role-add.yml', command)
            self.assertTrue(any(tag in play['tags'] for play in self.plays))
        # The canonical projection selector must still record the Analytics Process.
        self.assertEqual(self.jobs['analytics']['steps'][0]['name'], 'Apply Analytics runtime-0')
        self.assertEqual(self.jobs['role']['steps'][0]['name'], 'Check the role')
        # Certificate signing remains at the native boundary required by the approved chart.
        self.assertEqual(self.jobs['phase_51']['steps'][0]['task']['workflowIdOrName'], 'node-role-certificate-publish')

    def test_observations_precede_pure_composition_and_machine_application(self):
        for observe, compose, apply in [('tools_read', 'tools_plan', 'phase_13'),
                                        ('publication_read', 'publication_plan', 'phase_52')]:
            self.assertEqual(self.jobs[compose]['dependsOn'][0]['job'], observe)
            self.assertEqual(self.jobs[apply]['dependsOn'][0]['job'], compose)
            steps = self.jobs[compose]['steps']
            self.assertEqual(steps[0]['task']['methodName'], 'composeRole')
            self.assertIn('node.role_add.composed', steps[1]['task']['inputs']['run'])
            self.assertEqual(steps[1]['dependsOn'][0]['step'], steps[0]['name'])

    def test_removal_waits_for_every_job_including_failed_and_skipped_container_cleanup(self):
        dependencies = {dep['job']: dep['condition'] for dep in self.jobs['cleanup']['dependsOn']}
        self.assertEqual(set(dependencies), set(self.jobs) - {'cleanup'})
        self.assertEqual(dependencies['analytics_cleanup'], {'type': 'or', 'conditions': [{'type': 'completed'}, {'type': 'skipped'}]})
        self.assertEqual([step['name'] for step in self.jobs['cleanup']['steps']], ['Remove the playbook input'])
        self.assertEqual({dep['job'] for dep in self.jobs['analytics_cleanup']['dependsOn']}, {'analytics', 'readiness'})
        self.assertIn('analytics == null', self.jobs['analytics_cleanup']['steps'][0]['guard'])
        self.assertIn('node.role_converge', self.jobs['analytics_cleanup']['steps'][0]['guard'])

    def test_composed_observations_keep_literal_jinja_and_private_modes(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'composed.json').write_text(json.dumps({'plans': {'ci': {}, 'publications': {'n4': {'dns': {'records': '{{ not_a_variable }}'}}}}}))
            builder.build(folder, 'node.role_add.composed')
            result = (folder / 'composed.yml').read_text()
            self.assertIn('!unsafe "{{ not_a_variable }}"', result)
            self.assertEqual((folder / 'composed.yml').stat().st_mode & 0o777, 0o600)

    def test_tool_manager_keeps_mirrors_and_both_admitted_checksum_algorithms(self):
        tasks = yaml.safe_load((ANSIBLE / 'tasks/role-tool-manager.yml').read_text())
        for algorithm in ['SHA256', 'SHA512']:
            task = next(task for task in tasks if task['name'] == f'Download {algorithm} pinned Tool Manager packages')
            self.assertEqual(task['orbit_artifact']['urls'], '{{ item.urls }}')
            self.assertIn(algorithm.lower(), task['orbit_artifact'])
            self.assertIn('manager_missing_indexes', task['when'])
        names = [task['name'] for task in tasks]
        self.assertLess(names.index('Link manager resources'), names.index('Configure the pinned manager'))
        self.assertLess(names.index('Configure the pinned manager'), names.index('Publish manager launchers'))
        self.assertIn('Check pinned manager packages', names)

    def test_analytics_and_swamp_resources_keep_the_admitted_pins_and_order(self):
        analytics = yaml.safe_load((ANSIBLE / 'tasks/role-analytics.yml').read_text())
        initialize = next(task for task in analytics if task['name'] == 'Initialize the Analytics database')
        self.assertEqual(initialize['ansible.builtin.command']['argv'], '{{ item.argv }}')
        self.assertTrue(initialize['no_log'])
        runtime = next(task for task in analytics if task['name'] == 'Apply the Analytics runtime')
        self.assertEqual(runtime['community.docker.docker_container']['labels']['orbit.process.spec'], '{{ analytics.intent.installation.spec }}')
        self.assertLess(analytics.index(initialize), analytics.index(runtime))
        swamp = yaml.safe_load((ANSIBLE / 'tasks/role-swamp.yml').read_text())
        binary = next(task for task in swamp if task['name'] == 'Install pinned Swamp binary')
        self.assertEqual(binary['ansible.builtin.get_url']['checksum'], 'sha256:{{ rt.swamp.binary.sha256 }}')
        link = next(task for task in swamp if task['name'] == 'Link the pinned Swamp executable')
        self.assertEqual(link['register'], 'swamp_link')
        for name in ['Start Swamp runtime', 'Start the Swamp workers']:
            start = next(task for task in swamp if task['name'] == name)
            self.assertIn('swamp_link.changed', start['ansible.builtin.systemd']['state'])
            self.assertIn('swamp_identity.changed', start['ansible.builtin.systemd']['state'])
        check = next(task for task in swamp if task['name'] == 'Check Swamp version')
        self.assertEqual(check['become_user'], '{{ rt.swamp.user }}')


if __name__ == '__main__':
    unittest.main()
