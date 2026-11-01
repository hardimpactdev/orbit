"""Proof-input boundary tests, without topology or production access."""
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

DIRECTORY = Path(__file__).resolve().parents[1] / 'proof/task_vm.sweep_host'


def load(name):
    spec = importlib.util.spec_from_file_location('sweep_proof_' + name, DIRECTORY / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class SweepProofInputs(unittest.TestCase):
    def test_recreation_requires_real_matching_completed_cancellation_before_writes(self):
        module = load('prepare')
        receipt = {'kind': 'tasks.cancel', 'status': 'succeeded', 'target_type': 'task_group', 'target_id': 17,
                   'input': {'task_vm_id': 31, 'node_id': 13},
                   'result': {'cleanup_complete': True, 'vm': {'task_vm_id': 31, 'destroyed': True}}}
        for field, wrong in (('status', 'failed'), ('target_id', 18)):
            with self.subTest(field=field), patch.object(module, 'execute', return_value=json.dumps({**receipt, field: wrong})) as execute:
                with self.assertRaises(ValueError):
                    module.prepare(31, 13, 17, 42)
                self.assertEqual(execute.call_count, 1)
        with patch.object(module, 'execute', return_value=json.dumps(receipt)) as execute:
            module.prepare(31, 13, 17, 42)
        self.assertEqual(execute.call_count, 3)
        self.assertEqual(execute.call_args_list[0].args[0], 'operator')
        create = execute.call_args_list[1].args
        self.assertEqual(create[0], 'app-dev')
        self.assertIn('tvm-31', create[1])
        self.assertIn('--vm', create[1])
        self.assertIn('--empty', create[1])
        self.assertNotIn('delete', create[1])

    def test_parity_uses_unwrapped_actual_outputs_and_rejects_an_extra_deletion(self):
        module = load('parity')
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'outputs.json'
            receipt = {'ready': True, 'deleted_names': ['tvm-31-topology-seed', 'tvm-31'], 'observed_names': []}
            path.write_text(json.dumps({'ready': True, 'stdout': json.dumps(receipt)}))
            env = {'PROOF_OUTPUTS': str(path), 'PROOF_EXEC': '/harness exec proof', 'PROOF_RUN': 'proof-1', 'PROOF_OPERATION_ID': '42'}
            with patch.dict(module.os.environ, env, clear=True), patch.object(module.subprocess, 'run') as run:
                run.return_value.stdout = '[]'
                module.main(31)
                self.assertEqual(run.call_count, 2)
                self.assertEqual(run.call_args.args[0][:4], ['/harness', 'exec', 'proof', 'app-dev'])
                receipt['deleted_names'].append('tvm-32')
                path.write_text(json.dumps({'ready': True, 'stdout': json.dumps(receipt)}))
                run.reset_mock()
                with self.assertRaises(ValueError):
                    module.main(31)
                run.assert_not_called()
