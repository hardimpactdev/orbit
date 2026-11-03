import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../tests/_support/native_harness.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
/** Every job and step of a tracked workflow is placed on the swamp role. */
function assertSwampLabels(name: string) {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${name}.yaml`)) as {
    jobs: { labels?: unknown; steps: { labels?: unknown }[] }[];
  };
  for (const job of workflow.jobs) {
    assertEquals(job.labels, { role: "swamp" }, name);
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" }, name);
  }
}

Deno.test("tasks.provision's default queue budget covers a loaded dispatch", () => {
  const workflow = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-provision.yaml`)) as {
    queueTimeout: number;
  };
  assertEquals(workflow.queueTimeout, 30);
  for (
    const name of [
      "task-vm-provision",
      "operation-task-vm-provision",
      "task-vm-ssh-prepare",
      "task-vm-incus-first-visit",
      "task-vm-enrollment",
    ]
  ) assertSwampLabels(name);
});
