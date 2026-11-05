import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { ROOT } from "../../../../bin/gate-test-support.ts";
import { parse } from "jsr:@std/yaml@1.0.12";

type Step = { name: string; labels?: unknown; guard?: string; writes?: boolean; dependsOn?: { step?: string; job?: string }[]; task: Record<string, unknown> };
type Workflow = { name: string; queueTimeout: number; vaults?: string[]; inputs: { required: string[] }; jobs: { name: string; labels?: unknown; steps: Step[] }[] };

const read = (name: string) => parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${name}.yaml`)) as Workflow;

Deno.test("the slot release runs the steps of its chart, on the swamp role, with no secret", () => {
  const workflow = read("operation-task-vm-slot-release");
  assertEquals(workflow.queueTimeout, 60);
  // The release rotates nothing: no vault, and the Gateway sends an empty `execution_secrets`.
  assertEquals(workflow.vaults, undefined);
  assert(!Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-vm-slot-release.yaml`).includes("vault.get"));
  const steps = workflow.jobs.flatMap((job) => job.steps);
  assertEquals(steps.map((step) => step.name), ["Delete the VM", "Delete the seed clone", "Read the host again", "Check the slot is clean"]);
  for (const step of steps) assertEquals(step.labels, { role: "swamp" }, step.name);
  // Both deletes are forced writes that count an absent resource as done; the read and the assert write nothing.
  for (const name of ["Delete the VM", "Delete the seed clone"]) {
    const step = steps.find((item) => item.name === name)!;
    assertEquals(step.writes, true);
    const inputs = step.task.inputs as { okExitCodes: number[]; argv: string[] };
    assertEquals(inputs.okExitCodes, [0, 1]);
    assert(inputs.argv.includes("delete"));
  }
  // The VM goes first, so the clone volume is free to delete.
  assertEquals(steps[1].dependsOn?.[0].step, "Delete the VM");
});

Deno.test("the slot release deletes the VM and the seed clone only, and asserts the identity volume is still listed", () => {
  const text = Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-vm-slot-release.yaml`);
  const argv = [...text.matchAll(/argv: \[(.*)\]/g)].map((match) => match[1]);
  assertEquals(argv.length, 2);
  assert(argv[0].includes("delete, --force"));
  assert(argv[1].includes("storage, volume, delete") && argv[1].includes("seed_clone"));
  // No step names the identity volume as a target of a delete.
  assert(!argv.some((line) => line.includes("identity_volume")));
  const check = read("operation-task-vm-slot-release").jobs.flatMap((job) => job.steps).find((step) => step.name === "Check the slot is clean")!;
  const expr = check.task.expr as string;
  assert(expr.includes("inputs.intent.vm.identity_volume"));
  assert(expr.includes("inputs.intent.vm.seed_clone") && expr.includes("inputs.intent.vm.name"));
});

Deno.test("tasks.cancel does not destroy a slot, and its end check no longer asks for a destroyed VM", () => {
  const text = Deno.readTextFileSync(`${ROOT}/workflows/workflow-operation-task-cancel.yaml`);
  const steps = read("operation-task-cancel").jobs.flatMap((job) => job.steps);
  const destroy = steps.find((step) => step.name === "Destroy the task VM")!;
  assert(destroy.guard!.includes("inputs.intent.?slot.orValue(false)"));
  assert(text.includes("TASK_VM: ${{ string(inputs.intent.task_vm_id != null && !inputs.intent.?slot.orValue(false)) }}"));
  // The skipped destroy step has no entry in `steps`: reading it for a slot fails the run, so the slot test must come first.
  const vm = text.split("\n").find((line) => line.trimStart().startsWith("VM: "))!;
  assert(vm.indexOf("inputs.intent.?slot.orValue(false)") !== -1);
  assert(vm.indexOf("inputs.intent.?slot.orValue(false)") < vm.indexOf('steps["Destroy the task VM"]'));
});
