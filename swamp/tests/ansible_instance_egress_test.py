"""Exercise the Instance proxy's readiness through Ansible and real sockets, without host or firewall changes."""
import copy
import os
import select
import shutil
import socket
import subprocess
import sys
import tempfile
import threading
import unittest
from pathlib import Path

import yaml

EGRESS = Path(__file__).resolve().parents[1] / 'ansible/tasks/instance-egress.yml'
HOST = EGRESS.with_name('instance-host.yml')


class InstanceEgressReadinessTest(unittest.TestCase):
    def setUp(self):
        self.tasks = {task['name']: task for task in yaml.safe_load(EGRESS.read_text())}

    def readiness(self, address, port, retries=1):
        """Run the shipped task, with a shorter budget for absent listeners."""
        command = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
        task = copy.deepcopy(self.tasks['Wait for the proxy'])
        if 'ansible.builtin.wait_for' in task:
            task['ansible.builtin.wait_for']['timeout'] = 1
        else:
            task.update(retries=retries, delay=1)
        with tempfile.TemporaryDirectory(prefix='egress-readiness-') as folder:
            playbook = Path(folder) / 'probe.yml'
            playbook.write_text(yaml.safe_dump([{
                'name': 'Observe the proxy listener', 'hosts': 'localhost', 'gather_facts': False,
                'vars': {'instances': {'egress': {'origin': f'http://{address}:{port}', 'port': port}}}, 'tasks': [task],
            }]))
            return subprocess.run([command, '-i', 'localhost,', '-c', 'local', str(playbook)],
                                  check=False, capture_output=True, text=True, timeout=30,
                                  env={**os.environ, 'ANSIBLE_STDOUT_CALLBACK': 'default',
                                       'ANSIBLE_CALLBACKS_ENABLED': 'default', 'ANSIBLE_COLLECTIONS_PATH': folder})

    def test_readiness_observes_the_listener_without_a_connection(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            result = self.readiness(*listener.getsockname())
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertIn('changed=0', result.stdout)
            self.assertFalse(select.select([listener], [], [], 0)[0],
                             'Host TCP readiness enters over lo, which the egress table rejects')

    def test_readiness_refuses_a_listener_on_another_address(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen()
            result = self.readiness('127.0.0.2', listener.getsockname()[1])
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_readiness_refuses_a_bound_port_that_is_not_listening(self):
        with socket.socket() as bound, socket.socket() as other:
            bound.bind(('127.0.0.1', 0))
            other.bind(('127.0.0.1', 0))
            other.listen()
            result = self.readiness(*bound.getsockname())
            self.assertNotEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_readiness_waits_for_the_service_to_listen(self):
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            start = threading.Timer(3, listener.listen)
            start.start()
            try:
                result = self.readiness(*listener.getsockname(), retries=5)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                self.assertIn('FAILED - RETRYING', result.stdout)
                self.assertIn('changed=0', result.stdout)
            finally:
                start.cancel()
                start.join()

    def test_wait_is_bounded_after_start_and_before_the_image_uses_the_proxy(self):
        task = self.tasks['Wait for the proxy']
        self.assertEqual((task['retries'], task['delay']), (30, 1))
        names = list(self.tasks)
        self.assertLess(names.index('Load the table'), names.index('Run the proxy'))
        self.assertLess(names.index('Run the proxy'), names.index('Wait for the proxy'))
        self.assertLess(names.index('Admit the containers to the proxy'), names.index('Wait for the proxy'))
        host_tasks = {task['name']: task for task in yaml.safe_load(HOST.read_text())}
        self.assertLess(list(host_tasks).index('Run the egress proxy'), list(host_tasks).index('Build the Instance image'))


if __name__ == '__main__':
    unittest.main()
