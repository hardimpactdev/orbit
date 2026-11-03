/** Disposable official SSH provider lifecycle; never contacts a fleet Node. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { lastOutputs, taskVmLifecycle } from "../../../../tests/_support/task_vm_lifecycle.ts";

const count = (calls: string[][], word: string) => calls.filter((argv) => argv.includes(word)).length;

Deno.test("power start, stop and absent delete are idempotent", async () => {
  await using vm = await taskVmLifecycle();
  await vm.repo.cli(["workflow", "validate", "task-vm-provider-power"]);
  for (const action of ["start", "start", "stop", "stop", "delete", "delete"]) {
    vm.envelope.action = action;
    const { run, history } = await vm.run();
    assertEquals(run.status, "succeeded");
    const receipt = lastOutputs(history);
    assertEquals(receipt.action, action);
    assert(receipt.settled);
  }
  const calls = vm.argvCalls();
  assertEquals([count(calls, "start"), count(calls, "stop"), count(calls, "delete")], [1, 1, 1]);
});

Deno.test("a delayed start waits for running without repeating the start write", async () => {
  await using vm = await taskVmLifecycle();
  await vm.writeState({ status: "Stopped", exists: true, remaining_start_reads: 1 });
  const { run, history } = await vm.run();
  assertEquals(run.status, "succeeded");
  assert(lastOutputs(history).running);
  assertEquals(count(vm.argvCalls(), "start"), 1);
  assertEquals((await vm.readState()).status, "Running");
});

Deno.test("a start timeout fails and native resume does not repeat the start", async () => {
  await using vm = await taskVmLifecycle();
  await Deno.writeTextFile(vm.pending, "");
  const { run } = await vm.run("task-vm-provider-power", false);
  assertEquals(run.status, "failed");
  await Deno.remove(vm.pending);
  await vm.writeState({ exists: true, status: "Running" });
  const resumed = JSON.parse(
    (await vm.repo.cli([
      "workflow",
      "resume",
      "task-vm-provider-power",
      "--run",
      run.id,
      "--from",
      "incus-after",
      "--job",
      "provider",
    ])).stdout,
  );
  assertEquals(resumed.status, "succeeded");
  assertEquals(count(vm.argvCalls(), "start"), 1);
});

Deno.test("a lost action reply uses the actual power observation", async () => {
  await using vm = await taskVmLifecycle();
  await Deno.writeTextFile(vm.lost, "");
  const { run } = await vm.run();
  assertEquals(run.status, "succeeded");
  assertEquals((await vm.readState()).status, "Running");
});

Deno.test("the park root stops the VM, keeps the original envelope and refuses an ineligible VM", async () => {
  await using vm = await taskVmLifecycle();
  delete vm.envelope.action;
  vm.envelope.kind = "tasks.park";
  vm.envelope.intent = {
    task_vm_id: 42,
    guest_node_id: 142,
    group_id: 51,
    instance_id: 61,
    project_id: 71,
    eligible: true,
    provider_input: vm.provider,
  };
  await vm.writeState({ exists: true, status: "Running" });
  const { run: parked, history, steps } = await vm.run("operation-task-park");
  assertEquals(parked.status, "succeeded");
  assertEquals(history.inputs.node_id, 99);
  assertEquals(history.inputs.kind, "tasks.park");
  assertEquals(steps.provider, "succeeded");
  // The Gateway projects the park result from the run; the workflow keeps no receipt step.
  assertEquals((await vm.readState()).status, "Stopped");
  vm.envelope.intent.eligible = false;
  const { run } = await vm.run("operation-task-park", false);
  assertEquals(run.status, "failed");
});
