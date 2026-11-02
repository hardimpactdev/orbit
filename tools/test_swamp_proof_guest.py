"""Large public Operation intents reach parity without entering the process environment."""
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


class Parity(unittest.TestCase):
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


if __name__ == '__main__':
    unittest.main()
