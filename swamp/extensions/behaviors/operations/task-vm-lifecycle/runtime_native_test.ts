/** The native runtime DAG and official SSH, with disposable SSH and SCP process boundaries. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const Secret = "runtime_native_private_fixture_" + "c".repeat(32);
const Build = "pinned pi build";
const sha256 = async (text: string) =>
  Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))), (byte) =>
    byte.toString(16).padStart(2, "0")).join("");

type Event = { argv?: string[]; stdin?: string | null; ssh_argv?: string[]; copy?: string[] };

async function fixture(env: Record<string, string> = {}) {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.pull("@swamp/ssh");
  const events = `${repo.dir}/events.jsonl`;
  // Stdin is recorded by digest only: the harness fails the test when private bytes reach any scratch file.
  const ssh = await repo.fakeBinary("ssh", `
import { logCall, readStdin, shellSplit } from ${JSON.stringify(tool)};
const argv = shellSplit(Deno.args.at(-1));
const body = await readStdin();
const digest = body ? Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(body))), (b) => b.toString(16).padStart(2, "0")).join("") : null;
logCall(${JSON.stringify(events)}, { argv, stdin: digest, ssh_argv: Deno.args });
if (argv.join(" ").includes("/capabilities") && Deno.env.get("FIXTURE_FAIL") === "true") Deno.exit(19);
`);
  // The fetched build lands where the copy step names it, so the digest check reads real bytes.
  const scp = await repo.fakeBinary("scp", `
import { logCall } from ${JSON.stringify(tool)};
logCall(${JSON.stringify(events)}, { copy: Deno.args });
const destination = Deno.args.at(-1);
if (!destination.includes(":")) Deno.writeTextFileSync(destination, Deno.env.get("FIXTURE_BUILD"));
`);
  await repo.workflow("task-vm-runtime", (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs?: Record<string, unknown> };
      if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs!, { sshBinary: ssh, scpBinary: scp });
    }
  });
  await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
  for (const key of ["task-vm-pi-models-42", "task-vm-pi-token-42"]) {
    await repo.cli(["vault", "put", "orbit-control-inputs", key], { input: Secret });
  }
  repo.secrets = [Secret];
  repo.env = { FIXTURE_BUILD: Build, FIXTURE_FAIL: "false", ...env };
  const execution = {
    control_node_id: 209,
    identity_file: "/etc/orbit/swamp-role-worker/ssh-identity",
    public_key: HostKey,
    target: { name: "target", host: "192.0.2.42", port: 22, user: "orbit", host_keys: [HostKey] },
  };
  const inputs = {
    operation_id: 7005,
    node_id: 9,
    kind: "tasks.provision",
    intent: {
      task_vm_id: 42,
      guest_node_id: 142,
      user: "orbit",
      ssh_execution: execution,
      runtime: {
        pi_sha256: "a".repeat(64),
        origin: "http://192.0.2.6",
        process: { process_id: 33, unit_name: "orbit-process-33.service", unit_path: "/etc/systemd/system/orbit-process-33.service" },
      },
      pi_artifact: { path: "/cache/pi.bin", sha256: await sha256(Build), ssh_execution: execution },
      process_unit: "[Service]\nExecStart=/home/orbit/.local/bin/pi-server\n",
      certificate: "-----BEGIN CERTIFICATE-----\nAA==\n-----END CERTIFICATE-----\n",
      pi_url: "http://10.44.0.142:3774",
    },
    execution_secrets: {
      pi_models: { vault: "orbit-control-inputs", key: "task-vm-pi-models-42" },
      pi_token: { vault: "orbit-control-inputs", key: "task-vm-pi-token-42" },
    },
  };
  return {
    repo,
    async run(success = true) {
      const result = await repo.runWorkflow("task-vm-runtime", inputs, { success });
      return { ...result, events: readCalls<Event>(events) };
    },
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

const staged = async (runId: string) => await Deno.stat(`/tmp/orbit-pi-${runId}.bin`).then(() => true, () => false);

Deno.test("the pinned build, files and service reach the guest through pinned SSH", async () => {
  await using test = await fixture();
  const { run, history, events } = await test.run();
  assertEquals(run.status, "succeeded");
  assertEquals(JSON.parse(history.jobs[0].steps.at(-1)!.outputs!.stdout as string), {
    task_vm_id: 42,
    guest_node_id: 142,
    process_id: 33,
    ready: true,
  });
  const commands = events.filter((event) => event.argv);
  const joined = commands.map((event) => event.argv!.join(" "));
  const digest = await sha256(Secret);
  // Vault values reach the guest only on stdin, into 0600 files of the managed account.
  assertEquals(commands.filter((event) => event.stdin === digest).length, 2);
  assert(joined.some((line) => line.includes("/home/orbit/.pi/agent/models.json") && line.includes("0600")));
  assert(joined.some((line) => line.endsWith("/dev/stdin /home/orbit/.pi/agent/orbit-token")));
  assert(joined.some((line) => line.includes("sha256sum -c") && line.includes("/home/orbit/.local/bin/pi-server")));
  // The trust directory may not exist on the image yet.
  assert(joined.some((line) =>
    line.includes("install -D -m 0644 -o root -g root /dev/stdin /usr/local/share/ca-certificates/orbit-root.crt")
  ));
  assert(joined.some((line) => line.includes("update-ca-certificates")));
  assert(joined.some((line) => line.endsWith("/etc/systemd/system/orbit-process-33.service")));
  assert(commands.some((event) => event.argv!.at(-1) === "http://10.44.0.142:3774"));
  assert(!JSON.stringify(history).includes(Secret));
  assert(!joined.some((line) => line.includes("python3")));
  for (const event of events.filter((event) => event.ssh_argv)) assert(event.ssh_argv!.includes("StrictHostKeyChecking=yes"));
  assert(!await staged(run.id));
});

Deno.test("a build with another digest never reaches the guest", async () => {
  await using test = await fixture({ FIXTURE_BUILD: "tampered build" });
  const { run, events } = await test.run(false);
  assertEquals(run.status, "failed");
  assert(!events.some((event) => event.copy?.at(-1)!.includes(":")));
  assert(!events.some((event) => event.argv));
  assert(!await staged(run.id));
});

Deno.test("a failed probe cleans the stage and never returns ready", async () => {
  await using test = await fixture({ FIXTURE_FAIL: "true" });
  const { run, steps } = await test.run(false);
  assertEquals(run.status, "failed");
  assertEquals(steps.result, "skipped");
  assertEquals(steps["Remove the staged build"], "succeeded");
});
