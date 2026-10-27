import { fixture, original, reports } from "./_support/project_scheduler.ts";
import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "../extensions/behaviors/orbit_gateway.ts";
import {
  batchReceipt,
  projectFindings,
  scheduleReceipt,
  ScheduleReceiptSchema,
} from "../extensions/behaviors/_lib/project_schedule.ts";
Deno.test("scheduler uses extension config and reconciles a lost factory-start reply", async () => {
  const f = fixture();
  try {
    f.loseStart();
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "routine",
    }, f.ctx);
    await behavior.methods.project_work.execute({}, f.ctx);
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "routine",
    }, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
    assertEquals(f.data.get("project-nckrtl-com/configuration"), {
      ...original,
      schema: 3,
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
      routine: ["tg-1"],
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

const plans = reports.map((
  r: {
    candidates: {
      path: string;
      manager: string;
      package: string;
      latest?: string;
      advisories: { id: string; url: string }[];
    }[];
  },
) => ({
  schema: 1,
  targets: r.candidates.filter((c) => c.latest).map((c) => ({
    path: c.path,
    manager: c.manager,
    package: c.package,
    version: c.latest,
    purpose: c.advisories.length ? "security" : "major",
    advisories: c.advisories.map((a) => a.id),
    advisoryUrls: c.advisories.map((a) => a.url),
  })),
  blocked: r.candidates.filter((c) => !c.latest).map((c) => ({
    path: c.path,
    manager: c.manager,
    package: c.package,
    reason: "No verified target",
  })),
}));
Deno.test("completed detection stores findings then admits one immutable whole-project batch exactly once", async () => {
  const f = fixture();
  try {
    await behavior.methods.schedule_project.execute({
      projectId: 20,
      mode: "detect",
    }, f.ctx);
    Object.assign(f.groups.get(1)!, {
      status: "completed",
      vm_power: "destroyed",
      tasks: [{ status: "completed" }],
    });
    f.data.set("dependency-detect/state-tg-1", {
      stageId: "completed",
      status: "terminal",
    });
    f.data.set("dependency-detect/artifact-tg-1-script", {
      payload: { detections: reports, plans },
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 2);
    assertEquals(f.starts(), 2);
    const findings = f.data.get("orbit-gateway/findings-20") as {
      targets: unknown[];
      blocked: unknown[];
    };
    assertEquals(findings.targets.length, 2);
    assertEquals(findings.blocked.length, 1);
    const receipts = [...f.data].filter(([k]) =>
      k.startsWith("orbit-gateway/schedule-")
    ).map(([, v]) => ScheduleReceiptSchema.parse(v));
    assertEquals(receipts.filter((r) => r.mode === "package").length, 1);
    assertEquals(
      receipts.find((r) => r.mode === "package")!.targets!.length,
      2,
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 2);
    assertEquals(f.starts(), 2);
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
    f.groups.set(98, { id: 98, status: "running" });
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
    assertEquals(work.routine.includes("tg-97"), true);
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
      (f.data.get(`orbit-gateway/scheduler-issue-admission-${bad.key}`) as {
        status: string;
      }).status,
      "resolved",
    );
  } finally {
    await f.close();
  }
});

Deno.test("a stuck batch create is surfaced without creating per-finding siblings", async () => {
  const f = fixture();
  try {
    const findings = await projectFindings(original, "scan", reports, plans),
      queued = await batchReceipt(original, findings);
    f.data.set("orbit-gateway/findings-20", findings);
    f.data.set(`orbit-gateway/schedule-${queued.key}`, {
      ...queued,
      attempted: true,
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 0);
    const work = f.data.get("orbit-gateway/project-work") as {
      issues: { reason: string }[];
    };
    assertEquals(
      work.issues.some((i) => i.reason.includes("uncertain create")),
      true,
    );
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

Deno.test("cleaned cancellation retires an obsolete factory stage before status and preserves healthy work", async () => {
  const f = fixture();
  try {
    const cancelled = {
      ...scheduleReceipt(original, "detect", new Date("2026-10-27T03:00:00Z")),
      workItem: "tg-1",
      attempted: true,
      started: true,
    };
    const healthy = {
      ...scheduleReceipt(original, "routine", new Date("2026-10-26T02:00:00Z")),
      workItem: "tg-2",
      attempted: true,
      started: true,
    };
    f.data.set(`orbit-gateway/schedule-${cancelled.key}`, cancelled);
    f.data.set(`orbit-gateway/schedule-${healthy.key}`, healthy);
    const obsolete = { workItem: "tg-1", stageId: "handoff", status: "active" };
    f.data.set("dependency-detect/state-tg-1", obsolete);
    f.data.set("dependency-routine/state-tg-2", {
      workItem: "tg-2",
      stageId: "provision",
      status: "active",
    });
    f.groups.set(1, {
      id: 1,
      project_id: 20,
      status: "cancelled",
      taskable_id: null,
      vm_power: "destroyed",
      assistance_requested: false,
      assistance_kind: null,
      assistance_reason: null,
      assistance_question: null,
      tasks: [{ status: "cancelled" }],
    });
    f.groups.set(2, { id: 2, project_id: 20, status: "running" });
    const runModel = f.ctx.runModel;
    let cancelledStatusCalls = 0;
    f.ctx.runModel = (args) => {
      if (
        args.definition === "dependency-detect" && args.method === "status" &&
        args.arguments?.workItem === "tg-1"
      ) {
        cancelledStatusCalls++;
        throw new Error(
          "Current stage 'handoff' no longer exists in the definition",
        );
      }
      return runModel(args);
    };
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(cancelledStatusCalls, 0);
    assertEquals(
      (f.data.get(`orbit-gateway/schedule-${cancelled.key}`) as {
        completed: boolean;
      }).completed,
      true,
    );
    assertEquals(f.data.get("dependency-detect/state-tg-1"), obsolete);
    const work = f.data.get("orbit-gateway/project-work") as {
      routine: string[];
      detect: string[];
      issues: unknown[];
    };
    assertEquals(work.routine, ["tg-2"]);
    assertEquals(work.detect, []);
    assertEquals(work.issues, []);
    assertEquals(f.posts(), 0);
    assertEquals(f.starts(), 0);
  } finally {
    await f.close();
  }
});

Deno.test("cancellation with remaining VM, child, or assistance evidence stays held", async () => {
  for (
    const outstanding of [
      { vm_power: "running", taskable_id: 77 },
      { tasks: [{ status: "running" }] },
      {
        assistance_requested: true,
        assistance_kind: "safety",
        assistance_reason: "Keep this request",
      },
      {
        assistance_requested: false,
        assistance_reason: "Unreconciled direction",
      },
    ]
  ) {
    const f = fixture();
    try {
      const receipt = {
        ...scheduleReceipt(
          original,
          "detect",
          new Date("2026-10-27T03:00:00Z"),
        ),
        workItem: "tg-1",
        attempted: true,
        started: true,
      };
      f.data.set(`orbit-gateway/schedule-${receipt.key}`, receipt);
      f.data.set("dependency-detect/state-tg-1", {
        stageId: "assistance",
        status: "active",
      });
      const group = {
        id: 1,
        project_id: 20,
        status: "cancelled",
        taskable_id: null,
        vm_power: "destroyed",
        tasks: [{ status: "cancelled" }],
        ...outstanding,
      };
      f.groups.set(1, group);
      await behavior.methods.project_work.execute({}, f.ctx);
      assertEquals(
        (f.data.get(`orbit-gateway/schedule-${receipt.key}`) as {
          completed: boolean;
        }).completed,
        false,
      );
      assertEquals(f.groups.get(1), group);
      assertEquals(
        (f.data.get("orbit-gateway/project-work") as { detect: string[] })
          .detect,
        ["tg-1"],
      );
      assertEquals(f.posts(), 0);
      assertEquals(f.starts(), 0);
    } finally {
      await f.close();
    }
  }
});
