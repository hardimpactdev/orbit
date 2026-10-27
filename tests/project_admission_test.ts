import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {
  type Admission,
  reconcileAdmissions,
  reserveMaintenance,
} from "../extensions/behaviors/_lib/project_admission.ts";
import { scheduleReceipt } from "../extensions/behaviors/_lib/project_schedule.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";
import { fixture as scheduler } from "./_support/project_scheduler.ts";
import { behavior } from "../extensions/behaviors/orbit_gateway.ts";

function fixture() {
  const receipt = scheduleReceipt(WEBSITE_PROJECT, "detect");
  receipt.attempted = true;
  let admission: Admission = {
    key: receipt.key,
    projectId: receipt.projectId,
    mode: receipt.mode,
    workItem: null,
    admittedAt: "2026-10-27T10:00:00Z",
    released: false,
  };
  const groups: Record<string, unknown>[] = [];
  const issues: (string | null)[] = [];
  let clock = Date.parse("2026-10-27T10:15:00Z");
  let status = 200;
  let saves = 0;
  const calls: string[] = [];
  const deps = () => ({
    admissions: [structuredClone(admission)],
    receipts: [receipt],
    http: (method: string, path: string) => {
      calls.push(`${method} ${path}`);
      return Promise.resolve({
        status,
        body: { data: path.includes("?") ? groups : groups[0] },
      });
    },
    save: (a: Admission) => {
      admission = structuredClone(a);
      saves++;
      return Promise.resolve();
    },
    saveReceipt: (r: typeof receipt) => {
      Object.assign(receipt, r);
      return Promise.resolve();
    },
    assistance: (_a: Admission, reason: string | null) => {
      issues.push(reason);
      return Promise.resolve();
    },
    now: () => clock,
  });
  return {
    receipt,
    groups,
    issues,
    calls,
    deps,
    admission: () => admission,
    saves: () => saves,
    clock: (v: string) => clock = Date.parse(v),
    status: (v: number) => status = v,
  };
}

Deno.test("a failed unbound create expires with assistance and frees both fleet and project; never reposts", async () => {
  const f = fixture();
  f.clock("2026-10-27T10:14:59Z");
  await reconcileAdmissions(f.deps());
  assertEquals(f.calls, []);
  f.clock("2026-10-27T10:15:00Z");
  await reconcileAdmissions(f.deps());
  assert(f.admission().released);
  assert(f.receipt.completed);
  assert(f.issues[0]?.includes("Intent retired; slot released"));
  const admissions = [f.admission()];
  const result = await reserveMaintenance({
    key: "new-project-batch",
    projectId: 20,
    mode: "package",
    workItem: null,
  }, {
    admissions,
    existing: [],
    http: () => Promise.resolve({ status: 200, body: { data: [] } }),
    save: (a) => {
      admissions.push(a);
      return Promise.resolve();
    },
  });
  assert(result.admitted);
  const same = await reserveMaintenance(f.admission(), {
    admissions,
    existing: [],
    http: () => Promise.resolve({ status: 200, body: { data: [] } }),
    save: () => Promise.reject(new Error("must not recreate retired intent")),
  });
  assertEquals(same.admitted, false);
  await reconcileAdmissions(f.deps());
  assertEquals(f.calls, ["GET /api/v1/task-groups?project_id=20"]);
});

Deno.test("a reservation interrupted before saving its create intent expires without an Orbit write", async () => {
  const f = fixture();
  await reconcileAdmissions({ ...f.deps(), receipts: [] });
  assert(f.admission().released);
  assert(f.issues[0]?.includes("never attempted"));
  assertEquals(f.calls, []);
});

Deno.test("an expired lost reply adopts its matching group instead of releasing a live reservation", async () => {
  const f = fixture();
  f.groups.push({
    id: 7,
    project_id: 20,
    brief: f.receipt.request.brief,
    orchestration: "external",
    status: "running",
  });
  await reconcileAdmissions(f.deps());
  assertEquals(f.admission().workItem, "tg-7");
  assertEquals(f.receipt.workItem, "tg-7");
  assertEquals(f.admission().released, false);
  assertEquals(f.receipt.completed, false);
  assert(f.calls.every((c) => c.startsWith("GET ")));
});

Deno.test("a saved group binds its reservation before expiry after a bind crash", async () => {
  const f = fixture();
  f.clock("2026-10-27T10:01:00Z");
  f.receipt.workItem = "tg-7";
  f.groups.push({ id: 7, status: "running" });
  await reconcileAdmissions(f.deps());
  assertEquals(f.admission().workItem, "tg-7");
  assertEquals(f.admission().released, false);
});

for (const ambiguous of [false, true]) {
  Deno.test(`expired create retains its place and reports ${ambiguous ? "ambiguous" : "unreadable"} evidence`, async () => {
    const f = fixture();
    if (ambiguous) {
      for (const id of [7, 8]) {
        f.groups.push({
          id,
          project_id: 20,
          brief: f.receipt.request.brief,
          orchestration: "external",
        });
      }
    } else f.status(503);
    await reconcileAdmissions(f.deps());
    assertEquals(f.admission().released, false);
    assertEquals(f.receipt.completed, false);
    assert(f.issues[0]?.includes(ambiguous ? "ambiguous" : "unreadable"));
    assertEquals(f.saves(), 0);
  });
}

Deno.test("completed closed-unmerged PR retains its reservation with assistance; reopening clears the issue, merge releases", async () => {
  const f = fixture();
  f.receipt.workItem = "tg-7";
  f.groups.push({
    id: 7,
    status: "completed",
    tasks: [{ status: "completed" }],
    vm_power: "destroyed",
    taskable_id: null,
    pr_url: "https://example.test/pull/7",
    watched_pr_state: "closed",
  });
  await reconcileAdmissions(f.deps());
  assertEquals(f.admission().released, false);
  assert(f.issues[0]?.includes("tg-7"));
  assert(f.issues[0]?.includes("https://example.test/pull/7"));
  f.groups[0].watched_pr_state = "open";
  await reconcileAdmissions(f.deps());
  assertEquals(f.issues.at(-1), null);
  assertEquals(f.admission().released, false);
  f.groups[0].watched_pr_state = "merged";
  await reconcileAdmissions(f.deps());
  assert(f.admission().released);
});

Deno.test("the idle minute driver expires failed-create reservations and keeps assistance visible on repeat", async () => {
  const f = scheduler();
  try {
    const receipt = scheduleReceipt(WEBSITE_PROJECT, "detect");
    f.data.set(`orbit-gateway/schedule-${receipt.key}`, receipt);
    f.createStatus(422);
    await behavior.methods.project_work.execute({}, f.ctx);
    const admission = f.data.get(
      `orbit-gateway/admission-${receipt.key}`,
    ) as Admission;
    assertEquals(admission.released, false);
    assertEquals(admission.workItem, null);
    assertEquals(f.createAttempts(), 1);
    f.data.set(`orbit-gateway/admission-${receipt.key}`, {
      ...admission,
      admittedAt: "2020-01-01T00:00:00Z",
    });
    for (let i = 0; i < 2; i++) {
      await behavior.methods.project_work.execute({}, f.ctx);
    }
    assertEquals(f.posts(), 0);
    assertEquals(
      (f.data.get(`orbit-gateway/admission-${receipt.key}`) as Admission)
        .released,
      true,
    );
    assertEquals(
      (f.data.get(`orbit-gateway/schedule-${receipt.key}`) as typeof receipt)
        .completed,
      true,
    );
    const work = f.data.get("orbit-gateway/project-work") as {
      issues: { reason: string }[];
    };
    assertEquals(work.issues.length, 1);
    assert(work.issues[0].reason.includes("slot released"));
    assertEquals(
      f.createAttempts(),
      1,
      "expiry never repeats the failed create",
    );
    const next = scheduleReceipt(
      WEBSITE_PROJECT,
      "detect",
      new Date("2030-01-01T00:00:00Z"),
    );
    f.data.set(`orbit-gateway/schedule-${next.key}`, next);
    f.createStatus(201);
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(
      f.posts(),
      1,
      "a later intent can use the released project and slot",
    );
  } finally {
    await f.close();
  }
});

Deno.test("the idle minute driver reports a closed PR even after its schedule receipt completed", async () => {
  const f = scheduler();
  try {
    f.groups.set(7, {
      id: 7,
      project_id: 20,
      status: "completed",
      tasks: [],
      vm_power: "destroyed",
      taskable_id: null,
      pr_url: "https://example.test/pr/7",
      watched_pr_state: "closed",
    });
    f.data.set("orbit-gateway/admission-old", {
      key: "old",
      projectId: 20,
      mode: "package",
      workItem: "tg-7",
      released: false,
      admittedAt: "2020-01-01T00:00:00Z",
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    const work = f.data.get("orbit-gateway/project-work") as {
      issues: { reason: string }[];
    };
    assertEquals(work.issues.length, 1);
    assert(work.issues[0].reason.includes("closed without merge"));
    assertEquals(f.posts(), 0);
  } finally {
    await f.close();
  }
});

Deno.test("a repeated entry after pre-create expiration retires its recreated intent without holding the project", async () => {
  const f = scheduler();
  try {
    const receipt = scheduleReceipt(WEBSITE_PROJECT, "detect");
    f.data.set(`orbit-gateway/schedule-${receipt.key}`, receipt);
    f.data.set(`orbit-gateway/admission-${receipt.key}`, {
      key: receipt.key,
      projectId: 20,
      mode: "detect",
      workItem: null,
      admittedAt: "2020-01-01T00:00:00Z",
      released: true,
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(
      (f.data.get(`orbit-gateway/schedule-${receipt.key}`) as typeof receipt)
        .completed,
      true,
    );
    assertEquals(f.posts(), 0);
  } finally {
    await f.close();
  }
});
