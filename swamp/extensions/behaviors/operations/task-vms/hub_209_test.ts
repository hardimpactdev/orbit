import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
const Name = "operation-task-vm-hub-converge";

Deno.test("UFW rules run one at a time under names by hub", () => {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${Name}.yaml`)) as {
    jobs: { steps: { name: string; labels: unknown; concurrency?: number; task: { type: string; behaviorName?: string } }[] }[];
  };
  const steps = workflow.jobs[0].steps;
  for (const step of steps) assertEquals(step.labels, { role: "swamp" });
  assertEquals(steps.find((step) => step.name === "Allow 209 SSH, Router web")!.concurrency, 1);
  const names = steps.filter((step) => step.task.type === "behavior").map((step) => step.task.behaviorName!);
  assert(names.every((name) => name.includes("inputs.intent.node_id") && !/run\.id|operation_id/.test(name)));
  assertEquals(new Set(names).size, names.length);
});
