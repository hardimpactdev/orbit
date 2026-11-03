/** Native root failure is retained without a step-side Gateway callback. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../tests/_support/native_harness.ts";
Deno.test("failed family retains exact native root without a failure reporter", async () => {
  await using repo = await nativeRepo();
  await repo.copyBehaviors("operations/tools", "operations/node-bootstrap");
  await repo.pull("@swamp/ssh");
  await Deno.writeTextFile(
    `${repo.dir}/extensions/behaviors/rejected.ts`,
    "import {z} from 'npm:zod@4.4.3';export const behavior={type:'@nckrtl/fixture-rejected',version:'2026.11.01.1',methods:{apply:{description:'Refuse before the first effect',arguments:z.object({}).passthrough(),execute:()=>{throw new Error('fixture pre-effect failure');}}}};",
  );
  const source = parse(
    await Deno.readTextFile(
      `${ROOT}/workflows/workflow-operation-tool-write-209.yaml`,
    ),
  ) as { jobs: { labels?: unknown; steps: { labels: unknown }[] }[] };
  for (const job of source.jobs) {
    assert(!("labels" in job));
    for (const step of job.steps) {
      assertEquals(step.labels, { role: "swamp" });
    }
  }
  await repo.workflow("operation-tool-write-209", (flow) => {
    for (const job of flow.jobs) {
      for (const step of job.steps) {
        if (step.name === "Prepare package commands") {
          step.task = {
            type: "behavior",
            behaviorType: "@nckrtl/fixture-rejected",
            behaviorName: "rejected-${{ run.id }}",
            behaviorVersion: "2026.11.01.1",
            methodName: "apply",
          };
        }
      }
    }
  });
  const inputs = {
    "operation_id": 71,
    "node_id": 209,
    "kind": "tool.install",
    "execution_secrets": {},
    "intent": {
      "kind": "tool.install",
      "node_id": 209,
      "manager": "apt",
      "package": "hello",
      "user": "orbit",
      "platform": "linux",
      "architecture": "x86_64",
      "setup": null,
    },
    "ssh": {
      "control_node_id": 209,
      "identity_file": "/unused/fixture",
      "target": {
        "name": "target",
        "host": "node.invalid",
        "port": 22,
        "user": "orbit",
        "host_keys": [
          "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG",
        ],
      },
    },
  };
  const reply = await repo.cli([
    "workflow",
    "run",
    "operation-tool-write-209",
    "--stdin",
  ], { input: JSON.stringify(inputs), success: false });
  assert(reply.stdout.trim(), reply.stderr);
  const run = JSON.parse(reply.stdout);
  const history = JSON.parse(
    (await repo.cli(["workflow", "history", "get", run.id])).stdout,
  );
  assertEquals(history.status, "failed");
  assertEquals(history.inputs, inputs);
  assertEquals(history.jobs.map((j: { name: string }) => j.name), ["tools"]);
  const steps = Object.fromEntries(
    history.jobs[0].steps.map((s: { name: string }) => [s.name, s]),
  );
  assertEquals(steps["Prepare package commands"].status, "failed");
  assertEquals(steps["Prepare package commands"].error, "fixture pre-effect failure");
  assert(!("result" in steps));
});
