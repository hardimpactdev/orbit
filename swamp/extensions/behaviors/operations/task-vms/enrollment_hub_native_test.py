"""Exercise the enrollment DAG's Hub outcome gate with the shared native harness."""
import json
from pathlib import Path
import sys
import unittest

import yaml

sys.path.insert(0, str(Path(__file__).resolve().parents[4]))
from tests.native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[4]


class EnrollmentHubNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        directory = self.repo / 'extensions/behaviors/operations/task-vms'
        directory.mkdir(parents=True)
        (directory / 'enrollment_hub_fixture.ts').write_text('''
import {z} from "npm:zod@4.4.3";
async function record(event: string) {
  await Deno.writeTextFile("effects.jsonl", JSON.stringify(event) + "\\n", {append: true});
}
export const behavior = {
  type: "@nckrtl/node-wireguard-direct", version: "2026.10.31.1",
  resources: {outcome: {description: "Public fixture hub result", schema: z.record(z.string(), z.unknown()), lifetime: "1d", garbageCollection: 1}},
  methods: {
    publish: {description: "Return a public hub result", arguments: z.object({operation_id: z.number(), kind: z.string(), node_id: z.number(), run_id: z.string(), snapshot: z.record(z.string(), z.unknown())}), async execute(_args: unknown, ctx: any) {
      await record("publish-hub");
      const outcome = {schema_version: 1, hub_id: 1, status: Deno.env.get("HUB_OUTCOME")};
      await ctx.writeResource("outcome", "outcome", outcome);
      return outcome;
    }},
    probe: {description: "Record downstream fixture effects", arguments: z.object({phase: z.string()}), async execute(args: {phase: string}) {
      await record(args.phase); return {};
    }}
  }
};
''')
        workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-task-vm-enroll.yaml').read_text())
        hub = next(job for job in workflow['jobs'] if job['name'] == 'publish-hub')
        hub.pop('dependsOn')
        hub.pop('target')
        hub.pop('labels')
        hub['steps'][0]['task']['behaviorName'] = 'wireguard-hub-1'
        hub['steps'][0]['task']['inputs']['snapshot'] = dict(schema_version=1, executor_node_id=14,
            hub_id=1, context_node_id=212, excluded_node_id=None, agent_sha256='b' * 64)
        # Keep the real owning job dependencies, replacing guest effects with observable fixture leaves.
        jobs = [hub]
        for name in ('publish-network', 'complete'):
            job = next(job for job in workflow['jobs'] if job['name'] == name)
            job.pop('target')
            job.pop('labels')
            if name == 'complete':
                job['dependsOn'] = [dict(job='publish-network', condition=dict(type='succeeded'))]
            job['steps'] = [dict(name=name, writes=True, task=dict(type='behavior',
                behaviorType='@nckrtl/node-wireguard-direct', behaviorName='wireguard-hub-1',
                methodName='probe', inputs=dict(phase=name)))]
            jobs.append(job)
        workflow['jobs'] = jobs
        (self.repo / 'workflows/workflow-operation-task-vm-enroll.yaml').write_text(yaml.safe_dump(workflow))
        self.cli('workflow', 'validate', 'operation-task-vm-enroll')

    def test_failed_outcome_stops_guest_network_and_ready_even_when_publish_method_succeeds(self):
        for status in ('failed', 'succeeded'):
            with self.subTest(status=status):
                self.native_env['HUB_OUTCOME'] = status
                events = self.repo / 'effects.jsonl'
                events.unlink(missing_ok=True)
                self.cli('workflow', 'run', 'operation-task-vm-enroll', '--input', 'operation_id=7',
                         '--input', 'node_id=9', '--input', 'kind=task_vm.enroll', success=status == 'succeeded')
                self.assertEqual([json.loads(line) for line in events.read_text().splitlines()],
                    ['publish-hub'] if status == 'failed' else ['publish-hub', 'publish-network', 'complete'])


if __name__ == '__main__':
    unittest.main()
