/**
 * task_vm.resume against a fake Incus host and guest behind official SSH; never contacts a fleet Node.
 *
 * The fake `ssh` runs nothing: it tells the host (192.0.2.42) from the guest (10.44.0.142) by the
 * destination, answers from a JSON state file and logs each argv, so the test sees the exact commands.
 */
import { assertEquals, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const KEY = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";

const fake = (state: string, calls: string) => `
import { logCall, readStdin, shellSplit } from ${JSON.stringify(tool)};
const argv = Deno.args;
if (argv.includes("-M") || argv.includes("-O")) Deno.exit(0);
const address = argv.find((arg) => arg.startsWith("orbit@"))!.split("@")[1];
const args = shellSplit(argv.at(-1)!);
const value = JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)}));
const body = await readStdin();
logCall(${JSON.stringify(calls)}, { address, argv: args, stdin: body });
const save = () => Deno.writeTextFileSync(${JSON.stringify(state)}, JSON.stringify(value));
if (address === "192.0.2.42") {
  if (args[0] === "/usr/bin/sudo") Deno.exit(0);
  if (args.slice(0, 4).join(" ") !== "sudo -n -- timeout" || args[5] !== "incus") throw new Error(args.join(" "));
  const command = args.slice(6);
  if (command.slice(0, 4).join(" ") === "start --project tasks --") {
    if (value.status === "Running") { console.error("Error: The instance is already running"); Deno.exit(1); }
    if (!value.stuck) value.status = "Running";
    save();
    Deno.exit(0);
  }
  if (command.slice(0, 3).join(" ") === "wait --project tasks" && command.slice(-3).join(" ") === "-- tvm-42 agent") {
    Deno.exit(value.status === "Running" ? 0 : 1);
  }
  Deno.exit(9);
}
if (address !== "10.44.0.142") throw new Error(address);
if (args.join(" ") === "/usr/bin/systemctl is-active orbit-agent.service") {
  const agent = value.agent ?? "active";
  console.log(agent);
  Deno.exit(agent === "active" ? 0 : 3);
}
if (args.slice(0, 3).join(" ") === "/usr/bin/python3 -B -c" && args[3].includes("urllib.request")) {
  if (JSON.parse(body).pi_url !== "http://10.44.0.142:3774") throw new Error(body);
  if (value.pi_down) { console.error("task_vm.resume_failed"); Deno.exit(1); }
  console.log('{"pi_ready": true}');
  Deno.exit(0);
}
Deno.exit(9);
`;

type Call = { address: string; argv: string[]; stdin: string };

async function fixture(state: Record<string, unknown>) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/task-vm-lifecycle");
  const statePath = `${repo.dir}/state.json`;
  const callsPath = `${repo.dir}/calls.jsonl`;
  await Deno.writeTextFile(statePath, JSON.stringify(state));
  const ssh = await repo.fakeBinary("ssh", fake(statePath, callsPath));
  for (const name of ["operation-task-vm-resume", "task-vm-provider-power"]) {
    await repo.workflow(name, (workflow) => {
      for (const job of workflow.jobs) {
        for (const step of job.steps) {
          const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
          if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = ssh;
        }
      }
    });
  }
  const identity = {
    control_node_id: 209,
    identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
    public_key: "ssh-ed25519 PUBLIC",
  };
  const target = (host: string) => ({
    ...identity,
    target: { name: "target", host, user: "orbit", port: 22, host_keys: [KEY] },
  });
  const inputs = {
    operation_id: 7002,
    node_id: 99,
    kind: "task_vm.resume",
    execution_secrets: {},
    intent: {
      task_vm_id: 42,
      node_id: 99,
      guest_node_id: 142,
      name: "tvm-42",
      provider: "incus",
      provider_id: null,
      user: "orbit",
      pi_url: "http://10.44.0.142:3774",
      admitted_state: "parked",
      mode: "resume",
      node: { node_id: 142, wireguard_ip: "10.44.0.142" },
      ssh_identity: identity,
      ssh_execution: target("10.44.0.142"),
      runtime_json: JSON.stringify({ pi_sha256: "a".repeat(64), pi_port: 3774, pi_url: "http://10.44.0.142:3774" }),
      provider_input: {
        task_vm_id: 42,
        name: "tvm-42",
        provider: "incus",
        provider_id: null,
        vm: { project: "tasks", name: "tvm-42", pool: "tasks" },
        host_ssh_execution: target("192.0.2.42"),
      },
    } as Record<string, unknown>,
  };
  return {
    repo,
    inputs,
    resume: (success = true) => repo.runWorkflow("operation-task-vm-resume", inputs, { success }),
    callsTo: (address: string) => readCalls<Call>(callsPath).filter((call) => call.address === address),
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

Deno.test("a parked VM starts, waits for its agent and checks agent and Pi", async () => {
  await using test = await fixture({ status: "Stopped" });
  const { run, steps } = await test.resume();
  assertEquals(run.status, "succeeded");
  assertEquals(test.callsTo("192.0.2.42").map((call) => call.argv.slice(3)), [
    ["timeout", "60", "incus", "start", "--project", "tasks", "--", "tvm-42"],
    ["timeout", "130", "incus", "wait", "--project", "tasks", "--timeout", "120", "--interval", "1", "--", "tvm-42", "agent"],
  ]);
  assertEquals(test.callsTo("10.44.0.142").map((call) => call.argv[0]).sort(), ["/usr/bin/python3", "/usr/bin/systemctl"]);
  for (
    const name of [
      "Start the VM",
      "Wait for the guest agent",
      "Read the guest state-agent",
      "Read the guest state-pi",
      "Check the guest is ready",
    ]
  ) assertEquals(steps[name], "succeeded", name);
  assertEquals(steps["Start the server"], "skipped");
  assertNotEquals(steps["Converge 209 SSH access-0"] ?? "skipped", "succeeded");
  for (const name of ["eligible", "provider", "ssh", "artifact", "pi", "runtime-confirmed", "result"]) {
    assertEquals(name in steps, false, name);
  }
});

Deno.test("a VM that already runs counts as started", async () => {
  await using test = await fixture({ status: "Running" });
  const { run, steps } = await test.resume();
  assertEquals(run.status, "succeeded");
  assertEquals(steps["Start the VM"], "succeeded");
});

Deno.test("an agent that never comes up fails before the guest is read", async () => {
  await using test = await fixture({ status: "Stopped", stuck: true });
  const { run, steps } = await test.resume(false);
  assertEquals(run.status, "failed");
  assertEquals(steps["Wait for the guest agent"], "failed");
  assertEquals(test.callsTo("10.44.0.142"), []);
});

for (
  const [name, state] of [
    ["a Pi that does not answer fails the run", { pi_down: true }],
    ["an inactive agent fails the run", { agent: "inactive" }],
  ] as const
) {
  Deno.test(name, async () => {
    await using test = await fixture({ status: "Stopped", ...state });
    const { run, steps } = await test.resume(false);
    assertEquals(run.status, "failed");
    assertNotEquals(steps["Check the guest is ready"], "succeeded");
  });
}

Deno.test("converge restores 209 access through the host before the check", async () => {
  await using test = await fixture({ status: "Running" });
  Object.assign(test.inputs.intent, { mode: "converge", admitted_state: "ready" });
  const { run, steps } = await test.resume();
  assertEquals(run.status, "succeeded");
  const prefix = [
    "/usr/bin/sudo", "-n", "--", "/usr/bin/incus", "--project", "tasks", "exec", "--mode=non-interactive",
    "--user", "0", "--group", "0", "--", "tvm-42",
  ];
  const changes = test.callsTo("192.0.2.42").filter((call) => call.argv[0] === "/usr/bin/sudo");
  assertEquals(changes.length, 3);
  for (const call of changes) assertEquals(call.argv.slice(0, prefix.length), prefix);
  const commands = Object.fromEntries(changes.map((call) => [call.argv[prefix.length], call]));
  assertEquals(Object.keys(commands).sort(), ["/bin/sh", "/usr/bin/install", "/usr/bin/systemctl"]);
  assertEquals(commands["/usr/bin/install"].stdin, "ssh-ed25519 PUBLIC\n");
  assertEquals(steps["Check the guest is ready"], "succeeded");
});
