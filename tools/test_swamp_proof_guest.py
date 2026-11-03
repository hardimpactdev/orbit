"""Large public Operation intents reach parity without entering the process environment."""
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


class Parity(unittest.TestCase):
    def test_public_hook_bindings_cannot_replace_topology_or_secret_bindings(self):
        source = Path(__file__).parent / 'swamp-proof-guest/run.py'
        spec = importlib.util.spec_from_file_location('proof_guest', source)
        runner = importlib.util.module_from_spec(spec)
        with patch.object(sys, 'argv', ['run.py', str(source.parent), 'fixture']):
            spec.loader.exec_module(runner)
        context = {'node.target': 209, 'secret.token': 'private'}
        runner.public_bindings([json.dumps({'bindings': {'operation': 73, 'node.target': 99,
                                                       'secret.token': 'replace', 'invalid': {}, 'boolean': True}})], context)
        self.assertEqual(runner.bind('operations/${fixture.operation}', context), 'operations/73')
        self.assertEqual(context, {'node.target': 209, 'secret.token': 'private', 'fixture.operation': 73})

    def test_large_operation_is_loaded_from_its_file(self):
        source = Path(__file__).parent / 'swamp-proof-guest/run.py'
        spec = importlib.util.spec_from_file_location('proof_guest', source)
        runner = importlib.util.module_from_spec(spec)
        with patch.object(sys, 'argv', ['run.py', str(source.parent), 'fixture']):
            spec.loader.exec_module(runner)
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'parity.py').write_text('import json, os\nvalue=json.load(open(os.environ["PROOF_OPERATION_FILE"]))\nassert len(value["input"]["large"])==1024*1024\nprint("large Operation read")\n')
            operation = {'status': 'succeeded', 'result': {}, 'input': {'large': 'x' * (1024 * 1024)}}
            with patch.object(runner, 'SOURCE', folder), patch.object(runner, 'STATE', folder):
                result = runner.parity_check({'parity': 'parity.py'}, {'target': {'key': 'fixture'}, 'nodes': {}}, 'run', {}, operation)
            self.assertEqual(result, {'exit': 0, 'output': 'large Operation read\n'})
            self.assertEqual(json.loads((folder / 'operation-run.json').read_text()), operation)


class Reached(Exception):
    pass


def guest_up():
    source = Path(__file__).parent / 'swamp-proof-guest/up.py'
    spec = importlib.util.spec_from_file_location('proof_guest_up', source)
    module = importlib.util.module_from_spec(spec)
    with patch.object(sys, 'argv', ['up.py', str(Path(__file__).parents[1])]):
        spec.loader.exec_module(module)
    return module


class NestedNode:
    """A fake Incus with one nested Node, recording the commands up runs."""

    def __init__(self, exists, status, agent=True, stops=False):
        self.exists, self.state, self.agent, self.stops, self.commands = exists, status, agent, stops, []

    def run(self, argv, **_):
        self.commands.append(argv)
        if argv[:2] == ['incus', 'list']:
            return 'peer\n' if self.exists else ''
        if argv[:2] == ['incus', 'query']:
            return json.dumps({'status': self.state})
        if argv[:2] == ['incus', 'start']:
            self.state = 'Running'
            return ''
        if argv[:2] in (['incus', 'copy'], ['incus', 'config']):
            self.exists = True
            return ''
        raise Reached(argv)

    def subprocess(self, argv, **_):
        if argv[:2] == ['incus', 'console']:
            return type('Result', (), {'stdout': 'boot line\nkernel panic\n', 'stderr': '', 'returncode': 0})()
        if self.stops:
            self.state = 'Stopped'
        code = 0 if self.agent and not self.stops else 1
        return type('Result', (), {'stdout': '', 'stderr': '', 'returncode': code})()


class NestedNodes(unittest.TestCase):
    def prepare(self, incus):
        up = guest_up()
        with patch.object(up, 'run', incus.run), patch.object(up.subprocess, 'run', incus.subprocess):
            return up.prepare({'key': 'peer', 'address': '10.231.0.12', 'memory': '2GiB'}, {'name': 'oeproof'})

    def test_a_held_vm_starts_a_nested_node_that_stopped(self):
        incus = NestedNode(exists=True, status='Stopped')
        with self.assertRaises(Reached):
            self.prepare(incus)
        self.assertIn(['incus', 'start', 'peer'], incus.commands)
        self.assertFalse(any(command[:2] == ['incus', 'copy'] for command in incus.commands))

    def test_a_running_held_node_is_not_started_again(self):
        incus = NestedNode(exists=True, status='Running')
        with self.assertRaises(Reached):
            self.prepare(incus)
        self.assertNotIn(['incus', 'start', 'peer'], incus.commands)

    def test_a_node_that_stops_while_booting_fails_at_once_with_its_state_and_console(self):
        incus = NestedNode(exists=False, status='Stopped', stops=True)
        with self.assertRaises(RuntimeError) as failure:
            self.prepare(incus)
        self.assertIn('peer agent did not start: status Stopped', str(failure.exception))
        self.assertIn('kernel panic', str(failure.exception))


if __name__ == '__main__':
    unittest.main()
