"""Local-only native schedule isolation and shipped family contract checks."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import time
import unittest

from native_harness import NativeTestCase

import yaml

ROOT = Path(__file__).resolve().parents[1]
SWAMP = os.environ.get('SWAMP_BIN', 'swamp')


class OperationSchedulesTest(NativeTestCase):
    def test_direct_kinds_are_not_dispatched_by_polled_schedules(self):
        direct = set()
        workflows = [yaml.safe_load(path.read_text())
                     for path in ROOT.glob('workflows/workflow-operation-*.yaml')]
        for path in ROOT.glob('extensions/behaviors/operations/*/registration.json'):
            registration = json.loads(path.read_text())
            workflow = yaml.safe_load((ROOT / f'workflows/workflow-{registration["workflow"]}.yaml').read_text())
            if not workflow.get('trigger', {}).get('schedule'):
                direct.update(registration['kinds'])
        for workflow in workflows:
            if not workflow.get('trigger', {}).get('schedule'):
                continue
            for job in workflow['jobs']:
                for step in job['steps']:
                    kinds = step.get('task', {}).get('inputs', {}).get('kinds', [])
                    with self.subTest(workflow=workflow['name']):
                        self.assertFalse(direct.intersection(kinds))

    def test_every_resolved_behavior_name_fits_swamp_limit(self):
        # Swamp rejects definition names over 64 characters at run time, not at load.
        import re
        for path in sorted(ROOT.glob('workflows/*.yaml')):
            text = path.read_text()
            for prefix, mapping in re.findall(r'behaviorName:\s*\'\$\{\{\s*"([^"]+)"\s*\+\s*(\{[^}]*\})', text):
                for suffix in re.findall(r':"([^"]+)"', mapping):
                    with self.subTest(workflow=path.name, name=prefix + suffix):
                        self.assertLessEqual(len(prefix + suffix), 64)

    def test_every_batch_slot_name_fits_the_native_definition_limit(self):
        import re
        for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
            workflow = yaml.safe_load(path.read_text())
            name = workflow['jobs'][1]['steps'][0]['task']['behaviorName']
            prefix = re.fullmatch(r'\$\{\{ "([^"]+)" \+ string\(self.work.slot\) \}\}', name)
            self.assertIsNotNone(prefix, name)
            for slot in range(8):
                with self.subTest(family=workflow['name'], slot=slot):
                    self.assertLessEqual(len(prefix[1] + str(slot)), 64)

    def test_stage_summaries_remain_available_to_the_existing_driver(self):
        for path in ROOT.glob('workflows/workflow-stage-*.yaml'):
            with self.subTest(workflow=path.name):
                self.assertNotIn('@swamp/workflow-summary',
                                 yaml.safe_load(path.read_text()).get('reports', {}).get('skip', []))

    def test_family_models_use_native_report_selection_for_every_fixed_slot(self):
        definitions = {}
        ids = set()
        for path in ROOT.glob('models/@nckrtl/operation-*/*.yaml'):
            definition = yaml.safe_load(path.read_text())
            self.assertNotIn(definition['name'], definitions)
            self.assertNotIn(definition['id'], ids)
            definitions[definition['name']] = definition
            ids.add(definition['id'])
        for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
            workflow = yaml.safe_load(path.read_text())
            self.assertIn('@swamp/workflow-summary', workflow['reports']['skip'])
            discover = workflow['jobs'][0]['steps'][0]['task']
            execute = workflow['jobs'][1]['steps'][0]['task']
            prefix = execute['behaviorName'].split('"')[1]
            for name, model_type in [(discover['behaviorName'], discover['behaviorType']),
                                     *[(prefix + str(slot), execute['behaviorType']) for slot in range(8)]]:
                with self.subTest(model=name):
                    self.assertEqual(definitions[name]['type'], model_type)
                    self.assertIn('@swamp/method-summary', definitions[name]['reports']['skip'])

    def test_native_retention_keeps_measured_history_bound_and_current_data(self):
        marker = yaml.safe_load((ROOT / '.swamp.yaml').read_text())
        policy = marker['garbageCollection']
        self.assertTrue(marker['autoGc'])
        self.assertEqual(policy, {'automatic': True, 'retention': '30d', 'workflowRuns': '1d', 'outputs': '1d'})
        self.assertNotIn('data', policy)

    def test_native_four_same_kind_claims_enter_before_any_finishes(self):
        repo = self.repo
        extensions = repo / 'extensions/behaviors'
        self.copy_behaviors('automatic-deploy', 'operation-runner')
        discovery = extensions / 'operation-runner/mod.ts'
        discovery.write_text(discovery.read_text().replace('...operationDiscovery()', '''...operationDiscovery(() => async () => ({status:200,body:{data:{kinds:[
{kind:"fleet.cli_converge",claimable:4,oldest_at:null}]}}}))'''))
        folder = extensions / 'operations/fleet'
        folder.mkdir(parents=True)
        (folder / 'mod.ts').write_text('''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/operation-fleet",version:"2026.10.30.1",
resources:{receipt:{description:"Parallel claim proof",schema:z.object({worker:z.string()}),lifetime:"1d",garbageCollection:1}},
methods:{execute:{description:"Require four concurrent invocations",arguments:z.object({kind:z.string(),worker:z.string()}),execute:async(args,ctx)=>{
await Deno.mkdir("entered",{recursive:true});
await Deno.writeTextFile("entered/"+args.worker.split(":").at(-1),args.worker);
for(let i=0;;i++){
let count=0;for await(const _ of Deno.readDir("entered"))count++;
if(count===4)break;
if(i===200)throw new Error("batch_serialized_by_model_lock");
await new Promise(r=>setTimeout(r,20));
}
return {dataHandles:[await ctx.writeResource("receipt","proof",{worker:args.worker})]};}}}};
''')
        source = (ROOT / 'workflows/workflow-operation-fleet.yaml').read_text()
        source = '\n'.join(line for line in source.splitlines() if not line.startswith('labels:')) + '\n'
        (repo / 'workflows/workflow-operation-fleet.yaml').write_text(source)
        self.native_timeout = 40
        self.cli('workflow', 'run', 'operation-fleet')
        workers = [p.read_text() for p in (repo / 'entered').iterdir()]
        self.assertEqual(len(set(workers)), 4)
        self.assertEqual({w.rsplit(':', 1)[1] for w in workers}, {'0', '1', '2', '3'})

    def test_every_registered_family_has_an_independent_placed_schedule(self):
        self.assertFalse((ROOT / 'workflows/workflow-operation-runner.yaml').exists())
        self.assertFalse((ROOT / 'workflows/workflow-operation-dispatch.yaml').exists())
        seen = set()
        for path in sorted(ROOT.glob('extensions/behaviors/operations/*/registration.json')):
            registration = json.loads(path.read_text())
            self.assertFalse(seen.intersection(registration['kinds']))
            seen.update(registration['kinds'])
            workflow = yaml.safe_load((ROOT / f'workflows/workflow-{registration["workflow"]}.yaml').read_text())
            with self.subTest(family=workflow['name']):
                self.assertTrue(workflow['autoResume'])
                self.assertEqual(workflow['queueTimeout'], 60)
                seconds = list(map(int, workflow['trigger']['schedule'].split()[0].split(',')))
                self.assertIn(len(seconds), (1, 2))
                self.assertEqual([b - a for a, b in zip(seconds, seconds[1:])], [30] * (len(seconds) - 1))
                if workflow['name'] != 'operation-dependency-merge':
                    self.assertEqual(workflow['labels'], {'pool': 'migration'})
                discover, execute = workflow['jobs']
                task = discover['steps'][0]['task']
                self.assertEqual(task['behaviorType'], '@nckrtl/operation-discovery')
                self.assertEqual(task['inputs']['kinds'], registration['kinds'])
                self.assertEqual(task['inputs']['family'], workflow['name'])
                self.assertEqual(task['behaviorName'], workflow['name'] + '-discovery')
                self.assertEqual(execute['dependsOn'], [{'job': 'discover', 'condition': {'type': 'succeeded'}}])
                self.assertEqual(execute['steps'][0]['task']['inputs']['kind'], '${{ self.work.kind }}')
                self.assertNotIn('type: workflow', yaml.safe_dump(workflow))

    def test_pinned_native_loader_validates_all_family_workflows(self):
        repo = self.repo
        shutil.copytree(ROOT / 'extensions', repo / 'extensions')
        for path in ROOT.glob('workflows/workflow-operation-*.yaml'):
            shutil.copy(path, repo / 'workflows' / path.name)
        self.native_timeout = 30
        for path in sorted((repo / 'workflows').glob('*.yaml')):
            name = yaml.safe_load(path.read_text())['name']
            with self.subTest(family=name):
                self.cli('workflow', 'validate', name)

    def test_native_scheduler_runs_child_family_while_parent_family_is_in_progress(self):
        self.run_family_probe(False)

    def test_native_slow_discovery_does_not_lock_another_family(self):
        self.run_family_probe(True)

    def run_family_probe(self, block_discovery):
        repo = self.repo
        extensions = repo / 'extensions/behaviors'
        self.copy_behaviors('automatic-deploy', 'operation-runner')
        discovery = extensions / 'operation-runner/mod.ts'
        discovery.write_text(discovery.read_text().replace('...operationDiscovery()', '''...operationDiscovery(() => async () => ({ status:200, body:{data:{kinds:[
{kind:"fleet.cli_converge",claimable:1,oldest_at:null},{kind:"database.create",claimable:1,oldest_at:null}]}} }))'''))
        if block_discovery:
            with discovery.open('a') as source:
                source.write('\nconst discover=behavior.methods.discover.execute;\nbehavior.methods.discover.execute=async(args,ctx)=>{\nawait Deno.writeTextFile(args.family+"-discover-started","yes");\nif(args.family==="operation-fleet")await Deno.writeTextFile("parent-running","yes");\nconst other=args.family==="operation-fleet"?"operation-database":"operation-fleet";\nfor(let i=0;;i++){\ntry{await Deno.stat(other+"-discover-started");break}catch(e){if(!(e instanceof Deno.errors.NotFound))throw e}\nif(i>=500){await Deno.writeTextFile("discovery-failed","yes");throw new Error("discovery_blocked_sibling");}\nawait new Promise(r=>setTimeout(r,20));\n}\nreturn await discover(args,ctx);\n};\n')
        probe = '''import {z} from "npm:zod@4.4.3";
export const behavior={type:"@nckrtl/operation-FAMILY",version:"2026.10.30.1",
resources:{receipt:{description:"Scheduler isolation proof",schema:z.object({done:z.boolean()}),lifetime:"1d",garbageCollection:1}},
methods:{execute:{description:"Local test barrier",arguments:z.object({kind:z.string(),worker:z.string()}),execute:async(_args,ctx)=>{
const exists=async(p)=>{try{await Deno.stat(p);return true}catch(e){if(e instanceof Deno.errors.NotFound)return false;throw e}};
if("FAMILY"==="fleet")await Deno.writeTextFile("parent-running","yes");
const awaited="FAMILY"==="fleet"?"child-completed":"parent-running";
for(let i=0;!await exists(awaited);i++){if(i>=500){await Deno.writeTextFile("execution-failed","yes");throw new Error("family_dispatch_blocked");}await new Promise(r=>setTimeout(r,20))}
await Deno.writeTextFile("FAMILY"==="fleet"?"parent-completed":"child-completed",String(Date.now()));
return {dataHandles:[await ctx.writeResource("receipt","proof",{done:true})]};}}}};
'''
        for family in ('fleet', 'database'):
            folder = extensions / f'operations/{family}'
            folder.mkdir(parents=True)
            (folder / 'mod.ts').write_text(probe.replace('FAMILY', family))
            workflow = yaml.safe_load((ROOT / f'workflows/workflow-operation-{family}.yaml').read_text())
            workflow.pop('labels')  # Prove serve's scheduler, without a fleet or credentials.
            workflow['trigger']['schedule'] = '* * * * * *'
            (repo / f'workflows/workflow-operation-{family}.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        env = {k: v for k, v in os.environ.items() if not k.startswith('SWAMP_SERVER_') and k not in ('SWAMP_SERVE_URL', 'SWAMP_REPO_DIR')}
        env.update(SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for family in ('fleet', 'database'):
            self.native_timeout = 20
            self.cli('workflow', 'validate', 'operation-' + family)
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        with (repo / 'serve.log').open('w') as log:
            serve = subprocess.Popen([SWAMP, 'serve', '--host', '127.0.0.1', '--port', str(port),
                                      '--auth-mode', 'token', '--admins', 'user:fixture',
                                      '--max-concurrent-scheduled-runs', '32'],
                                     cwd=repo, env=env, stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 35
                while time.monotonic() < deadline:
                    if serve.poll() is not None:
                        self.fail((repo / 'serve.log').read_text()[-5000:])
                    if (repo / 'parent-completed').exists():
                        break
                    time.sleep(.1)
                self.assertTrue((repo / 'parent-completed').exists(), (repo / 'serve.log').read_text()[-5000:])
                self.assertFalse((repo / "discovery-failed").exists())
                self.assertFalse((repo / "execution-failed").exists())
                self.assertLessEqual(int((repo / 'child-completed').read_text()), int((repo / 'parent-completed').read_text()))
            finally:
                serve.terminate()
                try:
                    serve.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    serve.kill()
                    serve.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
