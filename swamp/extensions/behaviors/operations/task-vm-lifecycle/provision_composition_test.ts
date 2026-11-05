import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
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
      "task-vm-slot-claim",
      "task-vm-nested-reset",
    ]
  ) assertSwampLabels(name);
});

Deno.test("tasks.provision runs the slot claim for a slot and the enrolment for any other VM", () => {
  const parent = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-provision.yaml`)) as {
    jobs: { steps: { name: string; task: { workflowIdOrName?: string } }[] }[];
  };
  const step = parent.jobs[0].steps.find((item) => item.name === "Create and prepare the VM")!;
  assertEquals(
    step.task.workflowIdOrName,
    '${{ inputs.intent.placement == "slot" ? "task-vm-slot-claim" : "task-vm-provision" }}',
  );
  const claim = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-task-vm-slot-claim.yaml`)) as {
    jobs: { steps: { name: string; guard?: string; task: { workflowIdOrName?: string } }[] }[];
  };
  assertEquals(claim.jobs[0].steps.map((item) => item.name), [
    "Boot the VM",
    "Check the slot VM",
    "Fetch the task branch",
    "Reset the nested topology",
    "result",
  ]);
  // The nested reset is skipped while the Gateway setting is off, and it calls the one workflow that lands with B3/B4.
  const reset = claim.jobs[0].steps.find((item) => item.name === "Reset the nested topology")!;
  assertEquals(reset.guard, "${{ inputs.intent.slot_claim.nested_reset == false }}");
  assertEquals(reset.task.workflowIdOrName, "task-vm-nested-reset");
});
