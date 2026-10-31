"""Run the workflow's actual inline filesystem actions against disposable trees."""
import ast
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

import yaml

WORKFLOW = Path(__file__).parents[1] / 'workflows/workflow-operation-metrics-fleet.yaml'


def script(name):
    workflow = yaml.safe_load(WORKFLOW.read_text())
    job = next(j for j in workflow['jobs'] if j['name'] == name)
    run = job['steps'][0]['task']['inputs']['run']
    return run.split("<<'PYTHON'\n", 1)[1].rsplit('\nPYTHON', 1)[0]


class ExporterFiles(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.addCleanup(self.temporary.cleanup)
        self.intent = {'path': '/etc/systemd/system/orbit-cadvisor.service',
                       'marker': '# Managed by Orbit: metrics-cadvisor\n',
                       'configuration': '# Managed by Orbit: metrics-cadvisor\n[Service]\n',
                       'selected': True}

    def run_action(self, name):
        tree = ast.parse(script(name))
        replacements = 0
        for node in tree.body:
            if isinstance(node, ast.Assign) and any(isinstance(t, ast.Name) and t.id == 'root' for t in node.targets):
                call = node.value
                if isinstance(call, ast.Call) and isinstance(call.func, ast.Attribute) and call.func.attr == 'Path' and len(call.args) == 1 and isinstance(call.args[0], ast.Constant) and call.args[0].value == '/':
                    call.args[0] = ast.Constant(str(self.root))
                    replacements += 1
        self.assertEqual(replacements, 1, 'Refuse to execute an action without its disposable filesystem root')
        program = ast.unparse(ast.fix_missing_locations(tree))
        return subprocess.run(['/usr/bin/python3', '-c', program], text=True, capture_output=True,
                              env={**os.environ, 'ORBIT_EXPORTER_INTENT': json.dumps(self.intent)})

    def test_publish_and_inspect_actual_file(self):
        result = self.run_action('publish-exporter-configuration')
        self.assertEqual(result.returncode, 0, result.stderr)
        target = self.root / self.intent['path'].lstrip('/')
        self.assertEqual(target.read_text(), self.intent['configuration'])
        self.assertEqual(target.stat().st_mode & 0o777, 0o644)
        before = self.run_action('inspect-exporter-files')
        self.assertEqual(json.loads(before.stdout)['configuration'], self.intent['configuration'])

    def test_refuse_foreign_file_without_writing(self):
        target = self.root / self.intent['path'].lstrip('/')
        target.parent.mkdir(parents=True)
        target.write_text('foreign\n')
        result = self.run_action('publish-exporter-configuration')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(target.read_text(), 'foreign\n')

    def test_refuse_symlink_parent_and_keep_referent(self):
        foreign = self.root / 'foreign'
        foreign.mkdir()
        (self.root / 'etc').mkdir()
        (self.root / 'etc/systemd').symlink_to(foreign, target_is_directory=True)
        result = self.run_action('publish-exporter-configuration')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(list(foreign.iterdir()), [])

    def test_remove_only_owned_unit(self):
        self.assertEqual(self.run_action('publish-exporter-configuration').returncode, 0)
        self.intent['selected'] = False
        self.assertEqual(self.run_action('publish-exporter-configuration').returncode, 0)
        self.assertFalse((self.root / self.intent['path'].lstrip('/')).exists())

    def test_refuse_writable_parent_before_mutation(self):
        parent = self.root / 'etc/systemd/system'
        parent.mkdir(parents=True)
        parent.chmod(0o777)
        result = self.run_action('publish-exporter-configuration')
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((parent / 'orbit-cadvisor.service').exists())


if __name__ == '__main__':
    unittest.main()
