/** Official SSH provider DAG from 209 with disposable Incus command fixtures. */
import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;

const fakeIncus = (path: string) => `
import { readStdin } from ${JSON.stringify(tool)};
const state = JSON.parse(Deno.readTextFileSync(${JSON.stringify(path)}));
state.calls.push(Deno.args);
const args = Deno.args.slice(2);
const head = args.slice(0, 3).join(" ");
let reply = "";
let failure = false;
if (args[0] === "list") {
  reply = JSON.stringify(state.exists ? [{
    name: "tvm-12", type: "virtual-machine", status: state.running ? "Running" : "Stopped",
    config: { "volatile.base_image": state.fingerprint },
    state: { network: { eth0: { addresses: state.no_address ? [] : [{ family: "inet", address: "10.240.9.12" }] } } },
  }] : []);
} else if (head === "storage volume list") {
  reply = JSON.stringify(state.volumes.map((name) => ({ type: "custom", name })));
} else if (head === "storage volume copy") {
  if (state.clone_failure) failure = true;
  else state.volumes.push("tvm-12-topology-seed");
} else if (args[0] === "init") {
  Object.assign(state, { exists: true, running: false, initial_config: JSON.parse(await readStdin()) });
} else if (head === "config metadata show") {
  reply = "templates:\\n  /var/lib/cloud/seed/nocloud-net/user-data: {template: cloud-init.tpl}\\n  /etc/hostname: {template: hostname.tpl}\\n";
} else if (head === "config metadata edit") {
  state.metadata = await readStdin();
} else if (args[0] === "wait") {
  // Native \`incus wait <vm> ipv4\` returns once a lease exists and exits nonzero at its timeout.
  if (state.lease_after_wait) state.no_address = false;
  failure = !!state.no_address;
} else if (args[0] === "start") {
  state.running = true;
  if (state.lose_start_reply) { state.lose_start_reply = false; failure = true; }
} else throw new Error("Unexpected Incus command: " + JSON.stringify(args));
Deno.writeTextFileSync(${JSON.stringify(path)}, JSON.stringify(state));
console.log(reply);
Deno.exit(failure ? 1 : 0);
`;

type State = {
  calls: string[][];
  exists: boolean;
  running: boolean;
  fingerprint: string;
  volumes: string[];
  metadata?: string;
  initial_config?: { devices: Record<string, { source: string }> };
  [key: string]: unknown;
};

async function fixture(state: Partial<State> = {}, topology = false) {
  const repo = await nativeRepo();
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/task-vm-lifecycle");
  await Deno.mkdir(`${repo.dir}/extensions/behaviors/operations/task-vms`, { recursive: true });
  await Deno.copyFile(
    `${ROOT}/extensions/behaviors/operations/task-vms/provision_inputs.ts`,
    `${repo.dir}/extensions/behaviors/operations/task-vms/provision_inputs.ts`,
  );
  const path = `${repo.dir}/incus-state.json`;
  const incus = await repo.fakeBinary("fake-incus", fakeIncus(path));
  const ssh = await repo.fakeBinary("ssh", `
import { passThrough, readStdinBytes, shellSplit } from ${JSON.stringify(tool)};
const args = shellSplit(Deno.args.at(-1));
args[args.length - 1] = args.at(-1).replaceAll("/usr/bin/sudo -n -- /usr/bin/incus", ${JSON.stringify(incus)});
await passThrough(args, await readStdinBytes());
`);
  const tracked = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-task-vm-provider-incus.yaml`)) as {
    jobs: { name: string; labels: unknown; steps: { labels: unknown }[] }[];
  };
  assertEquals(tracked.jobs[0].name, "provider");
  for (const job of tracked.jobs) {
    assertEquals(job.labels, { role: "swamp" });
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" });
  }
  const source = await repo.workflow("task-vm-provider-incus", (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") task.globalArgs.sshBinary = ssh;
    }
  });
  const intent = {
    host_ssh_execution: { control_node_id: 209, identity_file: "/etc/orbit/swamp-role-worker/ssh-identity", public_key: "ssh-ed25519 PUBLIC",
      target: { name: "target", host: "192.0.2.9", user: "orbit", port: 22, host_keys: [HostKey] } },
    task_vm_id: 12, node_id: 9, host_node_id: 9, guest_node_id: 210, gateway_node_id: 14, issuer_node_id: 209, model_router_node_id: 6,
    provider: "incus", name: "tvm-12", image_fingerprint: "a".repeat(64), recipe_identity: "sha256:" + "b".repeat(64),
    vm: { project: "orbit-task-vms", name: "tvm-12", cidr: "10.240.9.0/24" },
    create: { image: "a".repeat(64), expected_fingerprint: "a".repeat(64), gateway_public_key: "ssh-ed25519 " + "a".repeat(43) + "=",
      cpus: 2, memory: "2GiB", disk: "20GiB", network: "orbit-task", pool: "default", topology },
  };
  const save = (value: Partial<State>) => Deno.writeTextFile(path, JSON.stringify(value));
  await save({ calls: [], exists: false, running: false, fingerprint: "a".repeat(64), volumes: ["orbit-topology-seed-" + "a".repeat(12)], ...state });
  const read = async () => JSON.parse(await Deno.readTextFile(path)) as State;
  return {
    repo, source, intent, read, save,
    actions: async () => (await read()).calls.map((call) => call[2]),
    async run(success = true) {
      await repo.cli(["workflow", "validate", "task-vm-provider-incus"]);
      return (await repo.runWorkflow("task-vm-provider-incus", { operation_id: 7, kind: "task_vm.provision", node_id: 9, intent }, { success })).run;
    },
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

const count = (list: string[], value: string) => list.filter((item) => item === value).length;

Deno.test("fresh provider creates and starts without Gateway calls", async () => {
  await using test = await fixture();
  await test.run();
  const state = await test.read();
  assert(state.exists && state.running);
  assertFalse(state.metadata!.includes("cloud-init.tpl"));
  const actions = await test.actions();
  assertEquals([count(actions, "init"), count(actions, "start")], [1, 1]);
});

Deno.test("the provider fragment returns typed public state to its parent", async () => {
  await using test = await fixture();
  const inputs = (key: string) => "${{ inputs." + key + " }}";
  await Deno.writeTextFile(
    `${test.repo.dir}/workflows/workflow-provider-parent.yaml`,
    stringify({
      id: "4c17391e-f057-468c-9a13-cbd977510272", name: "provider-parent", inputs: test.source.inputs,
      jobs: [{ name: "create", steps: [{
        name: "create",
        task: { type: "workflow", workflowIdOrName: "task-vm-provider-incus",
          inputs: Object.fromEntries(["operation_id", "kind", "node_id", "intent"].map((key) => [key, inputs(key)])) },
      }, {
        name: "proof",
        dependsOn: [{ step: "create", condition: { type: "succeeded" } }],
        task: { type: "assert", severity: "high", message: "Provider fragment did not expose settled state",
          expr: 'steps.create.outputs.provider.address == "10.240.9.12" && steps.create.outputs.provider.settled && ' +
            "steps.create.outputs.provider.image_fingerprint == inputs.intent.image_fingerprint" },
      }] }],
    }),
  );
  await test.repo.runWorkflow("provider-parent", { operation_id: 7, kind: "tasks.provision", node_id: 9, intent: test.intent });
});

Deno.test("an existing running VM skips mutations", async () => {
  await using test = await fixture({ exists: true, running: true });
  await test.run();
  const actions = await test.actions();
  assertFalse(actions.includes("init") || actions.includes("start"));
  assertFalse((await test.read()).calls.some((call) => call.slice(2, 5).join(" ") === "config metadata edit"));
});

Deno.test("a foreign image fails before mutation", async () => {
  await using test = await fixture({ exists: true, fingerprint: "c".repeat(64) });
  await test.run(false);
  assertEquals(await test.actions(), ["list"]);
});

Deno.test("a topology clone failure prevents VM initialization", async () => {
  await using test = await fixture({ clone_failure: true }, true);
  await test.run(false);
  assertFalse((await test.read()).exists);
  assertFalse((await test.actions()).includes("init"));
});

Deno.test("the topology clone is attached to the exact guest", async () => {
  await using test = await fixture({}, true);
  await test.run();
  assertEquals((await test.read()).initial_config!.devices["topology-seed"].source, "tvm-12-topology-seed");
});

Deno.test("inspection waits natively for the first lease before its one read", async () => {
  await using test = await fixture({ no_address: true, lease_after_wait: true });
  await test.run();
  const calls = (await test.read()).calls.map((call) => call.slice(2));
  const wait = calls.findIndex((call) => call.join(" ") === "wait tvm-12 ipv4 --timeout 120 --interval 1");
  assert(wait >= 0);
  assertEquals(calls[wait + 1][0], "list");
  assertEquals(count(calls.map((call) => call[0]), "start"), 1);
});

Deno.test("a pending address fails after one final observation", async () => {
  await using test = await fixture({ no_address: true });
  await test.run(false);
  assert(count(await test.actions(), "list") <= 4);
});

Deno.test("native resume from the final observation reads a new address without recreating the VM", async () => {
  await using test = await fixture({ no_address: true });
  const run = await test.run(false);
  const state = await test.read();
  const reads = count(await test.actions(), "list");
  await test.save({ ...state, no_address: false });
  await test.repo.cli(["workflow", "resume", "task-vm-provider-incus", "--run", run.id, "--from", "inspect-created-vm", "--job", "provider"]);
  const actions = await test.actions();
  assertEquals([count(actions, "list"), count(actions, "init"), count(actions, "start")], [reads + 1, 1, 1]);
});

Deno.test("native resume from the seed observation reads a repaired seed before initialization", async () => {
  await using test = await fixture({ volumes: [] }, true);
  const run = await test.run(false);
  const state = await test.read();
  assertFalse(state.exists);
  await test.save({ ...state, volumes: ["orbit-topology-seed-" + "a".repeat(12)] });
  await test.repo.cli(["workflow", "resume", "task-vm-provider-incus", "--run", run.id, "--from", "observe-seed", "--job", "provider"]);
  const after = await test.read();
  assert(after.running);
  assertEquals(after.initial_config!.devices["topology-seed"].source, "tvm-12-topology-seed");
});

Deno.test("native resume observes an uncertain start without repeating the mutation", async () => {
  await using test = await fixture({ lose_start_reply: true });
  const run = await test.run(false);
  assert((await test.read()).running);
  await test.repo.cli(["workflow", "resume", "task-vm-provider-incus", "--run", run.id]);
  assertEquals(count(await test.actions(), "start"), 1);
});
