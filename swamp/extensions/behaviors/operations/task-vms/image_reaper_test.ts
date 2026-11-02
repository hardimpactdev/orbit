import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type ImageBuilderDeletion,
  imageReaper,
  ImageReaperApi,
  reapImageBuilders,
} from "./image_reaper.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { SystemTransport } from "../node/system_command.ts";
import { parse } from "jsr:@std/yaml@1.0.12";

const now = Date.parse("2026-10-31T12:00:00Z");
const host = {
  node_id: 9,
  user: "nckrtl",
  project: "orbit-tasks",
  pool: "orbit-tasks",
  operator_owned: [] as string[],
};
function fixture() {
  const node = {
    id: 9,
    user: "nckrtl",
    status: "active",
    settings: {
      task_vms: { project: "orbit-tasks", pool: "orbit-tasks", max_vms: 6 },
    },
  };
  const vms = new Map<
    string,
    {
      name: string;
      status: string;
      type: string;
      created_at: string;
      expanded_devices: { root: { type: string; path: string; pool: string } };
    }
  >();
  const add = (name: string, age = 3 * 60 * 60 * 1000, status = "Running") =>
    vms.set(name, {
      name,
      status,
      type: "virtual-machine",
      created_at: new Date(now - age).toISOString(),
      expanded_devices: { root: { type: "disk", path: "/", pool: host.pool } },
    });
  add("tvm-image-7");
  add("tvm-image-8-attempt-2-smoke");
  add("tvm-image-9", 60 * 60 * 1000);
  const ops: Record<string, unknown[]> = {
    queued: [],
    running: [{
      id: 88,
      kind: "task_vm.image_build",
      status: "running",
      cancel_requested: true,
      input: {
        build_id: 8,
        host_node_id: 9,
        project: host.project,
        name: "tvm-image-8-attempt-2",
      },
    }],
    waiting: [],
  };
  let unavailable = false;
  let race = false;
  let lost = false;
  let refuseStop = false;
  let residual = false;
  let changedVm = false;
  const calls: string[][] = [];
  const apiCalls: string[] = [];
  const reports: ImageBuilderDeletion[] = [];
  const http: Http = async (_method, path) => {
    await Promise.resolve();
    apiCalls.push(path);
    if (unavailable) {
      return { status: 503, body: { error: "private upstream diagnostic" } };
    }
    if (path === "/api/v1/nodes") {
      return {
        status: 200,
        body: {
          data: [node, { ...node, id: 13, user: "orbit" }, {
            ...node,
            id: 17,
            settings: null,
          }],
        },
      };
    }
    if (path === "/api/v1/nodes/9") {
      return { status: 200, body: { data: node } };
    }
    const status = new URL("https://gateway.orbit" + path).searchParams.get(
      "status",
    )!;
    const data = [...ops[status]];
    if (race && status === "running" && apiCalls.length > 4) {
      data.push({
        id: 77,
        kind: "task_vm.image_build",
        status: "running",
        input: {
          build_id: 7,
          host_node_id: 9,
          project: host.project,
          name: "tvm-image-7",
        },
      });
    }
    return { status: 200, body: { data } };
  };
  const invoke: SystemTransport = async (cmd) => {
    await Promise.resolve();
    assertEquals(cmd.command, "/usr/bin/sudo");
    assertEquals(cmd.args.slice(0, 5), [
      "-n",
      "--",
      "/usr/bin/incus",
      "--project",
      host.project,
    ]);
    const args = [...cmd.args.slice(5)];
    calls.push(args);
    const name = args.at(-1)!;
    if (args[0] === "list") {
      const rows = args.includes("--")
        ? [...vms.values()].filter((v) => v.name === name)
        : [...vms.values()];
      return {
        success: true,
        stdout: JSON.stringify(
          changedVm && args.includes("--")
            ? rows.map((v) => ({
              ...v,
              created_at: new Date(now - 4 * 60 * 60 * 1000).toISOString(),
            }))
            : rows,
        ),
      };
    }
    if (args[0] === "storage") {
      return {
        success: true,
        stdout: JSON.stringify(
          residual
            ? [{ type: "virtual-machine", name: "tvm-image-7/snapshot0" }]
            : [],
        ),
      };
    }
    if (args[0] === "stop") {
      if (refuseStop) {
        return { success: false, stdout: "private upstream diagnostic" };
      }
      vms.get(name)!.status = "Stopped";
    }
    if (args[0] === "delete") vms.delete(name);
    if (lost) throw new Error("private upstream diagnostic");
    return { success: true, stdout: "" };
  };
  return {
    node,
    vms,
    add,
    ops,
    calls,
    apiCalls,
    reports,
    http,
    invoke,
    api: new ImageReaperApi(http),
    record: (r: ImageBuilderDeletion) => {
      reports.push(r);
      return Promise.resolve();
    },
    unavailable() {
      unavailable = true;
    },
    race() {
      race = true;
    },
    lost() {
      lost = true;
    },
    refuseStop() {
      refuseStop = true;
    },
    residual() {
      residual = true;
    },
    changedVm() {
      changedVm = true;
    },
  };
}

Deno.test("discovery uses only recorded active image hosts and strips unrelated settings", async () => {
  const f = fixture();
  assertEquals(await f.api.hosts(), [host, {
    ...host,
    node_id: 13,
    user: "orbit",
  }]);
  assertEquals(await f.api.host(9), host);
});
Deno.test("only old unreferenced image VMs are deleted and each has a storage-absence report", async () => {
  const f = fixture();
  f.add("tvm-image-7-smoke", 3 * 60 * 60 * 1000, "Stopped");
  f.add("tvm-175");
  f.add("tvm-image-10", 2 * 60 * 60 * 1000);
  f.add("tvm-image-11-builder", 3 * 60 * 60 * 1000, "Stopped");
  const result = await reapImageBuilders(
    host,
    f.api,
    f.record,
    f.invoke,
    undefined,
    () => now,
  );
  assertEquals(result.deleted, [
    "tvm-image-7",
    "tvm-image-7-smoke",
    "tvm-image-11-builder",
  ]);
  assertEquals(result.protected, ["tvm-image-8-attempt-2-smoke"]);
  assertEquals(result.failed, []);
  assertEquals(f.vms.has("tvm-image-9"), true);
  assertEquals(f.vms.has("tvm-image-10"), true);
  assertEquals(f.vms.has("tvm-175"), true);
  assertEquals(
    f.reports.map((r) => [r.name, r.storage_removed]),
    result.deleted.map((n) => [n, true]),
  );
});
for (const state of ["queued", "running", "waiting"] as const) {
  Deno.test(`${state} build protects all its attempt and smoke names even with cancellation requested`, async () => {
    const f = fixture();
    f.ops[state] = [{
      id: 77,
      kind: "task_vm.image_build",
      status: state,
      cancel_requested: true,
      input: {
        build_id: 7,
        host_node_id: 9,
        project: host.project,
        name: "tvm-image-7",
      },
    }];
    f.add("tvm-image-7-attempt-3-smoke");
    const result = await reapImageBuilders(
      host,
      f.api,
      f.record,
      f.invoke,
      undefined,
      () => now,
    );
    assertEquals(result.deleted.includes("tvm-image-7"), false);
    assertEquals(
      result.protected.includes("tvm-image-7-attempt-3-smoke"),
      true,
    );
    assertEquals(
      f.calls.some((c) =>
        ["stop", "delete"].includes(c[0]) && c.at(-1)!.startsWith("tvm-image-7")
      ),
      false,
    );
  });
}
Deno.test("a newly active build is checked again immediately before mutation", async () => {
  const f = fixture();
  f.race();
  const result = await reapImageBuilders(
    host,
    f.api,
    f.record,
    f.invoke,
    undefined,
    () => now,
  );
  assertEquals(result.failed, ["tvm-image-7"]);
  assertEquals(f.calls.some((c) => ["stop", "delete"].includes(c[0])), false);
});
Deno.test("a full 50-row list and unavailable operation API cannot authorize any effect", async () => {
  for (const saturated of [true, false]) {
    const f = fixture();
    if (saturated) f.ops.running = Array(50).fill(f.ops.running[0]);
    else f.unavailable();
    await assertRejects(() =>
      reapImageBuilders(host, f.api, f.record, f.invoke, undefined, () => now)
    );
    assertEquals(f.calls, []);
  }
});
Deno.test("foreign root pool, container, invalid timestamp and ordinary guests are never candidates", async () => {
  const f = fixture();
  f.vms.get("tvm-image-7")!.expanded_devices.root.pool = "foreign-pool";
  f.add("tvm-image-20");
  f.vms.get("tvm-image-20")!.type = "container";
  f.add("tvm-image-21");
  f.vms.get("tvm-image-21")!.created_at = "not-a-time";
  f.add("builder");
  f.add("tvm-175");
  assertEquals(
    (await reapImageBuilders(
      host,
      f.api,
      f.record,
      f.invoke,
      undefined,
      () => now,
    )).deleted,
    [],
  );
  assertEquals(f.calls.some((c) => ["stop", "delete"].includes(c[0])), false);
});
Deno.test("changed VM creation identity refuses a reused name before stop", async () => {
  const f = fixture();
  f.changedVm();
  assertEquals(
    (await reapImageBuilders(
      host,
      f.api,
      f.record,
      f.invoke,
      undefined,
      () => now,
    )).failed,
    ["tvm-image-7"],
  );
  assertEquals(f.calls.some((c) => c[0] === "stop"), false);
});
Deno.test("explicit operator-owned names and project reservations are preserved", async () => {
  for (const reservation of ["orbit-tasks/tvm-image-7", "orbit-tasks/*"]) {
    const f = fixture();
    const settings = { ...host, operator_owned: [reservation] };
    assertEquals(
      (await reapImageBuilders(
        settings,
        f.api,
        f.record,
        f.invoke,
        undefined,
        () => now,
      )).deleted,
      [],
    );
    assertEquals(f.calls.some((c) => ["stop", "delete"].includes(c[0])), false);
  }
});
Deno.test("lost stop and delete replies reconcile absence without repeating either mutation", async () => {
  const f = fixture();
  f.lost();
  assertEquals(
    (await reapImageBuilders(
      host,
      f.api,
      f.record,
      f.invoke,
      undefined,
      () => now,
    )).deleted,
    ["tvm-image-7"],
  );
  assertEquals(f.calls.filter((c) => c[0] === "stop").length, 1);
  assertEquals(f.calls.filter((c) => c[0] === "delete").length, 1);
});
Deno.test("a refused stop never deletes and leftover root snapshots never report a completed deletion", async () => {
  for (const residual of [true, false]) {
    const f = fixture();
    if (residual) f.residual();
    else f.refuseStop();
    const result = await reapImageBuilders(
      host,
      f.api,
      f.record,
      f.invoke,
      undefined,
      () => now,
    );
    assertEquals(result.failed, ["tvm-image-7"]);
    assertEquals(f.reports, []);
    if (!residual) assertEquals(f.calls.some((c) => c[0] === "delete"), false);
  }
});
Deno.test("cancellation before the sweep prevents even inventory commands", async () => {
  const f = fixture();
  await assertRejects(() =>
    reapImageBuilders(
      host,
      f.api,
      f.record,
      f.invoke,
      AbortSignal.abort(),
      () => now,
    )
  );
  assertEquals(f.calls, []);
});
Deno.test("native method enforces exact stable host instance and verifies host identity before Incus", async () => {
  const f = fixture();
  const resources: unknown[] = [];
  const behavior = imageReaper(
    () => f.http,
    f.invoke,
    () => Promise.reject(new Error("foreign identity")),
  );
  const ctx = {
    definition: { name: "task-vm-image-reaper-node-9" },
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/unused" },
    writeResource: (_spec: string, _name: string, value: unknown) => {
      resources.push(value);
      return Promise.resolve({ name: "report" });
    },
  };
  const args = { node_id: 9, run_id: "11111111-1111-4111-8111-111111111111" };
  await assertRejects(() =>
    behavior.methods.sweep.execute(args, {
      ...ctx,
      definition: { name: "wrong-host" },
    })
  );
  assertEquals(f.apiCalls, []);
  await assertRejects(() => behavior.methods.sweep.execute(args, ctx));
  assertEquals(f.calls, []);
  assertEquals(resources, []);
});
Deno.test("reaper stays unscheduled with exact discovery/host placement", async () => {
  const workflow = parse(
    await Deno.readTextFile(
      new URL(
        "../../../../workflows/workflow-task-vm-image-reaper.yaml",
        import.meta.url,
      ),
    ),
  ) as {
    concurrency: number;
    trigger?: unknown;
    jobs: {
      steps: {
        target: string;
        labels: { node: string; pool?: string };
        task: { methodName: string };
        writes?: boolean;
      }[];
    }[];
  };
  assertEquals(workflow.concurrency, 1);
  // Unscheduled until its host steps reach hosts through @swamp/ssh from 209 (3f66b05494).
  assertEquals(workflow.trigger, undefined);
  assertEquals(workflow.jobs[0].steps[0].target, "swamp209-migration-a");
  assertEquals(workflow.jobs[0].steps[0].labels, {
    node: "209",
    pool: "migration",
  });
  assertEquals(
    workflow.jobs[1].steps[0].target,
    "orbit-node-${{ self.host.node_id }}",
  );
  assertEquals(
    workflow.jobs[1].steps[0].labels.node,
    "${{ string(self.host.node_id) }}",
  );
  assertEquals(workflow.jobs[1].steps[0].writes, true);
});
