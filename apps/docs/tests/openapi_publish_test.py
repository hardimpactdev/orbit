"""Optional nested objects keep their required fields in generated API and MCP input."""
import ast
from pathlib import Path
import re
import unittest

SOURCE = ast.parse((Path(__file__).resolve().parents[3] / 'bin/docs-openapi').read_text())


class OpenApiPublishTest(unittest.TestCase):
    def test_requires_children_only_inside_their_optional_parent(self):
        namespace = {'re': re, 'enums': {}}
        names = {'schema_from_tokens', 'clean_schema', 'rules_to_schema'}
        nodes = [node for node in SOURCE.body if isinstance(node, ast.FunctionDef) and node.name in names]
        nodes += [node for node in SOURCE.body if isinstance(node, ast.Assign)
                  and any(isinstance(target, ast.Name) and target.id == 'TYPE_RULES' for target in node.targets)]
        exec(compile(ast.Module(body=nodes, type_ignores=[]), '<publish-schema>', 'exec'), namespace)
        namespace['parse_rule_entries'] = lambda _: {
            'pull_request': ['sometimes', 'array:base,title,body'],
            'pull_request.base': ['required_with:pull_request', 'string'],
            'pull_request.title': ['required_with:pull_request', 'string'],
            'pull_request.body': ['present_with:pull_request', 'string'],
            'other.value': ['required_with:unrelated', 'string'],
        }
        namespace['tokens_for'] = lambda tokens, route: [('rule', token) for token in tokens]

        schema, _ = namespace['rules_to_schema']('ignored')

        self.assertNotIn('required', schema)
        self.assertEqual(schema['properties']['pull_request']['required'], ['base', 'title', 'body'])
        self.assertNotIn('required', schema['properties']['other'])
