"""Real native input routing, with backend methods stubbed; no Gateway or Node calls."""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
CLI = os.environ.get('SWAMP_BIN', 'swamp')


class ProjectWorkflowsNativeTest(unittest.TestCase):
    def test_shared_entrypoints_route_two_projects_and_reject_missing_intent(self):
        with tempfile.TemporaryDirectory(prefix='project-entrypoints-') as tmp:
            repo = Path(tmp)
            shutil.copy(ROOT / '.swamp.yaml', repo)
            ext = repo / 'extensions/behaviors'
            ext.mkdir(parents=True)
            workflows = repo / 'workflows'
            workflows.mkdir()
            for mode, kind, method in (
                ('detect', '@nckrtl/dependency-scanner', 'scan'),
                ('routine', '@nckrtl/orbit-gateway', 'schedule_project'),
            ):
                # Only backend effects are a fixture. Use the shipped workflow and
                # native model-name/input evaluation; assert placement before running locally.
                workflow = yaml.safe_load((ROOT / f'workflows/workflow-project-{mode}.yaml').read_text())
                for job in workflow['jobs']:
                    for step in job['steps']:
                        self.assertEqual(step.pop('target'), 'orbit-node-209')
                        self.assertEqual(step.pop('labels'), {'pool': 'migration', 'node': '209'})
                        self.assertNotEqual(step['task']['modelType'], '@nckrtl/native-host-method')
                (workflows / f'workflow-project-{mode}.yaml').write_text(yaml.safe_dump(workflow))
                (ext / f'{mode}.ts').write_text('''import {z} from "npm:zod@4";
export const behavior = {
  type: KIND, version:"2026.11.01.1", globalArguments:z.object({}),
  resources:{observed:{description:"Public fixture input", schema:z.object({projectId:z.number().int().positive(),runId:z.string().optional(),mode:z.string().optional()}),lifetime:"1h",garbageCollection:4}},
  methods:{METHOD:{description:"Record routed input only",arguments:z.object({projectId:z.number().int().positive(),runId:z.string().optional(),mode:z.string().optional()}),
    execute:async(args,ctx)=>({dataHandles:[await ctx.writeResource("observed","observed",args)]})}}
};
'''.replace('KIND', json.dumps(kind)).replace('METHOD', method))
            env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
            for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                        'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
                env.pop(key, None)

            def cli(*args, success=True):
                result = subprocess.run([CLI, *args, '--json'], cwd=repo, env=env,
                                        capture_output=True, text=True, timeout=60)
                self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
                return json.loads(result.stdout) if success else {}

            for mode in ('detect', 'routine'):
                cli('workflow', 'validate', f'project-{mode}')
                for project in (16, 49):
                    run = cli('workflow', 'run', f'project-{mode}', '--input', f'projectId={project}')
                    self.assertEqual(run['status'], 'succeeded')
                    records = cli('data', 'query', 'name == "observed"', '--select', 'attributes')['results']
                    routed = [row for row in records if row['projectId'] == project]
                    self.assertTrue(routed)
                    if mode == 'detect':
                        self.assertTrue(any(row.get('runId') == run['id'] for row in routed))
                    else:
                        self.assertTrue(any(row.get('mode') == 'routine' for row in routed))
                cli('workflow', 'run', f'project-{mode}', success=False)
                cli('workflow', 'run', f'project-{mode}', '--input', 'projectId=0', success=False)


if __name__ == '__main__':
    unittest.main()
