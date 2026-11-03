/**
 * Actual native UpCloud template DAG on official e459: inline curl from 209 against a disposable provider API,
 * and official SSH/SCP against disposable process boundaries. No server, guest or fleet is touched.
 *
 * A `curl` fixture first on PATH checks that no vault value is in its argv, drops `--proto =https`, points the fixed
 * UpCloud origin at the local server and runs the real curl, so its own `--variable` expansion builds the requests.
 */
import { assert, assertEquals, assertFalse, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const Pin = "a".repeat(64);
const Identity = "sha256:" + "b".repeat(64);
const Stamp = "20261102120000";
const Name = "tvm-image-" + Stamp;
const Boot = "878a384f-8b8a-4066-bd17-dcad8eac3e86";
const Source = "01000000-0000-4000-8000-000030260200";
const HostKey = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG " + Name;
const Token = "ucat_fixture_secret_token_3f31c";
const Private = "-----BEGIN OPENSSH PRIVATE KEY-----\nfixture-secret-host-key\n-----END OPENSSH PRIVATE KEY-----\n";
const Phases = ["apt-source", "packages", "javascript", "rust", "agent", "pi"];
const Workflows = ["operation-task-vm-upcloud-image-build", "task-vm-upcloud-image-server", "task-vm-upcloud-image-pi",
  "task-vm-upcloud-image-phases", "task-vm-upcloud-image-prepare"];
const Build = "pinned pi build";
const uuid = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;
const sha256 = async (text: string) =>
  Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text))), (byte) =>
    byte.toString(16).padStart(2, "0")).join("");

// The fake guest answers each remote argv the way the build servers would, in Python's sorted JSON text.
const fakeSsh = (state: string) => `
import { readStdin, shellSplit } from ${JSON.stringify(tool)};
const py = (v) => Array.isArray(v) ? "[" + v.map(py).join(", ") + "]"
  : v && typeof v === "object" ? "{" + Object.keys(v).sort().map((k) => JSON.stringify(k) + ": " + py(v[k])).join(", ") + "}"
  : JSON.stringify(v);
const argv = shellSplit(Deno.args.at(-1));
const body = Deno.stdin.isTerminal() ? "" : await readStdin();
const value = JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)}));
const call = { argv, ssh: Deno.args.slice(0, -1) };
if (body.trim().startsWith("{")) call.stdin = JSON.parse(body);
value.calls.push(call);
Deno.writeTextFileSync(${JSON.stringify(state)}, JSON.stringify(value));
const reply = (data) => console.log(py({ data, ok: true }));
const command = argv.indexOf("command");
if (command > 0 && argv[command - 1].split("/").at(-1).startsWith("orbit-agent")) {
  const name = argv[command + 1];
  const request = call.stdin.input;
  if (name === Deno.env.get("FIXTURE_REFUSE")) console.log(py({ error: { code: "agent.version_mismatch", message: "refused" }, ok: false }));
  else if (name === "agent.install") reply({ sha256: request.sha256, replaced: true });
  else if (name === "image.inspect-phase") reply({ boot_id: ${JSON.stringify(Boot)}, unit: "u", state: request.boot_id === null ? "missing" : "complete" });
  else if (name === "image.start-phase") reply({ boot_id: request.boot_id, unit: "u", state: "running" });
  else reply({ ready: true });
} else if (argv.includes("--property=MainPID")) console.log("4242");
`;

type Row = Record<string, any>;

/** A disposable UpCloud API: older verified templates 50 (newest kept), 51 (pruned) and 52 (pinned by a live VM). */
function provider() {
  const servers: Record<string, Row> = {};
  const templates: Record<string, Row> = {};
  const requests: [string, string][] = [];
  const bodies: Row[] = [];
  const self = { servers, templates, requests, bodies, stopLag: false, port: 0, shutdown: () => Promise.resolve() };
  for (const [n, at] of [[50, "20261101120000"], [51, "20261031120000"], [52, "20261030120000"]] as const) {
    templates[uuid(n)] = { uuid: uuid(n), title: "orbit-task-base-" + at, access: "private", state: "online",
      labels: [{ key: "orbit.recipe", value: Identity }, { key: "orbit-task-image", value: at }] };
  }
  const answer = (status: number, value: unknown) => new Response(status === 204 ? null : JSON.stringify(value), { status });
  const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen: () => {} }, async (request) => {
    const text = await request.text();
    const body = text ? JSON.parse(text) : null;
    const url = new URL(request.url);
    const method = request.method;
    requests.push([method, url.pathname + url.search]);
    if (request.headers.get("authorization") !== "Bearer " + Token) return answer(403, {});
    const path = (url.pathname + url.search).replace(/^\/1\.3\//, "");
    const id = path.split("/")[1]?.split("?")[0];
    if (method === "GET" && path === "plan") {
      return answer(200, { plans: { plan: [{ name: "STARTER-2xCPU-4GB", storage_tier: "standard" }] } });
    }
    if (method === "GET" && path === "server?label=orbit-task-image") {
      return answer(200, { servers: { server: Object.values(servers) } });
    }
    if (method === "GET" && path === "storage/template") return answer(200, { storages: { storage: Object.values(templates) } });
    if (method === "POST" && path === "server") {
      bodies.push(body);
      const source = body.server.storage_devices.storage_device[0].storage;
      if (templates[source] && templates[source].state !== "online") {
        templates[source].state = "online";
        return answer(409, { error: { error_code: "STORAGE_STATE_ILLEGAL" } });
      }
      const n = 60 + Object.keys(servers).length;
      const row = {
        uuid: uuid(n), title: body.server.title, hostname: body.server.hostname, state: "started", labels: body.server.labels,
        ip_addresses: { ip_address: [{ access: "public", family: "IPv4", address: `93.184.216.${n}` }] },
        storage_devices: { storage_device: [{ storage: uuid(n + 100), type: "disk" }] },
      };
      servers[row.uuid] = row;
      return answer(202, { server: row });
    }
    if (method === "POST" && path.endsWith("/stop")) {
      bodies.push(body);
      servers[id].state = self.stopLag ? "maintenance" : "stopped";
      return answer(200, { server: servers[id] });
    }
    if (method === "POST" && path.endsWith("/templatize")) {
      bodies.push(body);
      const row = Object.values(servers).find((row) => row.storage_devices.storage_device[0].storage === id)!;
      if (row.state !== "stopped") {
        row.state = "stopped";
        return answer(409, { error: { error_code: "SERVER_STATE_ILLEGAL" } });
      }
      const template = { uuid: uuid(70), title: body.storage.title, access: "private", state: "maintenance" };
      templates[template.uuid] = template;
      return answer(201, { storage: template });
    }
    if (method === "PUT" && path.startsWith("storage/")) {
      bodies.push(body);
      Object.assign(templates[id], body.storage);
      return answer(202, { storage: templates[id] });
    }
    if (method === "DELETE" && path.startsWith("server/")) {
      if (servers[id].state !== "stopped") {
        servers[id].state = "stopped";
        return answer(409, { error: { error_code: "SERVER_STATE_ILLEGAL" } });
      }
      delete servers[id];
      return answer(204, {});
    }
    if (method === "DELETE" && path.startsWith("storage/")) {
      delete templates[id];
      return answer(204, {});
    }
    return answer(404, {});
  });
  self.port = server.addr.port;
  self.shutdown = () => server.shutdown();
  return self;
}

type Call = { argv: string[]; ssh: string[]; stdin: { agent_sha256: string; input: Row } };

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 900_000;
  const api = provider();
  try {
    await repo.pull("@swamp/ssh");
    await Deno.mkdir(`${repo.dir}/extensions/behaviors/operations/task-vms`, { recursive: true });
    for (const name of ["upcloud_image_api.ts", "image_build_inputs.ts", "images.ts"]) {
      await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/task-vms/${name}`, `${repo.dir}/extensions/behaviors/operations/task-vms/${name}`);
    }
    const curl = new TextDecoder().decode((await new Deno.Command("sh", { args: ["-c", "command -v curl"] }).output()).stdout).trim();
    await Deno.mkdir(`${repo.dir}/tools`);
    await repo.fakeBinary("tools/curl", `
const args = [...Deno.args];
const fail = (message) => { console.error(message, JSON.stringify(args)); Deno.exit(97); };
// Vault values reach curl only from its environment, through --variable.
if (args[args.indexOf("--variable") + 1] !== "%ORBIT_UPCLOUD_TOKEN") fail("variable");
for (const name of ["ORBIT_UPCLOUD_TOKEN", "ORBIT_HOST_PRIVATE_KEY"]) {
  const value = Deno.env.get(name);
  if (value && args.join(" ").includes(value)) fail(name);
}
const proto = args.indexOf("--proto");
if (args[proto + 1] !== "=https") fail("proto");
args.splice(proto, 2);
if (!args.at(-1).startsWith("https://api.upcloud.com/1.3/")) fail("origin");
args[args.length - 1] = args.at(-1).replace("https://api.upcloud.com/1.3", "http://127.0.0.1:${api.port}/1.3");
const { code } = await new Deno.Command(${JSON.stringify(curl)}, { args, stdin: "inherit", stdout: "inherit", stderr: "inherit" }).output();
Deno.exit(code);
`);
    const state = `${repo.dir}/guest.json`;
    await Deno.writeTextFile(state, JSON.stringify({ calls: [] }));
    const ssh = await repo.fakeBinary("ssh", fakeSsh(state));
    const copies = `${repo.dir}/copies.jsonl`;
    const scp = await repo.fakeBinary("scp", `
import { logCall } from ${JSON.stringify(tool)};
logCall(${JSON.stringify(copies)}, Deno.args);
if (!Deno.args.at(-1).includes(":")) Deno.writeTextFileSync(Deno.args.at(-1), Deno.env.get("FIXTURE_BUILD"));
`);
    const agent = `${repo.dir}/orbit-agent`;
    await Deno.writeTextFile(agent, "pinned agent fixture");
    for (const name of Workflows) {
      const tracked = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as {
        jobs: { labels?: unknown; steps: { name: string; labels: unknown }[] }[];
      };
      for (const job of tracked.jobs) {
        assertEquals(job.labels, undefined);
        for (const step of job.steps) assertEquals(step.labels, { role: "swamp" }, step.name);
      }
      await repo.workflow(name, (workflow) => {
        for (const step of workflow.jobs.flatMap((job) => job.steps)) {
          const task = step.task as { behaviorType?: string; globalArgs: Row; inputs: Row };
          if (task.behaviorType !== "@swamp/ssh") continue;
          Object.assign(task.globalArgs, { sshBinary: ssh, scpBinary: scp });
          if (task.inputs.src === "/usr/local/bin/orbit-agent") task.inputs.src = agent;
        }
      });
    }
    await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
    await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-upcloud-token"], { input: Token });
    await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-image-bootstrap-9"], { input: Private });
    repo.secrets = [Token, "fixture-secret-host-key"];
    repo.env = { FIXTURE_BUILD: Build, PATH: `${repo.dir}/tools:${Deno.env.get("PATH")}` };
    const key = (phase: string) => ({ identity: Identity, profile: "base", phase, execution: "upcloud-9" });
    const envelope = (seconds: number, data: unknown) =>
      JSON.stringify({ agent_sha256: Pin, deadline_ms: seconds * 1000, input: data }) + "\n";
    const recipe = { identity: Identity, agent_sha256: Pin, pi_sha256: null as string | null };
    const candidate = "/var/tmp/orbit-agent-candidate-" + Pin;
    const intent: Row = {
      build_id: 9, node_id: 14, identity: Identity, generation: 1, stamp: Stamp, name: Name, source: Source, agent_sha256: Pin, recipe,
      upcloud: { zone: "nl-ams1", plan: "STARTER-2xCPU-4GB", disk_gb: 30 }, pinned_templates: [uuid(52)], host_key: HostKey,
      ssh: { identity_file: "/etc/orbit/swamp-role-worker/ssh-identity", public_key: "ssh-ed25519 WORKER" }, pi_artifact: null,
      pi_source: { control_node_id: 209, identity_file: "/fixture/key", public_key: "ssh-ed25519 WORKER",
        target: { name: "target", host: "192.0.2.14", user: "orbit", port: 22, host_keys: [HostKey] } },
      candidate,
      envelopes: {
        install: envelope(180, { candidate, sha256: Pin }), cloud_init: envelope(45, {}), owner: envelope(120, {}),
        inspect_boot: envelope(30, { key: key("apt-source"), boot_id: null }),
        marker: envelope(120, { identity: Identity, profile: "base" }), verify: envelope(120, recipe),
        boot: envelope(120, { lean: false }), firewall: envelope(120, {}), clean: envelope(120, {}),
        check: envelope(120, { lean_boot: false, firewall: true }),
      },
      phase_keys: Object.fromEntries(Phases.map((phase) => [phase, key(phase)])),
      units: Object.fromEntries(
        await Promise.all(Phases.map(async (phase) => [
          phase,
          `orbit-recipe-image-${(await sha256(`${Identity}:base:${phase}:upcloud-9`)).slice(0, 32)}.service`,
        ])),
      ),
      template_data: JSON.stringify({ storage: { title: `orbit-task-base-${Stamp}-unverified` } }),
      promote_data: JSON.stringify({ storage: { title: `orbit-task-base-${Stamp}`,
        labels: [{ key: "orbit.recipe", value: Identity }, { key: "orbit-task-image", value: Stamp }] } }),
    };
    return {
      repo,
      api,
      intent,
      async withPi() {
        const digest = await sha256(Build);
        intent.recipe = { ...recipe, pi_sha256: digest };
        intent.pi_artifact = { path: "/srv/orbit/sandbox-artifacts/pi", sha256: digest };
        return digest;
      },
      async run(success = true) {
        const { run, history } = await repo.runWorkflow(Workflows[0], {
          operation_id: 7001, node_id: 14, kind: "task_vm.upcloud_image_build", intent,
          execution_secrets: {
            upcloud: { vault: "orbit-control-inputs", key: "task-vm-upcloud-token" },
            bootstrap: { vault: "orbit-control-inputs", key: "task-vm-image-bootstrap-9" },
          },
        }, { success });
        const steps = Object.fromEntries(history.jobs.flatMap((job) => job.steps).map((step) => [step.name, step as Row]));
        const calls = (JSON.parse(await Deno.readTextFile(state)) as { calls: Call[] }).calls;
        const agentCalls = calls.filter((call) => call.argv.includes("command")).map((call) =>
          [call.argv[call.argv.indexOf("command") + 1], call] as const
        );
        return { run, steps, calls, agent: agentCalls, copies: readCalls<string[]>(copies) };
      },
      async [Symbol.asyncDispose]() {
        await api.shutdown();
        await repo[Symbol.asyncDispose]();
      },
    };
  } catch (error) {
    await api.shutdown();
    await repo[Symbol.asyncDispose]();
    throw error;
  }
}

Deno.test("the build launches, boots, templates, smokes, promotes and cleans from 209", async () => {
  await using test = await fixture();
  for (const name of Workflows) await test.repo.cli(["workflow", "validate", name]);
  test.api.stopLag = true;
  const { run, steps, calls, agent } = await test.run();
  const { api } = test;
  assertEquals(run.status, "succeeded");
  assertEquals([steps["Check the template"].status, steps["Stage the Pi build"].status], ["succeeded", "skipped"]);
  assertEquals(steps["Read the template"].outputs.template_uuid, uuid(70));
  // Creates carry the vault host key through curl's own expansion; the run history never holds it.
  // The smoke create is refused once while UpCloud still prepares the template; curl sends it again.
  const creates = api.bodies.filter((body) => body?.server?.zone).map((body) => body.server);
  assertEquals(creates.length, 3);
  const [builder, smoke] = [creates[0], creates.at(-1)!];
  assertEquals([builder.title, smoke.title], [Name, Name + "-smoke"]);
  assertStringIncludes(builder.user_data, "fixture-secret-host-key");
  assertEquals(builder.storage_devices.storage_device[0].storage, Source);
  assertEquals(smoke.storage_devices.storage_device[0].storage, uuid(70));
  // The guest waits on OpenSSH's connection retries; every connection pins the admitted host key.
  const boot = calls.filter((call) => call.argv.includes("/usr/bin/cloud-init"));
  assertEquals(boot.length, 2);
  assertEquals(boot[0].argv, ["timeout", "900", "/usr/bin/sudo", "-n", "--", "/usr/bin/cloud-init", "status", "--wait"]);
  assert(boot[0].ssh.includes("ConnectionAttempts=90"));
  assertEquals(agent.map(([name]) => name), [
    "agent.install", "guest.cloud-init", "image.owner", "image.inspect-phase",
    ...Array(5).fill(["image.start-phase", "image.inspect-phase"]).flat(),
    "image.recipe-marker", "image.verify", "image.boot", "image.firewall", "image.clean",
    "agent.install", "guest.cloud-init", "image.owner", "image.inspect-phase", "image.verify", "image.check",
  ]);
  for (const [name, call] of agent) {
    assertEquals(call.argv[0], "timeout", name);
    assertEquals(call.stdin.agent_sha256, Pin, name);
  }
  assertEquals(agent.find(([name]) => name === "image.owner")![1].argv, [
    "timeout", "150", "/usr/local/bin/orbit-agent", "command", "image.owner",
  ]);
  const starts = agent.filter(([name]) => name === "image.start-phase").map(([, call]) => call.stdin.input);
  assertEquals(starts.map((start) => start.key.phase), Phases.slice(0, 5));
  assert(starts.every((start) => start.boot_id === Boot && start.key.execution === "upcloud-9"));
  const tails = calls.filter((call) => call.argv.includes("/usr/bin/tail")).map((call) => call.argv);
  assertEquals(tails[0], ["timeout", "1500", "/usr/bin/tail", "--pid=4242", "-f", "/dev/null"]);
  assertEquals(steps["Run the phases"].status, "succeeded");
  // Stop, then templatize retried by curl until the server stopped; the promoted template carries both labels.
  const order = api.requests.filter(([method]) => method !== "GET").map(([method, path]) =>
    [method, path.split("?")[0].replace(/^\/1\.3\//, "")]
  );
  const templatize = ["POST", `storage/${uuid(160)}/templatize`];
  assertEquals(order.slice(0, 7), [
    ["POST", "server"], ["POST", `server/${uuid(60)}/stop`], templatize, templatize, ["POST", "server"], ["POST", "server"],
    ["PUT", `storage/${uuid(70)}`],
  ]);
  assertEquals(api.templates[uuid(70)].title, "orbit-task-base-" + Stamp);
  // Cleanup: both servers stop and go; template 51 is pruned, 50 is among the two newest, 52 is pinned.
  assertEquals(Object.keys(api.templates).sort(), [uuid(50), uuid(52), uuid(70)]);
  assertEquals(api.servers, {});
  assert(order.some(([method, path]) => method === "POST" && path === `server/${uuid(61)}/stop`));
  assertEquals(steps["Delete servers, templates-0"].status, "succeeded");
  // Only the fixed provider origin is reached: no Gateway request from any step.
  assert(api.requests.every(([, path]) => path.startsWith("/1.3/")));
});

Deno.test("a Pi build reaches the builder cache through 209", async () => {
  await using test = await fixture();
  const digest = await test.withPi();
  const { run, calls, agent, copies } = await test.run();
  assertEquals(run.status, "succeeded");
  const fetched = copies.filter((copy) => copy.at(-2)!.endsWith(":/srv/orbit/sandbox-artifacts/pi"));
  assertEquals(fetched.length, 1);
  assertStringIncludes(fetched[0].at(-2)!, "192.0.2.14");
  const install = calls.find((call) => call.argv.includes("/usr/bin/install"))!.argv;
  assertEquals(install.slice(-2), [`/tmp/orbit-image-pi-${digest}.bin`, `/var/cache/orbit/pi/${digest}.bin`]);
  assertEquals(install.slice(0, 13), [
    "timeout", "60", "/usr/bin/sudo", "-n", "--", "/usr/bin/install", "-D", "-m", "0600", "-o", "root", "-g", "root",
  ]);
  assertEquals(agent.filter(([name]) => name === "image.start-phase").map(([, call]) => call.stdin.input.key.phase), Phases);
});

Deno.test("a tampered Pi build never reaches the builder", async () => {
  await using test = await fixture();
  await test.withPi();
  test.repo.env.FIXTURE_BUILD = "tampered build";
  const { run, steps, calls } = await test.run(false);
  assertEquals([run.status, steps["Stage the Pi build"].status, steps["Run the phases"].status], ["failed", "failed", "skipped"]);
  assertFalse(calls.some((call) => call.argv.includes("/usr/bin/install")));
  assertEquals(test.api.servers, {});
});

Deno.test("a refused smoke check keeps promotion out and removes servers and template", async () => {
  await using test = await fixture();
  test.repo.env.FIXTURE_REFUSE = "image.check";
  const { run, steps } = await test.run(false);
  assertEquals([run.status, steps["Smoke the template"].status], ["failed", "failed"]);
  // The parent step carries the child's public refusal code, which the Gateway maps to the error code.
  assertEquals(steps["Smoke the template"].error, "agent.version_mismatch");
  assertEquals([steps["Promote the template"].status, steps["Check the template"].status], ["skipped", "skipped"]);
  assertEquals(steps["Plan the cleanup"].outputs.promoted, false);
  assertFalse(test.api.requests.some(([method, path]) => method === "PUT" && path === `/1.3/storage/${uuid(70)}`));
  // The unverified template and both servers go; every verified template stays.
  assertEquals(Object.keys(test.api.templates).sort(), [uuid(50), uuid(51), uuid(52)]);
  assertEquals(test.api.servers, {});
});

Deno.test("an agent refusal on the builder makes no template", async () => {
  await using test = await fixture();
  test.repo.env.FIXTURE_REFUSE = "image.clean";
  const { run, steps } = await test.run(false);
  assertEquals([run.status, steps["Prepare and clean"].status, steps["Make the template"].status], ["failed", "failed", "skipped"]);
  assertFalse(test.api.requests.some(([, path]) => path.endsWith("/templatize")));
  assertEquals(test.api.servers, {});
});
