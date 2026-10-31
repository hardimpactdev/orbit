"""Operation schemas describe the parent record without deleted Gateway step receipts."""
import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ast.parse((ROOT / 'bin/docs-openapi').read_text())


class OpenApiOperationsTest(unittest.TestCase):
    def test_show_and_cancel_return_operation_data_without_step_receipts(self):
        namespace = {
            'RESPONSE_OVERRIDES': {},
            'CTRL_DATA_OVERRIDES': {},
            'SDK_RESPONSE_FALLBACK': {},
            'ref': lambda name: {'$ref': '#/components/schemas/' + name.removesuffix('Data')},
        }
        function = next(node for node in SOURCE.body
                        if isinstance(node, ast.FunctionDef) and node.name == 'data_schema_for')
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<data_schema_for>', 'exec'), namespace)

        for action in ('OperationsController@show', 'OperationsController@cancel'):
            with self.subTest(action=action):
                schema, is_list = namespace['data_schema_for']({'action': action})

                self.assertEqual(schema, {'$ref': '#/components/schemas/Operation'})
                self.assertFalse(is_list)

    def test_native_failure_response_is_only_identity_and_terminal_status(self):
        namespace = {'RESPONSE_OVERRIDES': {}, 'CTRL_DATA_OVERRIDES': {}, 'SDK_RESPONSE_FALLBACK': {}}
        function = next(node for node in SOURCE.body
                        if isinstance(node, ast.FunctionDef) and node.name == 'data_schema_for')
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<data_schema_for>', 'exec'), namespace)

        schema, is_list = namespace['data_schema_for']({'action': 'DirectOperationsController@failure'})

        self.assertEqual(set(schema['properties']), {'id', 'status'})
        self.assertEqual(schema['required'], ['id', 'status'])
        self.assertFalse(schema['additionalProperties'])
        self.assertFalse(is_list)

    def test_native_failure_and_primitive_request_accept_only_run_id(self):
        namespace = {}
        function = next(node for node in SOURCE.body
                        if isinstance(node, ast.FunctionDef) and node.name == 'direct_run_body')
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<direct_run_body>', 'exec'), namespace)

        schema = namespace['direct_run_body']()

        self.assertEqual(schema, {
            'type': 'object', 'properties': {'run_id': {'type': 'string', 'format': 'uuid'}},
            'required': ['run_id'], 'additionalProperties': False,
        })
