"""The copy check of a publication through the production Ansible phase, its Incus boundary and the typed reader; made-up values only."""
import json
import runpy
import subprocess
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))
ROOT = SUPPORT['ROOT']
SECRET = 'made-up-inner-gateway-token-0123456789'


class TaskInstanceWorkspaceScanTest(SUPPORT["WorkspaceHarness"]):
    def place(self, path, text):
        target = Path(str(self.root / 'copy-root') + path)
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(text)

    def scan(self, nested=True):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Candidate')
        self.intent.update({'head': self.git('rev-parse', 'HEAD'), 'start': self.git('rev-parse', 'HEAD~1'), 'nested': nested,
                            'proposed_body_path': '.git/orbit/proposed-pr-bodies/' + self.intent['branch'] + '.md'})
        value, output = self.phase('scan')
        self.assertNotIn(SECRET, output)
        self.assertNotIn(SECRET, json.dumps(value))
        return value

    def read(self, value):
        """The publish's typed read of the evidence, as the workflow's "Read the copy check" runs it."""
        script = self.root / 'scan.ts'
        source = ROOT / 'extensions/behaviors/operations/task-repository/publication.ts'
        script.write_text('import { behavior } from ' + json.dumps(source.as_uri()) + ';\n'
                          'try { const v = await behavior.methods.scan.execute({evidence: JSON.parse(Deno.args[0])}, '
                          '{writeResource: async (_s, _n, value) => value}); console.log(JSON.stringify(v.dataHandles[0])); }'
                          ' catch (e) { console.log(JSON.stringify({ refused: (e as Error).message })); }')
        done = subprocess.run(['deno', 'run', '-A', str(script), json.dumps({'phase': 'scan', 'evidence': value})],
                              capture_output=True, text=True, check=True, timeout=60)
        return json.loads(done.stdout)

    def setUp(self):
        super().setUp()
        # The phase reads the program beside its playbook, as the shipped assets hold it.
        (self.root / 'task_copy_scan.py').write_text((ROOT / 'ansible/task_copy_scan.py').read_text())
        gateway = '/var/lib/incus/storage-pools/inner/containers/nested-gateway/rootfs'
        self.place(gateway + '/var/lib/orbit-checkout-state/apps/gateway/.env', f'ORBIT_SWAMP_RUN_TOKEN={SECRET}\n')

    def test_a_secret_of_the_inner_gateway_refuses_by_path_line_and_name(self):
        (self.checkout / 'notes.txt').write_text('one\ntwo ' + SECRET + '\n')
        value = self.scan()

        self.assertEqual(value['scan']['secrets'], [{'path': 'notes.txt', 'line': 2,
            'secret': 'nested-gateway:env:/var/lib/orbit-checkout-state/apps/gateway/.env:ORBIT_SWAMP_RUN_TOKEN'}])
        self.assertEqual(self.read(value), {'refused': 'tasks.publish_secret_value: "notes.txt:2 '
            'nested-gateway:env:/var/lib/orbit-checkout-state/apps/gateway/.env:ORBIT_SWAMP_RUN_TOKEN"'})

    def test_a_workflow_change_is_no_refusal_of_the_copy_check(self):
        # The publish's base read judges it against the real default head (task_copy_publish_base_test.py).
        (self.checkout / '.github/workflows').mkdir(parents=True)
        (self.checkout / '.github/workflows/x.yml').write_text('on: push\n')
        value = self.scan()

        self.assertNotIn('workflows', value['scan'])
        self.assertIs(self.read(value)['checked'], True)

    def test_a_clean_change_passes_with_public_facts_only(self):
        (self.checkout / 'docs.md').write_text('one line\n')
        read = self.read(self.scan())

        self.assertEqual((read['checked'], read['sources'], read['values']), (True, ['nested-gateway:env'], 1))

    def test_a_nested_copy_without_its_inner_machines_is_unchecked(self):
        import shutil
        shutil.rmtree(self.root / 'copy-root' / 'var')
        (self.checkout / 'docs.md').write_text('one line\n')

        self.assertEqual(self.read(self.scan()), {'refused': 'tasks.publish_secret_unchecked: the nested copy shows no inner machine'})


if __name__ == '__main__':
    unittest.main()
