/** Real native image reaper DAG with the official SSH package on published inputs; isolated SSH binary, no host effects. */
import { assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT } from "../../../../tests/_support/native_harness.ts";

const tool = new URL("../../../../tests/_support/fake_tool.ts", import.meta.url).href;
const Incus = ["/usr/bin/sudo", "-n", "--", "/usr/bin/incus", "--project", "orbit-tasks"];

// Each host address answers its Incus argv from the fixture state, like the real host would.
const fakeSsh = (state: string) => `
import { shellSplit } from ${JSON.stringify(tool)};
const state = JSON.parse(Deno.readTextFileSync(${JSON.stringify(state)}));
const address = Deno.args.find((arg) => arg.startsWith("orbit@")).split("@")[1];
let argv = shellSplit(Deno.args.at(-1));
state.calls.push([address, argv]);
const host = state.hosts[address];
if (argv[0] === "timeout" && argv[1] === "150") argv = argv.slice(2);
if (argv.slice(0, 6).join(" ") !== ${JSON.stringify(Incus.join(" "))}) throw new Error(argv.join(" "));
argv = argv.slice(6);
let code = 0;
const line = argv.join(" ");
if (line === "list --format json") console.log(JSON.stringify(Object.values(host.vms)));
else if (line.startsWith("delete --force -- ")) delete host.vms[argv[3]];
else if (line === "storage volume list orbit-tasks --format json") {
  console.log(JSON.stringify([...Object.keys(host.vms), ...(host.leftover ?? [])].map((name) => ({ type: "virtual-machine", name }))));
} else code = 2;
Deno.writeTextFileSync(${JSON.stringify(state)}, JSON.stringify(state));
Deno.exit(code);
`;

type State = { calls: [string, string[]][]; hosts: Record<string, { vms: Record<string, unknown>; leftover?: string[] }> };

const vm = (name: string, { hours = 3, pool = "orbit-tasks", status = "Stopped" } = {}) => ({
  name, type: "virtual-machine", status, created_at: new Date(Date.now() - hours * 3600_000).toISOString(),
  expanded_devices: { root: { type: "disk", path: "/", pool } },
});
const vms = (...list: ReturnType<typeof vm>[]) => Object.fromEntries(list.map((value) => [value.name, value]));

async function* raws(directory: string): AsyncGenerator<string> {
  for await (const entry of Deno.readDir(directory)) {
    const path = `${directory}/${entry.name}`;
    if (entry.isDirectory) yield* raws(path);
    else if (entry.name === "raw") yield path;
  }
}

async function reap(hosts: Record<string, unknown>[], incus: State["hosts"], success = true) {
  await using repo = await nativeRepo();
  repo.timeoutMs = 300_000;
  await repo.pull("@swamp/ssh");
  await Deno.mkdir(`${repo.dir}/extensions/behaviors/operations/task-vms`, { recursive: true });
  await Deno.copyFile(`${ROOT}/extensions/behaviors/operations/task-vms/image_reaper.ts`, `${repo.dir}/extensions/behaviors/operations/task-vms/image_reaper.ts`);
  const key = `${repo.dir}/key`;
  await Deno.writeTextFile(key, "synthetic fixture identity, never used by SSH", { mode: 0o600 });
  const state = `${repo.dir}/incus.json`;
  const ssh = await repo.fakeBinary("ssh", fakeSsh(state));
  // Only fixture placement and the official package's own binary seam differ.
  const source = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-task-vm-image-reaper.yaml`)) as {
    jobs: { steps: { labels: unknown }[] }[];
  };
  for (const step of source.jobs.flatMap((job) => job.steps)) assertEquals(step.labels, { role: "swamp" });
  const tracked = (await repo.workflow("task-vm-image-reaper", (workflow) => {
    for (const step of workflow.jobs.flatMap((job) => job.steps)) {
      const task = step.task as { behaviorType: string; globalArgs: Record<string, unknown> };
      assertFalse("target" in step);
      if (task.behaviorType === "@swamp/ssh") task.globalArgs.sshBinary = ssh;
    }
  })) as Record<string, unknown>;
  assertEquals([(tracked.trigger as { schedule: string }).schedule, tracked.concurrency, tracked.queueTimeout], ["0 */30 * * * *", 1, 30]);
  await Deno.writeTextFile(state, JSON.stringify({ calls: [], hosts: incus }));
  await Deno.writeTextFile(
    `${repo.dir}/inputs.json`,
    JSON.stringify({ revision: 4, hosts: hosts.map((host) => ({ ...host, identity_file: key })) }),
  );
  await repo.cli(["workflow", "validate", "task-vm-image-reaper"]);
  const run = JSON.parse(
    (await repo.cli(["workflow", "run", "task-vm-image-reaper", "--input-file", `${repo.dir}/inputs.json`], { success })).stdout,
  );
  const sweeps = [];
  for await (const path of raws(`${repo.dir}/.swamp/data`)) {
    try {
      const value = JSON.parse(await Deno.readTextFile(path));
      if (value && typeof value === "object" && "unlisted" in value) sweeps.push(value);
    } catch {
      // Not JSON.
    }
  }
  assertEquals(sweeps.length, 1);
  return { run, state: JSON.parse(await Deno.readTextFile(state)) as State, sweep: sweeps[0] };
}

/** The Gateway's published shape: host settings, protected build ids and the pinned SSH target. */
const host = (nodeId: number, protectedBuilds: number[] = [], owned: string[] = []) => ({
  node_id: nodeId, project: "orbit-tasks", pool: "orbit-tasks", operator_owned: owned, protected_builds: protectedBuilds,
  name: "target", host: `10.44.0.${nodeId}`, user: "orbit", port: 22,
  host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICfyJ+CMCGNc9YCsyn3OdkhwyeKL0qHT/+ASm3iPNAvT"],
});

Deno.test("old unreferenced builders are deleted and every other VM is kept", async () => {
  const { run, state, sweep } = await reap([host(9, [8], ["orbit-tasks/tvm-image-11-builder"]), host(13)], {
    "10.44.0.9": {
      vms: vms(
        vm("tvm-image-7", { status: "Running" }), // deleted
        vm("tvm-image-8-attempt-2-smoke"), // protected: build 8 is active
        vm("tvm-image-10", { hours: 1 }), // younger than two hours
        vm("tvm-image-11-builder"), // operator-owned
        vm("tvm-image-12", { pool: "foreign-pool" }), // root on another pool
        vm("tvm-175"), // an ordinary task VM
      ),
    },
    "10.44.0.13": { vms: vms(vm("tvm-image-20")) },
  });
  assertEquals(run.status, "succeeded");
  assertEquals(state.calls.filter(([, argv]) => argv.includes("delete")).map(([address, argv]) => [address, argv.at(-1)]), [
    ["10.44.0.9", "tvm-image-7"],
    ["10.44.0.13", "tvm-image-20"],
  ]);
  assertEquals(
    Object.keys(state.hosts["10.44.0.9"].vms).sort(),
    ["tvm-175", "tvm-image-10", "tvm-image-11-builder", "tvm-image-12", "tvm-image-8-attempt-2-smoke"],
  );
  assertEquals(sweep, {
    hosts: [{ node_id: 9, deleted: ["tvm-image-7"], failed: [] }, { node_id: 13, deleted: ["tvm-image-20"], failed: [] }],
    unlisted: [],
  });
});

Deno.test("a remaining volume or unreachable host fails the run and spares other hosts", async () => {
  const { state, sweep } = await reap([host(9), host(13), host(14)], { // 10.44.0.14 does not answer
    "10.44.0.9": { vms: vms(vm("tvm-image-7")), leftover: ["tvm-image-7/snapshot0"] },
    "10.44.0.13": { vms: vms(vm("tvm-image-20")) },
  }, false);
  assertEquals(sweep, {
    hosts: [{ node_id: 9, deleted: [], failed: ["tvm-image-7"] }, { node_id: 13, deleted: ["tvm-image-20"], failed: [] }],
    unlisted: [14],
  });
  assertEquals(state.hosts["10.44.0.13"].vms, {});
});
