"""Extension metadata follows route middleware, including a new slug and unrelated route names."""
import ast
from pathlib import Path
import unittest
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
