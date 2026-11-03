/** Actual native image DAG and official SSH from 209, with disposable SSH/SCP process boundaries. No VM or fleet mutation. */
import { assert, assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const Pin = "a".repeat(64);
const Identity = "sha256:" + "b".repeat(64);
const Fingerprint = "c".repeat(64);
const Boot = "878a384f-8b8a-4066-bd17-dcad8eac3e86";
const Build = "pinned pi build";
const Workflow = "operation-task-vm-image-build";
const sha256 = async (text: string) =>
  Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))), (byte) =>
    byte.toString(16).padStart(2, "0")).join("");

// The fake host answers each argv the way the Incus host and its guests would, in Python's sorted JSON text.
const fakeSsh = (state: string) => `
import { readStdin, shellSplit } from ${JSON.stringify(tool)};
const py = (v) => Array.isArray(v) ? "[" + v.map(py).join(", ") + "]"
  : v && typeof v === "object" ? "{" + Object.keys(v).sort().map((k) => JSON.stringify(k) + ": " + py(v[k])).join(", ") + "}"
  : JSON.stringify(v);
const argv = shellSplit(Deno.args.at(-1));
const body = await readStdin();
const value = JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)}));
const call = { argv };
if (body) call.stdin = JSON.parse(body);
value.calls.push(call);
Deno.writeTextFileSync(${JSON.stringify(state)}, JSON.stringify(value));
const reply = (data) => console.log(py({ data, ok: true }));
const env = (name) => Deno.env.get(name);
const command = argv.indexOf("command");
if (command > 0 && argv[command - 1].split("/").at(-1).startsWith("orbit-agent")) {
  const name = argv[command + 1];
  const request = call.stdin.input;
  if (name === env("FIXTURE_REFUSE")) console.log(py({ error: { code: "agent.version_mismatch", message: "refused" }, ok: false }));
  else if (name === "incus.image" && request.operation.kind === "publish") reply({ ready: true, fingerprint: ${JSON.stringify(Fingerprint)} });
  else if (name === "incus.volume") reply({ name: "orbit-topology-seed-" + ${JSON.stringify(Fingerprint.slice(0, 12))} });
  else if (name === "agent.install") reply({ sha256: request.sha256, replaced: true });
  else if (name === "image.inspect-phase") reply({ boot_id: ${JSON.stringify(Boot)}, unit: "u", state: request.boot_id === null ? "missing" : "complete" });
  else if (name === "image.start-phase") reply({ boot_id: request.boot_id, unit: "u", state: env("FIXTURE_START") ?? "running" });
  else reply({ ready: true });
} else if (argv.includes("--property=MainPID")) console.log(env("FIXTURE_START") === "complete" ? "0" : "4242");
else if (argv.includes("is-active")) console.log("active");
`;

type Call = { argv: string[]; stdin: { agent_sha256: string; input: Record<string, any> } };
type Step = { status: string; outputs: Record<string, string> };

async function build({ pi = false, env = {} as Record<string, string>, recipe = undefined as string | undefined } = {}, success = true) {
  await using repo = await nativeRepo();
  repo.timeoutMs = 900_000;
  await repo.pull("@swamp/ssh");
  await Deno.mkdir(`${repo.dir}/extensions/behaviors/operations/task-vms`, { recursive: true });
  for (const name of ["image_build_inputs.ts", "images.ts"]) {
    await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/task-vms/${name}`, `${repo.dir}/extensions/behaviors/operations/task-vms/${name}`);
  }
  const state = `${repo.dir}/image-host.json`;
  await Deno.writeTextFile(state, JSON.stringify({ calls: [] }));
  const ssh = await repo.fakeBinary("ssh", fakeSsh(state));
  const copies = `${repo.dir}/copies.jsonl`;
  // The fetched build lands where the copy step names it, so the digest check on 209 reads real bytes.
  const scp = await repo.fakeBinary("scp", `
import { logCall } from ${JSON.stringify(tool)};
logCall(${JSON.stringify(copies)}, Deno.args);
if (!Deno.args.at(-1).includes(":")) Deno.writeTextFileSync(Deno.args.at(-1), Deno.env.get("FIXTURE_BUILD"));
`);
  const tracked = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${Workflow}.yaml`)) as {
    jobs: { name: string; labels?: unknown; steps: { name: string; labels: unknown }[] }[];
  };
  assertEquals(tracked.jobs.map((job) => job.name), ["admission", "build", "cleanup"]);
  for (const job of tracked.jobs) {
    assertEquals(job.labels, undefined);
    for (const step of job.steps) assertEquals(step.labels, { role: "swamp" }, step.name);
  }
  let fetch: Record<string, unknown> = {};
  await repo.workflow(Workflow, (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType?: string; globalArgs: Record<string, unknown> };
      if (step.name === "install-agent") {
        // Official cfgmgmt fetch opens its own SSH; the fixture records the arguments it would apply.
        assertEquals(task.behaviorType, "@swamp/cfgmgmt/fetch");
        fetch = task.globalArgs;
        step.task = { type: "behavior", behaviorType: "command/shell", behaviorName: "fixture-fetch", methodName: "execute",
          inputs: { run: `printf fetched >> ${repo.dir}/fetch.log` } };
      }
      if (task.behaviorType === "@swamp/ssh") Object.assign(task.globalArgs, { sshBinary: ssh, scpBinary: scp });
    }
  });
  repo.env = { FIXTURE_BUILD: Build, ...env };
  const execution = { control_node_id: 209, identity_file: "/fixture/key", public_key: "ssh-ed25519 PUBLIC",
    target: { name: "target", host: "192.0.2.13", user: "orbit", port: 22, host_keys: [HostKey] } };
  const digest = await sha256(Build);
  const intent = {
    build_id: 7, attempt: 1, host_node_id: 13, agent_sha256: Pin, identity: Identity,
    agent_url: "https://github.com/hardimpactdev/orbit/releases/download/cli-v0.7.0/orbit-agent-linux-x86_64",
    generation: 1, project: "orbit-tasks", name: "tvm-image-7", cidr: "10.251.77.0/24", pool: "orbit-tasks", network: "orbittask0",
    cpus: 2, memory: "4GiB", disk: "45GiB", source: "ubuntu-26.04-vm", candidate_alias: "candidate-7", base_alias: "orbit-task-base",
    gateway_public_key: "public-key", certificate: "public-root", ssh_execution: execution,
    pi_source: { ...execution, target: { ...execution.target, host: "192.0.2.14" } },
    recipe: recipe ?? JSON.stringify({ identity: Identity, agent_sha256: Pin, pi_sha256: pi ? digest : null }),
    pi_artifact: pi ? { path: "/srv/orbit/sandbox-artifacts/pi", sha256: digest } : null,
    prune: { project: "orbit-tasks",
      operation: { kind: "prune", pool: "orbit-tasks", obsolete_aliases: [], retired_aliases: [], protected_fingerprints: [] } },
  };
  await repo.cli(["workflow", "validate", Workflow]);
  const { run, history } = await repo.runWorkflow(
    Workflow,
    { operation_id: 7, node_id: 13, kind: "task_vm.image_build", intent, execution_secrets: {} },
    { success },
  );
  const steps = Object.fromEntries(history.jobs.flatMap((job) => job.steps).map((step) => [step.name, step as Step]));
  const calls = (JSON.parse(await Deno.readTextFile(state)) as { calls: Call[] }).calls;
  const agent = calls.filter((call) => call.argv.includes("command")).map((call) =>
    [call.argv[call.argv.indexOf("command") + 1], call] as const
  );
  return { run, steps, calls, agent, fetch, digest, copies: readCalls<string[]>(copies) };
}

type Result = Awaited<ReturnType<typeof build>>;

/** The release-pinned agent is installed and its service restarted before the first agent call. */
function assertAgentInstalled({ fetch, steps, calls }: Result) {
  assertEquals(fetch.url, "${{ inputs.intent.agent_url }}");
  assertEquals(fetch.path, "/usr/local/bin/orbit-agent");
  assertEquals(fetch.checksum, '${{ "sha256:" + inputs.intent.agent_sha256 }}');
  assertEquals([fetch.owner, fetch.mode, fetch.become], ["root", "0755", true]);
  assertEquals(steps["install-agent"].status, "succeeded");
  assert(calls[0].argv.includes("try-restart") && calls[0].argv.includes("orbit-agent.service"));
}

const Incus = ["timeout", "210", "/usr/bin/sudo", "-n", "--", "/usr/bin/incus", "--project", "orbit-tasks"];

Deno.test("the build runs every stage from 209 over pinned SSH", async () => {
  const result = await build({ pi: true });
  const { run, steps, calls, agent, copies, digest } = result;
  assertEquals([run.status, steps.complete.status], ["succeeded", "succeeded"]);
  assertEquals(steps["publish-reply"].outputs.fingerprint, Fingerprint);
  assertAgentInstalled(result);
  assertEquals(agent.map(([name]) => name), [
    "incus.vm", "incus.vm", "incus.vm", "agent.install", "guest.cloud-init", "image.inspect-phase",
    ...Array(6).fill(["image.start-phase", "image.inspect-phase"]).flat(),
    "image.owner", "incus.image", "image.boot", "image.recipe-marker", "image.trust-ca", "image.clean",
    "incus.image", "incus.image", "incus.vm", "incus.vm", "agent.install", "guest.cloud-init",
    "image.check", "image.verify", "incus.build-topology-seed", "incus.vm", "incus.volume",
    "incus.image", "incus.image", "incus.vm", "incus.vm",
  ]);
  for (const [name, call] of agent) {
    // Every call carries the admitted pin and a literal outer bound, and the agent never runs through a shell.
    assertEquals(call.argv[0], "timeout", name);
    assertEquals(call.stdin.agent_sha256, Pin, name);
    assertFalse(call.argv.some((part) => part.split("/").at(-1) === "sh"));
  }
  const host = agent.filter(([name]) => name.startsWith("incus.")).map(([, call]) => call);
  assertEquals(host[0].argv, ["timeout", "930", "/usr/bin/sudo", "-n", "--", "/usr/local/bin/orbit-agent", "command", "incus.vm"]);
  assertEquals(host[0].stdin.input, {
    vm: { project: "orbit-tasks", name: "tvm-image-7", cidr: "10.251.77.0/24" },
    operation: { kind: "remove_instance" },
  });
  const owner = agent.find(([name]) => name === "image.owner")![1];
  assertEquals(owner.argv.slice(0, 20), [
    "timeout", "150", ...Incus.slice(2), "exec", "--mode=non-interactive", "--user", "1000", "--group", "1000",
    "--env", "SUDO_USER=orbit", "--", "tvm-image-7", "/usr/local/bin/orbit-agent", "command",
  ]);
  const install = agent.filter(([name]) => name === "agent.install").map(([, call]) => call);
  const candidate = "/var/tmp/orbit-agent-candidate-" + Pin;
  assertEquals(install.map((call) => call.argv.slice(17, 19)), [["tvm-image-7", candidate], ["tvm-image-7-smoke", candidate]]);
  assertEquals(install[0].stdin.input, { candidate, sha256: Pin });
  assertEquals(
    calls.filter((call) => call.argv.includes("push") && call.argv.includes("0700")).map((call) => call.argv),
    ["tvm-image-7", "tvm-image-7-smoke"].map((vm) => [
      ...Incus, "file", "push", "--mode", "0700", "--uid", "0", "--gid", "0", "--", "/usr/local/bin/orbit-agent", vm + candidate,
    ]),
  );
  const waits = calls.filter((call) => call.argv.includes("wait")).map((call) => call.argv);
  assertEquals(waits[0].slice(5), ["/usr/bin/incus", "--project", "orbit-tasks", "wait", "--timeout", "600", "--", "tvm-image-7", "agent"]);
  // Each phase waits once on its retained unit's main process, then reads its outcome once.
  const tails = calls.filter((call) => call.argv.includes("/usr/bin/tail")).map((call) => call.argv);
  assertEquals(tails.length, 6);
  assertEquals([...tails[0].slice(0, 2), ...tails[0].slice(-6)], [
    "timeout", "1530", "/usr/bin/timeout", "1500", "/usr/bin/tail", "--pid=4242", "-f", "/dev/null",
  ]);
  const starts = agent.filter(([name]) => name === "image.start-phase").map(([, call]) => call.stdin.input);
  assertEquals(starts.map((start) => start.key.phase), ["apt-source", "packages", "javascript", "rust", "agent", "pi"]);
  assert(starts.every((start) => start.boot_id === Boot && start.key.execution === "7-1"));
  const units = calls.filter((call) => call.argv.includes("--property=MainPID")).map((call) => call.argv.at(-1)!);
  assertEquals(new Set(units).size, 6);
  assert(units.every((unit) => unit.startsWith("orbit-recipe-image-") && unit.endsWith(".service")));
  const published = agent.filter(([name]) => name === "incus.image").map(([, call]) => call.stdin.input.operation);
  assertEquals(published.map((operation) => operation.kind), ["prepare_metadata", "publish", "promote", "promote", "prune"]);
  assertEquals(published[2], { kind: "promote", alias: "candidate-7", fingerprint: Fingerprint });
  assertEquals(published[3], { kind: "promote", alias: "orbit-task-base", fingerprint: Fingerprint });
  const stage = `/tmp/orbit-image-pi-${run.id}.bin`;
  assertEquals(copies.length, 2);
  assert(copies[0].at(-2)!.endsWith(":/srv/orbit/sandbox-artifacts/pi") && copies[0].at(-1) === stage);
  assert(copies[1].at(-2) === stage && copies[1].at(-1)!.endsWith(":" + stage));
  assertStringIncludes(copies[0].at(-2)!, "192.0.2.14");
  const push = calls.find((call) => call.argv.includes("push") && call.argv.includes("0600"))!.argv;
  assertEquals(push.slice(-2), [stage, `tvm-image-7/var/cache/orbit/pi/${digest}.bin`]);
  const directory = calls.find((call) => call.argv.includes("/usr/bin/install"))!.argv;
  assertEquals(directory.slice(-9), ["/usr/bin/install", "-d", "-m", "0700", "-o", "root", "-g", "root", "/var/cache/orbit/pi"]);
  assertEquals(steps["cleanup-pi-host"].status, "succeeded");
  assert(calls.some((call) => call.argv.join(" ") === `timeout 30 /usr/bin/rm -f -- ${stage}`));
  assertFalse(await Deno.stat(stage).then(() => true, () => false));
  // No base64 chunk upload: the pi phase verifies the pinned file itself.
  assertFalse(agent.some(([name]) => name === "pi.receive-artifact"));
  assertEquals(steps["cleanup-smoke-reply"].status, "succeeded");
});

Deno.test("a Pi build with another digest never leaves 209", async () => {
  const { run, steps, agent, copies } = await build({ pi: true, env: { FIXTURE_BUILD: "tampered build" } }, false);
  assertEquals([run.status, steps["pi-check"].status], ["failed", "failed"]);
  assertEquals(copies.length, 1);
  assertFalse(agent.some(([name, call]) => name === "image.start-phase" && call.stdin.input.key.phase === "pi"));
  assertEquals(steps["cleanup-pi-local"].status, "succeeded");
});

Deno.test("an already complete phase is read without a wait", async () => {
  const { run, steps, calls } = await build({ env: { FIXTURE_START: "complete" } });
  assertEquals(run.status, "succeeded");
  assertEquals([steps["packages-wait"].status, steps["packages-inspect-reply"].status], ["skipped", "succeeded"]);
  assertFalse(calls.some((call) => call.argv.includes("/usr/bin/tail")));
});

Deno.test("an agent refusal stops the build and cleanup removes both guests", async () => {
  const result = await build({ env: { FIXTURE_REFUSE: "image.clean" } }, false);
  const { run, steps, agent } = result;
  assertEquals(run.status, "failed");
  assertEquals([steps["clean-guest"].status, steps["clean-guest-reply"].status], ["succeeded", "failed"]);
  assertStringIncludes(JSON.stringify(steps["clean-guest-reply"]), "agent.version_mismatch");
  assertEquals([steps.publish.status, steps.complete.status], ["skipped", "skipped"]);
  assertAgentInstalled(result);
  assertEquals(agent.slice(-3).map(([name]) => name), ["image.clean", "incus.vm", "incus.vm"]);
  assertEquals(agent.slice(-2).map(([, call]) => call.stdin.input.vm.name), ["tvm-image-7", "tvm-image-7-smoke"]);
  assertEquals(steps["cleanup-smoke-reply"].status, "succeeded");
});
