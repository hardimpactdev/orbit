import { assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
import { parse } from "jsr:@std/yaml@1.0.12";
import { bindHostKeys } from "../task-repository/provision.ts";
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

Deno.test("the workspace steps accept the receipt that a slot claim returns, after the Operation's JSON round trip", () => {
  // The Gateway builds this receipt in TaskVmSlotClaim::capture(); `process` names the slot's pi-server Process.
  const receipt = JSON.parse(JSON.stringify({
    task_vm_id: 7,
    guest_node_id: 31,
    ready: true,
    provider: { provider_id: null, address: "10.251.77.253", image_fingerprint: "c1c2cf4051fb0123", running: true, settled: true },
    enrollment: {
      ready: true,
      node: { ssh_host_key_type: "ssh-ed25519", ssh_host_key: "AAAAC3NzaC1lZDI1NTE5AAAAIEQ9Q5+auc2KFs6ebCBB3J9ROM+aIRnhyJwafKXHSCWT" },
      process: { process_id: 12, status: "active" },
    },
  }));
  const bound = bindHostKeys({ target: { host_keys: null } }, receipt, 31);
  assertEquals(bound.target.host_keys, ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEQ9Q5+auc2KFs6ebCBB3J9ROM+aIRnhyJwafKXHSCWT"]);
  // An empty `process` is what an empty object becomes in the database: the step rejects it.
  let rejected = false;
  try {
    bindHostKeys({}, { ...receipt, enrollment: { ...receipt.enrollment, process: [] } }, 31);
  } catch {
    rejected = true;
  }
  assertEquals(rejected, true);
});
