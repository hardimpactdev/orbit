"""Extension metadata follows route middleware, including a new slug and unrelated route names."""
import ast
import re
import unittest
from pathlib import Path
from unittest.mock import Mock

ROOT = Path(__file__).resolve().parents[3]
SOURCE = ast.parse((ROOT / 'bin/docs-openapi').read_text())


class OpenApiExtensionsTest(unittest.TestCase):
    def test_reads_extension_from_middleware_without_guessing_route_names(self):
        rows = [
            {'method': 'GET|HEAD', 'uri': 'api/v1/widgets', 'name': 'widget:list',
             'action': 'App\\Http\\Controllers\\WidgetsController@index',
             'middleware': ['App\\Http\\Middleware\\RequireEnabledExtension:widgets']},
            {'method': 'GET', 'uri': 'api/v1/tasks/status', 'name': 'tasks:status',
             'action': 'TasksController@status', 'middleware': []},
            {'method': 'GET', 'uri': 'health', 'name': 'health',
             'action': 'HealthController@index', 'middleware': []},
        ]
        namespace = {'GW': ROOT / 'apps/gateway', 'run_json': Mock(return_value=rows)}
        function = next(node for node in SOURCE.body if isinstance(node, ast.FunctionDef) and node.name == 'load_routes')
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<load_routes>', 'exec'), namespace)

        routes = namespace['load_routes']()

        self.assertEqual([(route['name'], route['extension']) for route in routes],
                         [('widget:list', 'widgets'), ('tasks:status', None)])

    def test_derives_the_slug_enum_from_the_gated_routes(self):
        namespace = {'routes': [{'extension': 'widgets'}, {'extension': None},
                                {'extension': 'reports'}, {'extension': 'widgets'}]}
        assignment = next(node for node in SOURCE.body if isinstance(node, ast.Assign)
                          and any(isinstance(target, ast.Name) and target.id == 'extensions' for target in node.targets))
        exec(compile(ast.Module(body=[assignment], type_ignores=[]), '<extension_slugs>', 'exec'), namespace)

        self.assertEqual(namespace['extensions'], ['reports', 'widgets'])


class OpenApiOptionalPropertyTest(unittest.TestCase):
    def test_optional_source_lists_keep_their_array_item_type(self):
        namespace = {'SCALARS': {'string': 'string', 'int': 'integer', 'bool': 'boolean'},
                     'gdata': {}, 'enums': {}, 'DOC_LIST': re.compile(r'^(?:list|array)<([\w]+)>$'), 're': re}
        function = next(node for node in SOURCE.body if isinstance(node, ast.FunctionDef) and node.name == 'prop_schema')
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<prop_schema>', 'exec'), namespace)
        schema = namespace['prop_schema']({'type': 'array|Optional', 'doc': 'list<string>'})
        self.assertEqual(schema, {'type': 'array', 'items': {'type': 'string'}})
