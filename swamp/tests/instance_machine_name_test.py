"""The presented machine name's one rule: only its own lines of /etc/hosts change, Orbit's private names block and Incus's hosts template
keep theirs, and /etc/machine-info is Orbit's whole."""
import json
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'swamp/ansible/instance_machine_name.py'
NODE_SERVICES = ROOT / 'swamp/ansible/instance_node_services.py'
PATHS = ['/etc/hostname', '/etc/machine-info', '/etc/hosts']

# What the stock image's hosts.tpl renders for a copy at its first start.
TEMPLATE = ('127.0.1.1\tinstance-42\n127.0.0.1\tlocalhost\n::1\t\tlocalhost ip6-localhost ip6-loopback\n'
            'ff02::1\t\tip6-allnodes\nff02::2\t\tip6-allrouters\n')
PRIVATE = [{'name': 'reverb.orbit', 'host': '10.44.0.3', 'port': 8443}]


def command(source, name, given):
    """A command of a shipped file, as every writer runs it on the Node."""
    done = subprocess.run([sys.executable, '-I', '-c', source.read_text(), name], input=json.dumps(given), capture_output=True, text=True,
                          check=True)
    return json.loads(done.stdout)


def render(files, hostname='task-5003.dlf-leden', pretty='task-5003.dlf-leden'):
    return command(SOURCE, 'render', {'files': dict(zip(PATHS, files)), 'hostname': hostname, 'pretty': pretty,
                                      'container': 'instance-42'})['files']


def hosts(text, hostname='task-5003.dlf-leden'):
    return command(SOURCE, 'hosts', {'hosts': text, 'hostname': hostname, 'container': 'instance-42'})['content']


def private_names(text):
    return command(NODE_SERVICES, 'hosts', {'hosts': text, 'private_names': PRIVATE})['content']


class RenderTest(unittest.TestCase):
    def test_a_copy_gets_all_three_files_and_a_second_render_changes_none(self):
        files = render(['instance-42\n', None, TEMPLATE])
        self.assertEqual(files['/etc/hostname'], 'task-5003.dlf-leden\n')
        self.assertEqual(files['/etc/machine-info'], 'PRETTY_HOSTNAME=task-5003.dlf-leden\n')
        self.assertEqual(files['/etc/hosts'], TEMPLATE.replace('127.0.1.1\tinstance-42',
                                                               '127.0.1.1\ttask-5003.dlf-leden task-5003 instance-42'))
        self.assertEqual(render([files['/etc/hostname'], files['/etc/machine-info'], files['/etc/hosts']]), {})

    def test_machine_info_is_orbits_whole_file_with_the_name_as_stored(self):
        files = render([None, 'CHASSIS=container\nPRETTY_HOSTNAME=default.shop\n', None],
                       'default-0000000000000000000000000000000000000000000000123.my-app', 'default.My_App')
        self.assertEqual(files['/etc/machine-info'], 'PRETTY_HOSTNAME=default.My_App\n')
        self.assertEqual(files['/etc/hostname'], 'default-0000000000000000000000000000000000000000000000123.my-app\n')

    def test_the_incus_name_stays_an_alias_so_the_kernel_hostname_resolves_before_the_next_boot(self):
        self.assertEqual(hosts('127.0.0.1 localhost\n'), '127.0.1.1\ttask-5003.dlf-leden task-5003 instance-42\n127.0.0.1 localhost\n')
        # A hostname without a dot gives no second label.
        self.assertEqual(hosts('', 'default'), '127.0.1.1\tdefault instance-42\n')

    def test_only_the_127_0_1_1_line_changes_and_the_private_names_survive_both_orders(self):
        # A copy's first start: the template, then the clone script's private names, then this writer.
        first = private_names(TEMPLATE)
        named = hosts(first)
        self.assertEqual(named, first.replace('127.0.1.1\tinstance-42', '127.0.1.1\ttask-5003.dlf-leden task-5003 instance-42'))
        self.assertIn('10.44.0.3 reverb.orbit', named)
        # A later start: the private names writer runs first again and keeps this writer's line.
        self.assertEqual(private_names(named), named)
        self.assertEqual(hosts(named), named)


if __name__ == '__main__':
    unittest.main()
