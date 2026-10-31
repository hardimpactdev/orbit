import { serviceDetection } from "../extensions/behaviors/_lib/service_detection.ts";
import { curlHttp } from "../extensions/behaviors/orbit_gateway.ts";
import {
  fixture,
  original,
  reports,
  seedServicePlan,
} from "./_support/project_scheduler.ts";
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
    await assertRejects(
      () =>
        behavior.methods.schedule_project.execute({
          projectId: 20,
          mode: "detect",
        }, f.ctx),
      Error,
      "dependency-scanner",
    );
    assertEquals(
      [...f.data.keys()].some((key) =>
        key.startsWith("orbit-gateway/scheduler-skip-")
      ),
      false,
    );
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

Deno.test("Gateway preflight failure records a failed detect attempt and revokes yesterday's plan", async () => {
  const f = fixture();
  try {
    await seedServicePlan(f, projectFindings(original, "old", reports, plans));
    f.mismatch();
    await serviceDetection(
      {
        ...original,
        worker: { ...original.worker, jsPackageManagers: { ".": "npm" } },
      },
      "new-scan",
      f.ctx,
      curlHttp(f.ctx.globalArgs),
    );
    const key = [...f.data.keys()].find((k) =>
      k.includes("dependencies-v1-project-")
    )!;
    const index = f.data.get(key) as {
      last_attempt: { status: string; run_id: string };
    };
    assertEquals(index.last_attempt.status, "failed");
    assertEquals(index.last_attempt.run_id, "new-scan");
    assertEquals(
      (f.data.get("orbit-gateway/findings-20") as { targets: unknown[] })
        .targets,
      [],
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
Deno.test("successful Services findings admit one immutable whole-project batch exactly once", async () => {
  const f = fixture();
  try {
    await seedServicePlan(
      f,
      projectFindings(original, "service-scan", reports, plans),
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
    const receipts = [...f.data].filter(([k]) =>
      k.startsWith("orbit-gateway/schedule-")
    ).map(([, v]) => ScheduleReceiptSchema.parse(v));
    assertEquals(receipts.length, 1);
    assertEquals(receipts[0].mode, "package");
    assertEquals(receipts[0].targets!.length, 2);
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
  } finally {
    await f.close();
  }
});

Deno.test("pending VM-era detect receipts and findings cannot allocate another detect VM", async () => {
  const f = fixture();
  try {
    const old = scheduleReceipt(original, "detect");
    f.data.set(`orbit-gateway/schedule-${old.key}`, old);
    f.data.set(
      "orbit-gateway/findings-20",
      projectFindings(original, "old-vm-scan", reports, plans),
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 0);
    assertEquals(f.starts(), 0);
    assertEquals(f.data.get(`orbit-gateway/schedule-${old.key}`), old);
  } finally {
    await f.close();
  }
});

Deno.test("an unattempted VM-era detect intent does not block a fresh Services batch", async () => {
  const f = fixture();
  try {
    const old = scheduleReceipt(original, "detect");
    f.data.set(`orbit-gateway/schedule-${old.key}`, old);
    await seedServicePlan(
      f,
      projectFindings(original, "new-service-scan", reports, plans),
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
    assertEquals(f.data.get(`orbit-gateway/schedule-${old.key}`), old);
  } finally {
    await f.close();
  }
});

Deno.test("completed VM-era detect cannot overwrite an independent Services plan", async () => {
  const f = fixture();
  try {
    const old = {
      ...scheduleReceipt(original, "detect"),
      workItem: "tg-99",
      attempted: true,
      started: true,
    };
    f.data.set(`orbit-gateway/schedule-${old.key}`, old);
    f.groups.set(99, {
      id: 99,
      project_id: 20,
      status: "completed",
      vm_power: "destroyed",
    });
    f.data.set("dependency-detect/state-tg-99", {
      stageId: "completed",
      status: "terminal",
    });
    f.data.set("dependency-detect/artifact-tg-99-script", {
      payload: { detections: reports, plans },
    });
    const plan = await seedServicePlan(
      f,
      projectFindings(original, "new-service-scan", reports, plans),
    );
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.data.get("orbit-gateway/findings-20"), plan);
    assertEquals(f.posts(), 1);
  } finally {
    await f.close();
  }
});

Deno.test("fresh scan supersedes only safe unattempted package intents while retaining their ledger", async () => {
  const f = fixture();
  try {
    const oldFindings = await seedServicePlan(
      f,
      projectFindings(original, "old", reports, plans),
    );
    const old = await batchReceipt(original, oldFindings);
    f.data.set(`orbit-gateway/schedule-${old.key}`, old);
    f.data.set(`orbit-gateway/admission-${old.key}`, {
      key: old.key,
      projectId: 20,
      mode: "package",
      workItem: null,
      released: false,
      admittedAt: new Date().toISOString(),
    });
    await seedServicePlan(f, projectFindings(original, "new", reports, plans));
    await behavior.methods.project_work.execute({}, f.ctx);
    const retained = ScheduleReceiptSchema.parse(
      f.data.get(`orbit-gateway/schedule-${old.key}`),
    );
    assertEquals(retained.completed, true);
    assertEquals(retained.supersededBy, "new");
    assertEquals(retained.request, old.request);
    assertEquals(retained.targets, old.targets);
    assertEquals(
      (f.data.get(`orbit-gateway/admission-${old.key}`) as {
        released: boolean;
      }).released,
      true,
    );
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
  } finally {
    await f.close();
  }
});

for (const change of ["head", "policy", "running", "partial", "failed"]) {
  Deno.test(`Services batch admission refuses ${change} changes before group creation`, async () => {
    const f = fixture();
    try {
      const findings = await seedServicePlan(
        f,
        projectFindings(original, "scan", reports, plans),
      );
      if (change === "policy") {
        f.data.set("orbit-gateway/findings-20", {
          ...findings,
          policyIdentity: "wrong",
        });
      } else if (change === "head") {
        const run = f.ctx.invokeModel;
        f.ctx.invokeModel = async (args) => {
          const result = await run(args);
          if (args.method === "remote_ref") {
            f.data.set(`${args.name}/remote-ref-refs-heads-main`, {
              sha: "b".repeat(40),
              ref: "refs/heads/main",
            });
          }
          return result;
        };
      } else {
        const key = [...f.data.keys()].find((key) =>
          key.includes("dependencies-v1-project-")
        )!;
        const index = f.data.get(key) as {
          last_attempt: Record<string, unknown>;
        };
        f.data.set(key, {
          ...index,
          last_attempt: {
            ...index.last_attempt,
            status: change,
            finished_at: change === "running"
              ? null
              : index.last_attempt.finished_at,
            error_code: change === "running" ? null : "source_refresh_failed",
          },
        });
      }
      await behavior.methods.project_work.execute({}, f.ctx);
      assertEquals(f.posts(), 0);
      assertEquals(f.starts(), 0);
    } finally {
      await f.close();
    }
  });
}

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
    const findings = await seedServicePlan(
        f,
        projectFindings(original, "scan", reports, plans),
      ),
      queued = await batchReceipt(original, findings);
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

Deno.test("parked and failed routines do not block a VM-free detection attempt", async () => {
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
      await serviceDetection(
        {
          ...original,
          worker: { ...original.worker, jsPackageManagers: { ".": "npm" } },
        },
        "separate-scan",
        { ...f.ctx, signal: AbortSignal.abort() },
        curlHttp(f.ctx.globalArgs),
      );
      const index = [...f.data].find(([key]) =>
        key.includes("dependencies-v1-project-")
      );
      assertEquals(Boolean(index), true);
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
    await serviceDetection(
      {
        ...original,
        worker: { ...original.worker, jsPackageManagers: { ".": "npm" } },
      },
      "separate-scan",
      { ...f.ctx, signal: AbortSignal.abort() },
      curlHttp(f.ctx.globalArgs),
    );
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
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
    const runModel = f.ctx.invokeModel;
    let cancelledStatusCalls = 0;
    f.ctx.invokeModel = (args) => {
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
