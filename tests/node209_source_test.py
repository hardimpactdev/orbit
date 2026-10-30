"""Source deployment preserves live identities and native placement defaults."""
import pathlib
import subprocess
import unittest
import uuid
import yaml

ROOT = pathlib.Path(__file__).resolve().parents[1]


class Node209SourceTest(unittest.TestCase):
    def test_existing_resource_owner_ids_are_not_replaced(self):
        expected = {'models/@nckrtl/operation-certificates/operation-certificates-slot-0.yaml': '8741c5fb-900e-4599-b502-925cff638483', 'models/@nckrtl/operation-database/operation-database-slot-0.yaml': '5b3bb5bb-5d6f-4cea-b9e6-b913837fa587', 'models/@nckrtl/operation-integrations/operation-integrations-slot-0.yaml': '72da35b2-215c-45ae-9e63-09508c0f622b', 'models/@nckrtl/operation-node/operation-node-slot-1.yaml': '84a07d64-18ed-401a-b7c6-29bf09dace27', 'models/@nckrtl/operation-routes/operation-routes-slot-2.yaml': '0257781a-a7e4-4330-9993-7e982f4445cf'}
        for path, identity in expected.items():
            self.assertEqual(yaml.safe_load((ROOT/path).read_text())['id'], identity, path)

    def test_models_have_unique_native_ids_and_project_definitions_are_public_metadata(self):
        identities = {}
        projects = []
        for path in (ROOT/'models').rglob('*.yaml'):
            definition = yaml.safe_load(path.read_text())
            identity = str(uuid.UUID(definition['id']))
            self.assertNotIn(identity, identities, (path, identities.get(identity)))
            identities[identity] = str(path)
            if '/@nckrtl/project-' in str(path):
                projects.append(definition['name'])
                self.assertEqual(definition['globalArguments'], {})
                self.assertEqual(definition['methods'], {})
        self.assertEqual(len(projects), 19)

    def test_no_unmeasured_top_level_queue_timeout_override(self):
        for path in (ROOT/'workflows').glob('*.yaml'):
            definition = yaml.safe_load(path.read_text())
            if definition['name'] == 'operation-task-vm-dispatch':
                # Existing #162 native unclaimed-child bound; not a local override.
                self.assertEqual(definition['queueTimeout'], 60)
            else:
                self.assertNotIn('queueTimeout', definition, path.name)

    def test_runtime_credentials_and_generated_records_are_ignored(self):
        paths = ['models/swamp/enrollment-token/example.yaml', 'vaults/local_encryption/example.yaml', 'bin/__pycache__/deploy-services.pyc']
        result = subprocess.run(['git','check-ignore','--no-index',*paths], cwd=ROOT, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        self.assertEqual(set(result.stdout.splitlines()), set(paths))


if __name__ == '__main__':
    unittest.main()
