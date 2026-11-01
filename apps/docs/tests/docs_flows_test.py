"""The docs build attaches each flow chart to the CLI commands whose route starts its kind."""
import ast
import importlib.machinery
import importlib.util
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
loader = importlib.machinery.SourceFileLoader('docs_flows', str(ROOT / 'bin/docs-flows'))
flows = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
loader.exec_module(flows)


class DocsFlowsTest(unittest.TestCase):
    def test_marker_kinds_resolve_literals_constants_and_flag_kinds(self):
        source = ast.parse((ROOT / 'bin/docs-openapi').read_text())
        function = next(n for n in source.body if isinstance(n, ast.FunctionDef) and n.name == 'marker_kinds')
        namespace = {'re': re}
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<marker_kinds>', 'exec'), namespace)
        kinds = namespace['marker_kinds']

        self.assertEqual(['firewall.allow'], kinds("'firewall.allow'", {}))
        self.assertEqual(['instance.deploy'], kinds('OperationKinds::InstanceDeploy, requiresKey: true', {'InstanceDeploy': 'instance.deploy'}))
        self.assertEqual(['node.role_add', 'node.role_converge'], kinds("'node.role_add', kindWhenTrue: ['converge_existing' => 'node.role_converge']", {}))

    def test_only_charted_kinds_of_documented_commands_attach(self):
        spec = {'paths': {
            '/a': {'post': {'operationId': 'firewall-allow', 'x-orbit-kinds': ['firewall.allow']}},
            '/b': {'post': {'operationId': 'node-ssh-access-converge', 'x-orbit-kinds': ['node.ssh_access_converge']}},
            '/c': {'post': {'operationId': 'route-target-unset', 'x-orbit-kinds': ['route.target_unset']}},
            '/d': {'get': {'operationId': 'firewall-list'}},
        }}
        headings = {'firewall:allow', 'route:target:unset', 'firewall:list'}
        charts = {'firewall.allow': '', 'node.ssh_access_converge': ''}

        self.assertEqual({'firewall:allow': ['firewall.allow']}, flows.attachments(spec, headings, charts))


if __name__ == '__main__':
    unittest.main()
