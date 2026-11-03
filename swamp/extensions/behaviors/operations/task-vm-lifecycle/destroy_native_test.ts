/**
 * The destroy child and root against a fake Incus host behind official SSH; model HTTP and Node removal
 * are real graphs or fixtures.
 *
 * The fake `ssh` runs nothing remote: it checks that every host command is `sudo -n -- timeout N incus …`,
 * answers from a JSON state file and logs each argv, so the test sees the exact commands and their order.
 */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { type TaskVmLifecycle, taskVmLifecycle } from "../../../../tests/_support/task_vm_lifecycle.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;

const fakeHost = (paths: Record<string, string>) => `
import { logCall, shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
const P = ${JSON.stringify(paths)};
const args = shellSplit(Deno.args.at(-1));
using lock = Deno.openSync(P.state + ".lock", { write: true, create: true });
lock.lockSync(true);
logCall(P.calls, { argv: args });
try { Deno.statSync(P.block); Deno.exit(255); } catch (error) { if (!(error instanceof Deno.errors.NotFound)) throw error; }
if (args.slice(0, 4).join(" ") !== "sudo -n -- timeout" || args[5] !== "incus") throw new Error(args.join(" "));
const value = JSON.parse(Deno.readTextFileSync(P.state));
const command = args.slice(6);
const url = (base, name) => base + "/" + name + "?project=tasks";
if (command[0] === "query") {
  const parsed = new URL(command[1], "http://incus");
  if (parsed.search !== "?project=tasks") throw new Error(command.join(" "));
  if (parsed.pathname === "/1.0/instances") console.log(JSON.stringify(value.exists ? [url(parsed.pathname, "tvm-42")] : []));
  else if (parsed.pathname === "/1.0/storage-pools/tasks/volumes/custom") {
    console.log(JSON.stringify(value.volumes.map((name) => url(parsed.pathname, name))));
  } else Deno.exit(9);
  Deno.exit(0);
}
if (command.slice(0, 4).join(" ") === "delete --force --project tasks" && command[4] === "--") {
  if (!value.exists) { console.error("Error: Instance not found"); Deno.exit(1); }
  if (!value.stuck) value.exists = false;
} else if (command.slice(0, 5).join(" ") === "storage volume delete --project tasks" && command.slice(5, 7).join(" ") === "-- tasks") {
  if (!value.volumes.includes(command[7])) { console.error("Error: Storage volume not found"); Deno.exit(1); }
  if (value.exists) { console.error("Error: Storage volume is in use"); Deno.exit(1); }
  value.volumes = value.volumes.filter((name) => name !== command[7]);
} else Deno.exit(9);
Deno.writeTextFileSync(P.state, JSON.stringify(value));
`;

type Step = { name: string; labels?: unknown; task: Record<string, unknown> & { inputs?: Record<string, unknown> } };

async function fixture(state: Record<string, unknown> = { exists: true, volumes: ["tvm-42-topology-seed", "tvm-43-topology-seed"] }) {
  const vm = await taskVmLifecycle();
  await vm.writeState(state);
  const host = await vm.repo.fakeBinary(
    "incus-host",
    fakeHost({ state: vm.state, calls: vm.calls, block: vm.block }),
  );
  for (const name of ["task-vm-destroy", "operation-task-vm-destroy", "node-role-remove", "operation-node-remove"]) {
    await vm.repo.workflow(name, (workflow) => {
      for (const job of workflow.jobs) {
        for (const step of job.steps as Step[]) {
          const task = step.task;
          if (task.behaviorType === "@swamp/ssh") {
            (task.globalArgs as Record<string, unknown>).sshBinary = name === "task-vm-destroy" ? host : vm.ssh;
          }
          if (step.name === "worker_tokens" || step.name === "access_tokens") task.inputs!.run = "printf '%s' '[]'";
          // If this actually runs, preflight sees an online guest without SSH and fails. Deleted machines must skip it.
          if (step.name === "probe") task.inputs!.run = `printf '%s' '{"reachable":true}'`;
        }
      }
    });
  }
  for (const name of ["operation-route-php-delta", "operation-route-caddy", "route-dns-publish"]) {
    await vm.repo.stubWorkflow(name, "true");
  }
  await vm.repo.stubWorkflow("task-vm-model-revoke", `printf '%s\\n' '{"model_revoked":true}' >> ${vm.calls}`);
  const empty = (fields: string[]) => Object.fromEntries(fields.map((field) => [field, []]));
  const role = {
    schema_version: 1,
    node_id: 142,
    assignment_id: 41,
    role: "app-dev",
    ssh: null,
    publications: [],
    ...empty([
      "public_ssh", "files", "directories", "commands", "stop_units", "remove_units", "remove_footprints", "reload_units",
      "stop_containers", "remove_containers", "ci_host", "firewall", "certificates",
    ]),
  };
  const removal = {
    schema_version: 1,
    node_id: 142,
    name: "tvm-42",
    machine_deleted: true,
    offline: true,
    platform: "linux",
    probe: { host: "192.0.2.142", port: 22 },
    ssh: null,
    roles: [role],
    public_ssh: { argv: [] },
    managed_keys: [],
    retained_on_node: [],
    preserved_on_node: [],
    ...empty(["hub", "router_rules", "peers", "dns", "metrics_files", "metrics_reload"]),
  };
  vm.envelope = {
    operation_id: 7009,
    node_id: 99,
    kind: "task_vm.destroy",
    intent: {
      task_vm_id: 42,
      guest_node_id: 142,
      provider: "incus",
      provider_input: vm.provider,
      model_key_required: true,
      model_intent: { task_vm_id: 42 },
      removal,
    },
    execution_secrets: Object.fromEntries(
      Object.entries({
        model_key: "task-vm-model-key-42",
        model_router_management: "model-router-management-node-6",
        model_router_anchor: "model-router-anchor-node-6",
      }).map(([field, key]) => [field, { vault: "orbit-control-inputs", key }]),
    ),
  };
  return vm;
}

const destroy = (vm: TaskVmLifecycle, success = true) => vm.run("operation-task-vm-destroy", success);
/** Step statuses of the shared child's newest run. */
const child = async (vm: TaskVmLifecycle) => (await vm.repo.history("task-vm-destroy")).steps;
const hostCalls = (vm: TaskVmLifecycle) => vm.argvCalls().filter((argv) => argv[0] === "sudo").map((argv) => argv.slice(3));
const lines = (vm: TaskVmLifecycle) => Deno.readTextFile(vm.calls).then((text) => text.split("\n"), () => []);

Deno.test("destroy revokes, then deletes the VM, then the volume, and checks both are gone", async () => {
  await using vm = await fixture();
  const { run, history } = await destroy(vm);
  assertEquals(run.status, "succeeded");
  assertEquals(history.jobs.flatMap((job) => job.steps.map((step) => step.name)), ["Destroy the task VM"]);
  const state = await vm.readState();
  assertEquals(state.exists, false);
  assertEquals(state.volumes, ["tvm-43-topology-seed"]);
  assertEquals(hostCalls(vm).filter((argv) => !argv.includes("query")), [
    ["timeout", "180", "incus", "delete", "--force", "--project", "tasks", "--", "tvm-42"],
    ["timeout", "180", "incus", "storage", "volume", "delete", "--project", "tasks", "--", "tasks", "tvm-42-topology-seed"],
  ]);
  const calls = await lines(vm);
  assert(
    calls.findIndex((line) => line.includes("model_revoked")) < calls.findIndex((line) => line.includes('"delete"')),
  );
  const steps = await child(vm);
  for (
    const name of ["Revoke the model key", "Delete the VM", "Delete the seed volume", "Remove the guest Node", "Check the VM is gone"]
  ) assertEquals(steps[name], "succeeded", name);
  // The intent has no WireGuard address, so the 209 known_hosts entry is left alone.
  for (const name of ["Forget the guest host key", "Read the server", "Stop the server", "Delete the server"]) {
    assertEquals(steps[name], "skipped", name);
  }
  for (const name of ["plan", "inspect", "stop", "revoked", "seed-before", "seed", "seed-delete", "seed-after", "result"]) {
    assertEquals(name in steps, false, name);
  }
});

Deno.test("an absent VM and volume count as done and still revoke and remove the Node", async () => {
  await using vm = await fixture({ exists: false, volumes: ["tvm-43-topology-seed"] });
  const { run } = await destroy(vm);
  assertEquals(run.status, "succeeded");
  assertStringIncludes(await Deno.readTextFile(vm.calls), "model_revoked");
  assertEquals((await child(vm))["Remove the guest Node"], "succeeded");
  assertEquals((await vm.readState()).volumes, ["tvm-43-topology-seed"]);
});

Deno.test("a failed revoke blocks the provider delete", async () => {
  await using vm = await fixture();
  await vm.repo.stubWorkflow("task-vm-model-revoke", "exit 7");
  const { run } = await destroy(vm, false);
  assertEquals(run.status, "failed");
  assert((await vm.readState()).exists);
  assertEquals(hostCalls(vm), []);
});

Deno.test("a VM the delete leaves fails the end check", async () => {
  await using vm = await fixture({ exists: true, stuck: true, volumes: ["tvm-42-topology-seed"] });
  const { run } = await destroy(vm, false);
  assertEquals(run.status, "failed");
  assertEquals((await child(vm))["Check the VM is gone"], "failed");
});
