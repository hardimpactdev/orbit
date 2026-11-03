/**
 * The official native209 sweep graph against disposable provider and SSH boundaries.
 *
 * The fake tools stand in for the hosts: `ssh` runs the remote argv locally without `sudo -n --`, `incus` answers
 * from a JSON state file, and `curl` sends UpCloud requests to a local HTTP server. Every step argv stays as written.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { type History, nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { readCalls } from "../../../../tests/_support/fake_tool.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;

// Removals run in parallel: the fake serializes its read-modify-write of the state file.
const incus = (state: string) => `
using lock = Deno.openSync(${JSON.stringify(state + ".lock")}, { write: true, create: true });
lock.lockSync(true);
const args = Deno.args;
const data = JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)}));
const url = (base, [project, name]) => base + name + (project === "default" ? "" : "?project=" + project);
if (args[0] === "query") {
  const parsed = new URL(args[1], "http://incus");
  const project = parsed.searchParams.get("project") ?? "default";
  const every = parsed.searchParams.has("all-projects");
  if (!every && !data.projects.includes(project)) {
    if (parsed.pathname === "/1.0/instances") { console.log("[]"); Deno.exit(0); }
    console.error("Error: Project not found");
    Deno.exit(1);
  }
  if (parsed.pathname === "/1.0/instances") {
    console.log(JSON.stringify(data.instances.filter((row) => every || row[0] === project).map((row) => url("/1.0/instances/", row))));
  } else if (parsed.pathname === "/1.0/storage-pools/tasks/volumes/custom") {
    console.log(JSON.stringify(data.volumes.map((row) => url(parsed.pathname + "/", row))));
  } else if (parsed.pathname === "/1.0/images") console.log("[]");
  else Deno.exit(9);
  Deno.exit(0);
}
const project = args[args.indexOf("--project") + 1];
const name = args.at(-1);
const key = args.slice(0, 3).join(" ") === "storage volume delete" ? "volumes" : "instances";
if (key === "instances" && args.slice(0, 2).join(" ") !== "delete --force") Deno.exit(9);
const index = data[key].findIndex((row) => row[0] === project && row[1] === name);
if (index < 0) Deno.exit(1);
if (!data.keep) {
  data[key].splice(index, 1);
  Deno.writeTextFileSync(${JSON.stringify(state)}, JSON.stringify(data));
}
`;

const ssh = (calls: string, block: string) => `
import { logCall, passThrough, shellSplit } from ${JSON.stringify(tool)};
if (Deno.args.includes("-M") || Deno.args.includes("-O")) Deno.exit(0);
try { Deno.statSync(${JSON.stringify(block)}); Deno.exit(255); } catch (error) { if (!(error instanceof Deno.errors.NotFound)) throw error; }
const argv = shellSplit(Deno.args.at(-1));
logCall(${JSON.stringify(calls)}, argv);
if (argv.slice(0, 3).join(" ") !== "sudo -n --") throw new Error(argv.join(" "));
await passThrough(argv.slice(3), new Uint8Array());
`;

// The token reaches curl from its environment through --variable; argv holds only the name.
const curl = (origin: string) => `
const args = Deno.args;
const token = Deno.env.get("ORBIT_UPCLOUD_TOKEN");
const after = (flag) => args[args.indexOf(flag) + 1];
if (after("--variable") !== "%ORBIT_UPCLOUD_TOKEN" || args.join(" ").includes(token)) throw new Error(args.join(" "));
const [name, value] = after("--expand-header").replace("{{ORBIT_UPCLOUD_TOKEN}}", token).split(": ");
const response = await fetch(args.at(-1).replace("https://api.upcloud.com/1.3", ${JSON.stringify(origin)}), {
  method: args.includes("--request") ? after("--request") : "GET",
  body: args.includes("--data") ? after("--data") : undefined,
  headers: { [name]: value, "Content-Type": "application/json" },
});
const body = await response.text();
if (!response.ok) { console.error("curl: (22) " + response.status); Deno.exit(22); }
await Deno.stdout.write(new TextEncoder().encode(body));
`;

type Server = { uuid: string; title: string; hostname: string; state: string; labels: { label: { key: string; value: string }[] } };

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("operations/task-vm-sweep");
  await repo.pull("@swamp/ssh");
  const state = `${repo.dir}/state.json`;
  const calls = `${repo.dir}/calls.jsonl`;
  const block = `${repo.dir}/block`;
  await Deno.writeTextFile(
    state,
    JSON.stringify({
      projects: ["tasks", "default"],
      instances: [
        ...["tvm-6", "tvm-7", "tvm-11", "tvm-image-16", "tvm-image-18"].map((name) => ["tasks", name]),
        ["default", "web"],
      ],
      volumes: [["tasks", "tvm-6-topology-seed"], ["tasks", "tvm-7-topology-seed"]],
    }),
  );
  await Deno.mkdir(`${repo.dir}/tools`);
  await repo.fakeBinary("tools/incus", incus(state));
  await repo.fakeBinary("tools/ssh", ssh(calls, block));
  repo.env.PATH = `${repo.dir}/tools:${Deno.env.get("PATH")}`;
  await repo.workflow("operation-task-vm-sweep");
  const inputs = {
    operation_id: 77,
    node_id: 9,
    kind: "task_vm.sweep_host",
    execution_secrets: {} as Record<string, unknown>,
    intent: {
      node_id: 9,
      provider: "incus",
      ci_runner: false,
      stray_vm_ids: [],
      image_cutoff: 0,
      running_stamps: [],
      scope: {
        project: "tasks",
        pool: "tasks",
        operator_owned: [],
        live_names: ["tvm-7"],
        max_task_vm_id: 10,
        max_build_id: 20,
        recorded_build_ids: [18],
        protected_build_ids: [18],
        retired_build_ids: [],
      } as Record<string, unknown>,
      ssh_execution: {
        identity_file: "/fixture/identity",
        target: {
          host: "192.0.2.9",
          user: "orbit",
          port: 22,
          host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"],
        },
      } as unknown,
    } as Record<string, unknown>,
  };
  const cloud = { calls: [] as string[], servers: new Map<string, Server>(), templates: new Map<string, unknown>(), pendingStop: false };
  let server: Deno.HttpServer<Deno.NetAddr> | undefined;

  const sweep = (success = true) => repo.runWorkflow("operation-task-vm-sweep", inputs, { success });
  /** What the Gateway reads: the plan step's names once the provider's end check passed. */
  const result = (history: History) => {
    const steps = Object.fromEntries(history.jobs.flatMap((job) => job.steps).map((step) => [step.name, step]));
    const [plan, check] = inputs.intent.provider === "upcloud"
      ? ["Plan the cloud removals", "Check the cloud removals"]
      : ["Plan the removals", "Check the VMs are gone"];
    if (steps[check].status !== "succeeded") {
      return { ready: false, failed_steps: Object.values(steps).filter((step) => step.status === "failed").map((step) => step.name) };
    }
    const outputs = steps[plan].outputs as { observed_names: string[]; deleted_names: string[] };
    return { ready: true, observed_names: outputs.observed_names, deleted_names: outputs.deleted_names };
  };

  const enableCloud = async () => {
    const token = `ucat_native_sweep_${crypto.randomUUID().replaceAll("-", "")}`;
    repo.secrets = [token];
    for (const n of [6, 7, 11]) {
      const uuid = crypto.randomUUID();
      cloud.servers.set(uuid, {
        uuid,
        title: `tvm-${n}`,
        hostname: `tvm-${n}`,
        state: "started",
        labels: { label: [{ key: "orbit-task-vm", value: String(n) }] },
      });
    }
    const template = crypto.randomUUID();
    cloud.templates.set(template, { uuid: template, title: "orbit-task-base-20240101000000-unverified", access: "private" });
    server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen: () => {} }, async (request) => {
      const url = new URL(request.url);
      cloud.calls.push(request.method);
      if (request.headers.get("Authorization") !== `Bearer ${token}`) return Response.json({}, { status: 403 });
      if (request.method === "GET") {
        if (url.pathname === "/server") {
          const label = url.searchParams.get("label");
          const matching = [...cloud.servers.values()].filter((row) => row.labels.label.some((entry) => entry.key === label));
          return Response.json({ servers: { server: matching } });
        }
        if (url.pathname === "/storage/template") return Response.json({ storages: { storage: [...cloud.templates.values()] } });
        return Response.json({}, { status: 404 });
      }
      if (request.method === "POST") {
        assertEquals(await request.json(), { stop_server: { stop_type: "hard" } });
        cloud.servers.get(url.pathname.split("/")[2])!.state = cloud.pendingStop ? "maintenance" : "stopped";
        return Response.json({});
      }
      const [prefix, key] = url.pathname.replace(/^\/|\/$/g, "").split("/");
      if (prefix === "server") {
        assertEquals(url.search, "?storages=1&backups=delete");
        if (cloud.servers.get(key)!.state !== "stopped") {
          return Response.json({ error: { error_code: "SERVER_STATE_ILLEGAL" } }, { status: 409 });
        }
      }
      (prefix === "server" ? cloud.servers : cloud.templates).delete(key);
      return new Response(null, { status: 204 });
    });
    await repo.fakeBinary("tools/curl", curl(`http://127.0.0.1:${server.addr.port}`));
    await repo.cli(["vault", "create", "local_encryption", "orbit-control-inputs"]);
    await repo.cli(["vault", "put", "orbit-control-inputs", "task-vm-upcloud-token"], { input: token });
    inputs.execution_secrets = { upcloud: { vault: "orbit-control-inputs", key: "task-vm-upcloud-token" } };
    delete inputs.intent.ssh_execution;
    Object.assign(inputs.intent, { provider: "upcloud", scope: { live_names: ["tvm-7"], max_task_vm_id: 10 }, image_cutoff: 1735689600 });
  };

  return {
    repo,
    inputs,
    state,
    block,
    cloud,
    sweep,
    result,
    enableCloud,
    remote: () => readCalls<string[]>(calls),
    posts: () => cloud.calls.filter((method) => method === "POST").length,
    [Symbol.asyncDispose]: async () => {
      await server?.shutdown();
      await repo[Symbol.asyncDispose]();
    },
  };
}

Deno.test("Incus cleanup preserves live and future resources", async () => {
  await using test = await fixture();
  const { run, history, steps } = await test.sweep();
  assertEquals(run.status, "succeeded");
  const result = test.result(history);
  assertEquals(result.deleted_names!.toSorted(), ["tvm-6", "tvm-6-topology-seed", "tvm-image-16"]);
  assertEquals(result.observed_names!.toSorted(), ["tvm-11", "tvm-7", "tvm-image-18"]);
  const state = JSON.parse(await Deno.readTextFile(test.state));
  assertEquals(state.instances, [["tasks", "tvm-7"], ["tasks", "tvm-11"], ["tasks", "tvm-image-18"], ["default", "web"]]);
  assertEquals(state.volumes, [["tasks", "tvm-7-topology-seed"]]);
  const writes = test.remote().filter((argv) => !argv.includes("query")).map((argv) => argv.slice(3));
  const remove = ["timeout", "180", "incus", "delete", "--force", "--project", "tasks", "--"];
  assertEquals(writes.map((argv) => argv.join(" ")).toSorted(), [
    [...remove, "tvm-6"],
    [...remove, "tvm-image-16"],
    ["timeout", "180", "incus", "storage", "volume", "delete", "--project", "tasks", "--", "tasks", "tvm-6-topology-seed"],
  ].map((argv) => argv.join(" ")).toSorted());
  assertEquals(writes.at(-1)!.slice(3, 5), ["storage", "volume"]);
  for (const name of ["List servers, templates", "Plan the cloud removals", "List again", "Check the cloud removals"]) {
    assertEquals(steps[name], "skipped", name);
  }
  for (const name of ["format", "result", "accepted", "incus", "cloud"]) assert(!(name in steps), name);
  assertEquals(test.result((await test.sweep()).history).deleted_names, []);
});

Deno.test("a removed VM that is still listed fails the end check", async () => {
  await using test = await fixture();
  const state = JSON.parse(await Deno.readTextFile(test.state));
  await Deno.writeTextFile(test.state, JSON.stringify({ ...state, keep: true }));
  const { run, steps } = await test.sweep(false);
  assertEquals(run.status, "failed");
  assertEquals(Object.fromEntries(Object.entries(steps).filter(([name]) => name.startsWith("Remove orphaned VMs-"))), {
    "Remove orphaned VMs-tvm-6": "succeeded",
    "Remove orphaned VMs-tvm-image-16": "succeeded",
  });
  assertEquals(steps["Check the VMs are gone"], "failed");
});

Deno.test("an SSH failure skips cleanup and keeps the native failure", async () => {
  await using test = await fixture();
  await Deno.writeTextFile(test.block, "");
  const { run, history } = await test.sweep(false);
  assertEquals(run.status, "failed");
  assertEquals(test.remote(), []);
  const result = test.result(history);
  assertEquals(result.ready, false);
  assertEquals(result.failed_steps!.toSorted(), [
    "Read the inventory-images",
    "Read the inventory-instances",
    "Read the inventory-volumes",
  ]);
});

Deno.test("a missing project fails the host without a change", async () => {
  await using test = await fixture();
  test.inputs.intent.scope = { ...test.inputs.intent.scope as object, project: "gone" };
  const { run, history } = await test.sweep(false);
  assertEquals(run.status, "failed");
  assertEquals(test.remote().filter((argv) => !argv.includes("query")), []);
  assertEquals(test.result(history).ready, false);
});

Deno.test("cloud cleanup uses the vault token and preserves live and future ids", async () => {
  await using test = await fixture();
  await test.enableCloud();
  const result = test.result((await test.sweep()).history);
  assert(result.ready);
  assertEquals(result.deleted_names!.toSorted(), ["orbit-task-base-20240101000000-unverified", "tvm-6"]);
  assertEquals(result.observed_names!.toSorted(), ["tvm-11", "tvm-7"]);
  assertEquals([...test.cloud.servers.values()].map((row) => row.title).toSorted(), ["tvm-11", "tvm-7"]);
  assertEquals(test.cloud.templates.size, 0);
  assertEquals(test.remote(), []);
  assertEquals(test.posts(), 1);
  assertEquals(test.result((await test.sweep()).history).deleted_names, []);
});

Deno.test("a pending cloud stop fails without a delete or a custom wait", async () => {
  await using test = await fixture();
  await test.enableCloud();
  test.cloud.pendingStop = true;
  const { run, history } = await test.sweep(false);
  assertEquals(run.status, "failed");
  assertEquals(test.result(history).ready, false);
  assertEquals(test.posts(), 1);
  const vm = [...test.cloud.servers.values()].find((row) => row.title === "tvm-6");
  assert(vm);
  test.cloud.pendingStop = false;
  vm.state = "stopped";
  assert(test.result((await test.sweep()).history).deleted_names!.includes("tvm-6"));
  assertEquals(test.posts(), 1);
});
