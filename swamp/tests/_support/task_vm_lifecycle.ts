/**
 * Disposable official SSH provider lifecycle for task VM native tests; never contacts a fleet Node.
 *
 * The fake `ssh` answers Incus commands from a JSON state file, logs each argv and runs any other
 * command locally, so a test sees the exact commands and their order.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type NativeRepo, nativeRepo } from "./native_harness.ts";
import { readCalls } from "./fake_tool.ts";

const tool = new URL("./fake_tool.ts", import.meta.url).href;
export const HostKey = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG";

const fakeSsh = (paths: Record<string, string>) => `
import { logCall, passThrough, readStdinBytes, shellSplit } from ${JSON.stringify(tool)};
const P = ${JSON.stringify(paths)};
const exists = (path) => { try { Deno.statSync(path); return true; } catch { return false; } };
const args = shellSplit(Deno.args.at(-1));
const body = await readStdinBytes();
logCall(P.calls, { argv: args, stdin: new TextDecoder().decode(body) });
if (exists(P.block)) Deno.exit(255);
const incus = args.indexOf("/usr/bin/incus");
if (incus >= 0) {
  const value = JSON.parse(Deno.readTextFileSync(P.state));
  const save = () => Deno.writeTextFileSync(P.state, JSON.stringify(value));
  const action = args[incus + 3];
  if (action === "storage") {
    if (args.includes("list")) console.log(JSON.stringify((value.volumes ?? []).map((name) => ({ name, type: "custom" }))));
    else if (args.includes("delete")) { value.volumes = (value.volumes ?? []).filter((name) => name !== args.at(-1)); save(); }
    Deno.exit(0);
  }
  if (action === "exec") {
    if (args.includes("/usr/bin/cat")) console.log(${JSON.stringify(HostKey)});
    Deno.exit(0);
  }
  if (action === "wait") {
    // Incus's agent wait: a starting VM finishes booting.
    if (value.starting) { value.status = "Running"; value.starting = false; save(); }
    Deno.exit(value.exists && value.status === "Running" ? 0 : 1);
  }
  if (action === "list") {
    if (value.starting) {
      const remaining = value.remaining_start_reads ?? 0;
      if (remaining) value.remaining_start_reads = remaining - 1;
      else { value.status = "Running"; value.starting = false; }
      save();
    }
    console.log(JSON.stringify(value.exists ? [{ name: "tvm-42", type: "virtual-machine", status: value.status }] : []));
  } else {
    if (!exists(P.pending)) {
      if (action === "start") {
        value.starting = (value.remaining_start_reads ?? 0) > 0;
        value.status = value.starting ? "Stopped" : "Running";
      } else if (action === "stop") value.status = "Stopped";
      else if (action === "delete") value.exists = false;
      else Deno.exit(9);
      save();
    }
    if (exists(P.lost)) Deno.exit(255);
  }
  Deno.exit(0);
}
// The bounded start wait reads the host through sudo; route those reads back through this fake.
if (args[0] === "timeout" && args[2] === "/bin/sh") args[4] = args[4].replaceAll("/usr/bin/sudo", P.sudoShim);
if (args[0] === "/usr/bin/python3") {
  if (args.at(-1).includes("urllib.request")) { console.log('{"pi_ready":true}'); Deno.exit(0); }
  args[args.length - 1] = args.at(-1).replaceAll("/home/orbit", P.guest);
}
await passThrough(args, body);
`;

const sudoShim = (ssh: string) => `
import { shellJoin } from ${JSON.stringify(tool)};
const child = new Deno.Command(${JSON.stringify(ssh)}, { args: [shellJoin(["/usr/bin/sudo", ...Deno.args])] }).spawn();
Deno.exit((await child.status).code);
`;

export type Envelope = {
  operation_id: number;
  node_id: number;
  kind: string;
  intent: Record<string, unknown>;
  execution_secrets: Record<string, unknown>;
  action?: string;
};

export class TaskVmLifecycle {
  readonly state: string;
  readonly calls: string;
  readonly block: string;
  readonly pending: string;
  readonly lost: string;
  readonly ssh: string;
  readonly sshExecution = {
    control_node_id: 209,
    identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
    public_key: "ssh-ed25519 PUBLIC",
    target: { name: "target", host: "192.0.2.42", user: "orbit", port: 22, host_keys: [HostKey] },
  };
  readonly provider: Record<string, unknown> = {
    task_vm_id: 42,
    name: "tvm-42",
    provider: "incus",
    provider_id: null,
    vm: { project: "tasks", name: "tvm-42", pool: "tasks" },
    host_ssh_execution: this.sshExecution,
  };
  envelope: Envelope = {
    operation_id: 7002,
    node_id: 99,
    kind: "task_vm.resume",
    intent: this.provider,
    execution_secrets: {},
    action: "start",
  };

  constructor(readonly repo: NativeRepo) {
    this.state = `${repo.dir}/state.json`;
    this.calls = `${repo.dir}/calls.jsonl`;
    this.block = `${repo.dir}/block`;
    this.pending = `${repo.dir}/pending`;
    this.lost = `${repo.dir}/lost`;
    this.ssh = `${repo.dir}/ssh`;
  }

  writeState(value: Record<string, unknown>) {
    return Deno.writeTextFile(this.state, JSON.stringify(value));
  }

  async readState() {
    return JSON.parse(await Deno.readTextFile(this.state));
  }

  argvCalls() {
    return readCalls<{ argv?: string[] }>(this.calls).filter((call) => call.argv).map((call) => call.argv!);
  }

  run(name = "task-vm-provider-power", success = true) {
    return this.repo.runWorkflow(name, this.envelope, { success });
  }

  [Symbol.asyncDispose]() {
    return this.repo[Symbol.asyncDispose]();
  }
}

/** The repo with the lifecycle and bootstrap behaviors, the fake host and the provider workflows. */
export async function taskVmLifecycle() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  await repo.copyBehaviors("operations/task-vm-lifecycle", "operations/node-bootstrap");
  const fixture = new TaskVmLifecycle(repo);
  await fixture.writeState({ status: "Stopped", exists: true });
  const guest = `${repo.dir}/guest`;
  await Deno.mkdir(`${guest}/.local/bin`, { recursive: true });
  await Deno.writeTextFile(`${guest}/.local/bin/pi-server`, "fixture-pi");
  const shim = await repo.fakeBinary("sudo-shim", sudoShim(fixture.ssh));
  await repo.fakeBinary(
    "ssh",
    fakeSsh({
      state: fixture.state,
      calls: fixture.calls,
      block: fixture.block,
      pending: fixture.pending,
      lost: fixture.lost,
      guest,
      sudoShim: shim,
    }),
  );
  for (const name of ["task-vm-provider-power", "operation-task-park", "task-vm-ssh-prepare"]) {
    await repo.workflow(name, (workflow) => {
      for (const job of workflow.jobs) {
        for (const step of job.steps) {
          const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
          if (task.behaviorType === "@swamp/ssh") task.globalArgs!.sshBinary = fixture.ssh;
        }
      }
    });
  }
  return fixture;
}

/** The newest receipt of a run's first job: its last step's outputs. */
export function lastOutputs(history: { jobs: { steps: { outputs?: Record<string, unknown> }[] }[] }) {
  const outputs = history.jobs[0].steps.at(-1)!.outputs;
  assertEquals(typeof outputs, "object");
  return outputs!;
}
