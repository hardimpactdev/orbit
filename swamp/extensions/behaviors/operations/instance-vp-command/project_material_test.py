"""Capture does not treat unrelated importer links as project-root dependencies."""
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
spec=importlib.util.spec_from_file_location('material',Path(__file__).parent/'scripts/project_material.py')
material=importlib.util.module_from_spec(spec);spec.loader.exec_module(material)
class ProjectMaterial(unittest.TestCase):
 def test_non_vp_pnpm_workspace_link_does_not_refuse(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp)/'project';root.mkdir()
   (root/'package.json').write_text('{"name":"monorepo"}')
   (root/'pnpm-lock.yaml').write_text('importers:\n  apps/web:\n    dependencies:\n      shared:\n        version: link:../../packages/shared\n')
   destination=Path(temp)/'material.json'
   self.assertTrue(material.capture({'application':str(root),'destination':str(destination)})['ready'])
   self.assertEqual(json.loads(destination.read_text())['workspaces'],{})
 def test_root_vp_link_captures_its_actual_version(self):
  with tempfile.TemporaryDirectory() as temp:
   root=Path(temp)/'project';root.mkdir();(root/'vp').mkdir()
   (root/'package.json').write_text('{"devDependencies":{"vite-plus":"workspace:*"}}')
   (root/'pnpm-lock.yaml').write_text('importers:\n  .:\n    devDependencies:\n      vite-plus:\n        version: link:vp\n  apps/web:\n    dependencies:\n      shared:\n        version: link:../../packages/shared\n')
   (root/'vp/package.json').write_text('{"name":"vite-plus","version":"0.2.1"}')
   destination=Path(temp)/'material.json'
   material.capture({'application':str(root),'destination':str(destination)})
   self.assertEqual(json.loads(destination.read_text())['workspaces'],{'link:vp':'0.2.1'})
if __name__=='__main__':unittest.main()
