import { taskInputs, todoError } from "./_support/task_contract.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "../extensions/behaviors/orbit_gateway.ts";
import { behavior as projectBehavior } from "../extensions/behaviors/projects/nckrtl-com/mod.ts";
import {
  findingReceipts,
  scheduleReceipt,
  ScheduleReceiptSchema,
} from "../extensions/behaviors/_lib/project_schedule.ts";
const original = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/nckrtl-com.json", import.meta.url),
  ),
);
const reports = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/detection.json", import.meta.url),
  ),
);

function fixture() {
  const data = new Map<string, unknown>();
  const groups = new Map<number, Record<string, unknown>>();
  let posts = 0;
  let starts = 0;
  let loseStart = false;
  let mismatch = false;
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    async (req) => {
      const url = new URL(req.url);
      const json = (value: unknown, status = 200) =>
        Response.json({ data: value }, { status });
      if (url.pathname === "/api/v1/projects/20") {
        return json({
          id: 20,
          repository_url: original.repository,
          task_check: mismatch ? "different" : original.check,
          default_branch: original.defaultBranch,
        });
      }
      if (
        url.pathname === "/api/v1/task-groups" && req.method === "GET"
      ) return json([...groups.values()]);
      if (url.pathname === "/api/v1/task-groups" && req.method === "POST") {
        const body = await req.json();
        if (!taskInputs.safeParse(body.tasks).success) {
          return Response.json({ error: { code: "validation.failed" } }, {
            status: 422,
          });
        }
        const id = ++posts;
        const group = {
          ...body,
          id,
          taskable_id: null,
          vm_power: null,
          tasks: body.tasks.map((t: unknown) => ({
            ...(t as object),
            id: id * 10,
            status: "todo",
          })),
        };
        groups.set(id, group);
        return json(group, 201);
      }
      const id = Number(url.pathname.split("/").at(-1));
      if (groups.has(id) && req.method === "PATCH") {
        const body = await req.json();
        const group = groups.get(id)!;
        const error = body.status === "todo" ? todoError(group.tasks) : null;
        if (error) {
          return Response.json({ error: { code: error } }, { status: 422 });
        }
        Object.assign(group, body);
      }
      return groups.has(id) ? json(groups.get(id)) : json(null, 404);
    },
  );
  const ctx = {
    globalArgs: behavior.globalArguments.parse({
      // Loopback HTTP uses no TLS, but curl still requires the --cacert path to exist.
      caFile: "/dev/null",
      gatewayUrl: `http://127.0.0.1:${server.addr.port}`,
      projectExtensions: [{
        projectId: 20,
        type: "@nckrtl/project-nckrtl-com",
      }],
    }),
    definition: { name: "orbit-gateway" },
    queryData: (predicate: string, select?: string) => {
      const model = JSON.parse(predicate.match(/modelName == ("[^"]*")/)![1]);
      const name = predicate.match(/name == ("[^"]*")/);
      const prefix = predicate.match(/name.startsWith\(("[^"]*")\)/);
      const values = [...data].filter(([key]) =>
        key.startsWith(`${model}/`) &&
        (name
          ? key === `${model}/${JSON.parse(name[1])}`
          : prefix
          ? key.slice(model.length + 1).startsWith(JSON.parse(prefix[1]))
          : true)
      ).map(([, value]) => structuredClone(value));
      return Promise.resolve(
        select === "attributes.runs"
          ? values.map((v) => (v as { runs: unknown }).runs)
          : values,
      );
    },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      data.set(`orbit-gateway/${name}`, structuredClone(value));
      return Promise.resolve({ name });
    },
    logger: { info() {} },
    vaultService: {
      get: () =>
        Promise.reject(new Error("must not read secrets in scheduler")),
    },
    runModel: async (
      args: {
        definition?: string;
        modelType?: string;
        name?: string;
        method: string;
        arguments?: Record<string, unknown>;
      },
    ) => {
      if (args.modelType === projectBehavior.type) {
        await projectBehavior.methods.configuration.execute({}, {
          readResource: (name) =>
            Promise.resolve(
              data.get(`${args.name}/${name}`) as Record<string, unknown> ??
                null,
            ),
          writeResource: (_spec, name, value) => {
            data.set(`${args.name}/${name}`, value);
            return Promise.resolve({ name });
          },
        });
        return { ok: true };
      }
      if (args.method === "start") {
        starts++;
        data.set(`${args.definition}/state-${args.arguments!.workItem}`, {
          workItem: args.arguments!.workItem,
          stageId: "provision",
          status: "active",
        });
        if (loseStart) {
          loseStart = false;
          return { ok: false, error: { message: "lost start reply" } };
        }
      }
      if (
        args.definition === "dependency-package" && args.method === "status" &&
        !args.arguments?.workItem
      ) {
        data.set("dependency-package/status-_factory", {
          runs: [...data].filter(([key]) =>
            key.startsWith("dependency-package/state-")
          ).map(([, value]) => value),
        });
      }
      return { ok: true };
    },
  };
  return {
    ctx,
    data,
    groups,
    close: () => server.shutdown(),
    posts: () => posts,
    starts: () => starts,
    loseStart: () => {
      loseStart = true;
    },
    mismatch: () => {
      mismatch = true;
    },
  };
}

Deno.test("scheduler uses extension config and reconciles a lost factory-start reply", async () => {
  const f = fixture();
  try {
    f.loseStart();
    await assertRejects(
      () =>
        behavior.methods.schedule_project.execute({
          projectId: 20,
          mode: "routine",
        }, f.ctx),
      Error,
      "lost start reply",
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "routine",
    }, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
    assertEquals(f.data.get("project-nckrtl-com/configuration"), {
      ...original,
      schema: 2,
    });
    const work = f.data.get("orbit-gateway/project-work");
    // The recovered intent enters the driver list on the next minute tick.
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.data.get("orbit-gateway/project-work"), {
      routine: ["tg-1"],
      detect: [],
      package: [],
      nextOffset: 0,
      nextQueueOffset: 0,
      nextPendingOffset: 0,
      issues: [],
    });
    assertEquals(work, {
      routine: [],
      detect: [],
      package: [],
      nextOffset: 0,
      nextQueueOffset: 0,
      nextPendingOffset: 0,
      issues: [],
    });
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "detect",
    }, f.ctx);
    const skip = [...f.data].find(([key]) =>
      key.startsWith("orbit-gateway/scheduler-skip-")
    )![1] as { blocker: string; reason: string };
    assertEquals(skip.blocker.includes("20-routine-"), true);
    assertEquals(skip.reason.includes("skip this detect fire"), true);
    assertEquals(f.posts(), 1);
  } finally {
    await f.close();
  }
});

Deno.test("scheduler refuses a Gateway setting mismatch before group creation", async () => {
  const f = fixture();
  try {
    f.mismatch();
    await assertRejects(
      () =>
        behavior.methods.schedule_project.execute({
          projectId: 20,
          mode: "routine",
        }, f.ctx),
      Error,
      "settings differ",
    );
    assertEquals(f.posts(), 0);
  } finally {
    await f.close();
  }
});

Deno.test("completed detection creates separate package tasks once, admits one major, and preserves security backlog", async () => {
  const f = fixture();
  try {
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "detect",
    }, f.ctx);
    f.data.set("dependency-detect/state-tg-1", {
      stageId: "completed",
      status: "terminal",
    });
    f.data.set("dependency-detect/artifact-tg-1-script", {
      payload: { detections: reports },
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 6); // One scan group, two majors, three separate advisories.
    assertEquals(f.starts(), 2); // Scan and first major; second major waits for completion.
    const receipts = [...f.data].filter(([key]) =>
      key.startsWith("orbit-gateway/schedule-")
    ).map(([, value]) => ScheduleReceiptSchema.parse(value));
    assertEquals(receipts.filter((r) => r.mode === "package").length, 5);
    assertEquals(
      receipts.filter((r) => r.mode === "package" && r.started).length,
      1,
    );
    assertEquals(
      receipts.filter((r) => r.mode === "package" && !r.target && !r.started)
        .length,
      3,
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 6);
    assertEquals(f.starts(), 2);
    const first = receipts.find((r) => r.mode === "package" && r.started)!;
    const group = f.groups.get(Number(first.workItem!.slice(3)))!;
    Object.assign(group, { status: "completed", vm_power: "destroyed" });
    f.data.set(`dependency-package/state-${first.workItem}`, {
      workItem: first.workItem,
      stageId: "completed",
      status: "terminal",
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.starts(), 3);
    // A repeat scan can have newer evidence for the same advisory; reuse its original task.
    const scanKey = [...f.data.keys()].find((key) =>
      key.startsWith("orbit-gateway/schedule-20-detect-")
    )!;
    const scan = ScheduleReceiptSchema.parse(f.data.get(scanKey));
    f.data.set(scanKey, { ...scan, completed: false });
    const changed = structuredClone(reports);
    changed[0].candidates[0].current = "1.9.1";
    f.data.set("dependency-detect/artifact-tg-1-script", {
      payload: { detections: changed },
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 6);
  } finally {
    await f.close();
  }
});

Deno.test("bounded schedule ticks rotate past parked projects", async () => {
  const f = fixture();
  try {
    for (let id = 1; id <= 6; id++) {
      f.groups.set(id, { id, status: "running" });
      f.data.set(`orbit-gateway/schedule-fixture-${id}`, {
        key: `fixture-${id}`,
        projectId: 20,
        mode: "routine",
        request: {},
        attempted: true,
        workItem: `tg-${id}`,
        started: true,
        completed: false,
      });
      f.data.set(`dependency-routine/state-tg-${id}`, {
        stageId: "assistance",
        status: "active",
      });
    }
    await behavior.methods.project_work.execute({}, f.ctx);
    const first = f.data.get("orbit-gateway/project-work") as {
      routine: string[];
    };
    await behavior.methods.project_work.execute({}, f.ctx);
    const second = f.data.get("orbit-gateway/project-work") as {
      routine: string[];
    };
    assertEquals(first.routine.length, 4);
    assertEquals(second.routine.length, 4);
    assertEquals(new Set([...first.routine, ...second.routine]).size, 6);
    assertEquals(f.posts(), 0);
  } finally {
    await f.close();
  }
});

Deno.test("uncertain pending creates and broken active rows are surfaced without stalling other projects", async () => {
  const f = fixture();
  try {
    const bad = {
      ...scheduleReceipt(original, "routine"),
      key: "uncertain-project-99",
      projectId: 99,
      attempted: true,
    };
    const good = {
      ...scheduleReceipt(original, "routine"),
      key: "healthy-project-20",
    };
    f.data.set(`orbit-gateway/schedule-${bad.key}`, bad);
    f.data.set(`orbit-gateway/schedule-${good.key}`, good);
    const broken = {
      ...good,
      key: "broken-active",
      projectId: 98,
      workItem: "tg-98",
      attempted: true,
      started: true,
    };
    f.data.set(`orbit-gateway/schedule-${broken.key}`, broken);
    f.data.set("orbit-gateway/schedule-active-other", {
      ...good,
      key: "active-other",
      projectId: 97,
      workItem: "tg-97",
      attempted: true,
      started: true,
    });
    f.groups.set(97, { id: 97, status: "running" });
    f.data.set("dependency-routine/state-tg-97", {
      stageId: "assistance",
      status: "active",
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    let work = f.data.get("orbit-gateway/project-work") as {
      routine: string[];
      issues: { key: string; reason: string }[];
    };
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
    assertEquals(work.routine, ["tg-97"]);
    assertEquals(
      work.issues.some((i) =>
        i.key === bad.key && i.reason.includes("uncertain create")
      ),
      true,
    );
    assertEquals(
      work.issues.some((i) =>
        i.key === broken.key && i.reason.includes("state is missing")
      ),
      true,
    );
    // Reconciliation can adopt a recovered response; it never resends the create.
    f.groups.set(99, { ...bad.request, id: 99, project_id: 99 });
    await behavior.methods.project_work.execute({}, f.ctx);
    work = f.data.get("orbit-gateway/project-work") as typeof work;
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 2);
    assertEquals(work.routine.includes("tg-1"), true);
    assertEquals(work.issues.some((i) => i.key === bad.key), false);
    assertEquals(
      (f.data.get(`orbit-gateway/scheduler-issue-pending-${bad.key}`) as {
        status: string;
      }).status,
      "resolved",
    );
  } finally {
    await f.close();
  }
});

Deno.test("a stuck detection finding does not block its siblings or another project's driver", async () => {
  const f = fixture();
  try {
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "detect",
    }, f.ctx);
    f.data.set("dependency-detect/state-tg-1", {
      stageId: "completed",
      status: "terminal",
    });
    f.data.set("dependency-detect/artifact-tg-1-script", {
      payload: { detections: reports },
    });
    const findings = await findingReceipts(original, reports);
    const stuck = { ...findings[0], attempted: true };
    f.data.set(`orbit-gateway/schedule-${stuck.key}`, stuck);
    const other = {
      ...scheduleReceipt(original, "routine"),
      key: "other-project",
      projectId: 99,
      workItem: "tg-99",
      attempted: true,
      started: true,
    };
    f.data.set("orbit-gateway/schedule-other-project", other);
    f.groups.set(99, { id: 99, status: "running" });
    f.data.set("dependency-routine/state-tg-99", {
      stageId: "provision",
      status: "active",
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    const work = f.data.get("orbit-gateway/project-work") as {
      routine: string[];
      issues: { key: string; phase: string }[];
    };
    assertEquals(f.posts(), 5); // Scan plus the other four findings.
    assertEquals(work.routine, ["tg-99"]);
    assertEquals(
      work.issues.some((i) => i.key === stuck.key && i.phase === "finding"),
      true,
    );
    const scan = [...f.data].find(([key]) =>
      key.startsWith("orbit-gateway/schedule-20-detect-")
    )![1] as { completed: boolean };
    assertEquals(scan.completed, false);
  } finally {
    await f.close();
  }
});

Deno.test("a bad package admission is recorded without blocking another project's active run", async () => {
  const f = fixture();
  try {
    const queued = (await findingReceipts(original, reports))[0];
    f.data.set(`orbit-gateway/schedule-${queued.key}`, {
      ...queued,
      workItem: "tg-404",
      attempted: true,
    });
    f.data.set("orbit-gateway/schedule-other-active", {
      ...scheduleReceipt(original, "routine"),
      key: "other-active",
      projectId: 99,
      workItem: "tg-99",
      attempted: true,
      started: true,
    });
    f.groups.set(99, { id: 99, status: "running" });
    f.data.set("dependency-routine/state-tg-99", {
      stageId: "provision",
      status: "active",
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    const work = f.data.get("orbit-gateway/project-work") as {
      routine: string[];
      issues: { key: string; phase: string }[];
    };
    assertEquals(work.routine, ["tg-99"]);
    assertEquals(
      work.issues.some((i) => i.key === queued.key && i.phase === "admission"),
      true,
    );
    assertEquals(f.posts(), 0);
  } finally {
    await f.close();
  }
});

Deno.test("parked and failed routines make daily detection a recorded skip without a group", async () => {
  for (
    const state of [{ stageId: "assistance", status: "active" }, {
      stageId: "failed",
      status: "terminal",
    }]
  ) {
    const f = fixture();
    try {
      const blocked = {
        ...scheduleReceipt(
          original,
          "routine",
          new Date("2026-01-05T02:00:00Z"),
        ),
        workItem: "tg-88",
        attempted: true,
        started: true,
      };
      f.data.set(`orbit-gateway/schedule-${blocked.key}`, blocked);
      f.data.set("dependency-routine/state-tg-88", state);
      const result = await behavior.methods.schedule_project.execute({
        projectId: 20,
        mode: "detect",
      }, f.ctx);
      assertEquals(result.dataHandles.length, 1);
      const skip = [...f.data].find(([key]) =>
        key.startsWith("orbit-gateway/scheduler-skip-")
      )![1] as { blocker: string; reason: string };
      assertEquals(skip.blocker, blocked.key);
      assertEquals(skip.reason.includes("unfinished"), true);
      assertEquals(f.posts(), 0);
      assertEquals(
        [...f.data.keys()].filter((key) =>
          key.startsWith("orbit-gateway/schedule-")
        ).length,
        1,
      );
    } finally {
      await f.close();
    }
  }
});

Deno.test("a cancelled backlog group with no VM retires its active factory receipt and unblocks detection", async () => {
  const f = fixture();
  try {
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "routine",
    }, f.ctx);
    Object.assign(f.groups.get(1)!, {
      status: "cancelled",
      taskable_id: null,
      vm_power: null,
      tasks: [{ status: "cancelled" }],
    });
    // Provision failed before an operation existed. The official factory still says active.
    await behavior.methods.project_work.execute({}, f.ctx);
    const routine = [...f.data].find(([key]) =>
      key.startsWith("orbit-gateway/schedule-20-routine-")
    )![1] as { completed: boolean };
    assertEquals(routine.completed, true);
    assertEquals(
      (f.data.get("orbit-gateway/project-work") as { routine: string[] })
        .routine,
      [],
    );
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "detect",
    }, f.ctx);
    assertEquals(f.posts(), 2);
    assertEquals(f.starts(), 2);
    assertEquals(
      [...f.data.keys()].some((key) =>
        key.startsWith("orbit-gateway/scheduler-skip-")
      ),
      false,
    );
  } finally {
    await f.close();
  }
});
