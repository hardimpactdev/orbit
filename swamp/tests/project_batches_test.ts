import { seedServicePlan } from "./_support/project_scheduler.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Candidate,
  eligible,
  planBatch,
  type UpdateTarget,
} from "../extensions/behaviors/_lib/maintenance/batch.ts";
import {
  type Admission,
  orderProjects,
  reserveMaintenance,
} from "../extensions/behaviors/_lib/project_admission.ts";
import { behavior } from "../extensions/behaviors/orbit_gateway.ts";
import {
  batchReceipt,
  projectFindings,
} from "../extensions/behaviors/_lib/project_schedule.ts";
import { fixture } from "./_support/project_scheduler.ts";
const old = "2020-01-01T00:00:00Z";
const target = (
  name: string,
  purpose: "major" | "security" = "major",
): UpdateTarget => ({
  path: ".",
  manager: "composer",
  package: `fixture/${name}`,
  version: "2.0.0",
  purpose,
  advisories: purpose === "security" ? ["PKSA-test"] : [],
  advisoryUrls: purpose === "security" ? ["https://example.test/advisory"] : [],
});
const candidate = (name: string, security = false): Candidate => ({
  path: ".",
  manager: "composer",
  package: `fixture/${name}`,
  current: "1.0.0",
  latest: "2.0.0",
  reasons: security ? ["major", "security"] : ["major"],
  advisories: security
    ? [{ id: "PKSA-test", url: "https://example.test/advisory" }]
    : [],
});
function nativeSolver(check: (targets: UpdateTarget[]) => string | null) {
  return (choices: UpdateTarget[][]) => {
    let error = "No solution";
    let found: UpdateTarget[] | null = null;
    const visit = (i: number, selected: UpdateTarget[]) => {
      if (found) return;
      if (i === choices.length) {
        const e = check(selected);
        if (e) error = e;
        else found = selected;
        return;
      }
      for (const t of choices[i]) visit(i + 1, [...selected, t]);
    };
    visit(0, []);
    return Promise.resolve(
      found
        ? {
          targets: (found as UpdateTarget[]).filter((t) =>
            t.version !== "1.0.0"
          ),
        }
        : { targets: [], error },
    );
  };
}
Deno.test("a project plan includes every compatible major and security target once, at exact versions", async () => {
  const cs = [candidate("one"), candidate("two", true), candidate("three")];
  const calls: UpdateTarget[][] = [];
  const p = await planBatch(
    cs,
    { ".": { minimumReleaseAgeDays: 7 } },
    () => Promise.resolve([{ version: "2.0.0", publishedAt: old }]),
    nativeSolver((t) => {
      calls.push(t);
      return null;
    }),
  );
  assertEquals(p.targets.length, 3);
  assertEquals(p.targets[0].purpose, "security");
  assertEquals(p.blocked, []);
  assertEquals(calls.length, 1);
  assertEquals(calls[0].length, 3);
});
Deno.test("too-young, prerelease, missing-date and unsolved targets stay recorded; security cannot bypass policy", async () => {
  const cs = [
    candidate("young", true),
    candidate("unknown"),
    candidate("incompatible"),
    candidate("safe"),
  ];
  const p = await planBatch(
    cs,
    { ".": { minimumReleaseAgeDays: 7 } },
    (c) =>
      Promise.resolve(
        c.package.endsWith("young")
          ? [{ version: "2.0.0", publishedAt: new Date().toISOString() }]
          : c.package.endsWith("unknown")
          ? [{ version: "2.0.0-beta", publishedAt: old }, {
            version: "2.0.0",
            publishedAt: "unknown",
          }]
          : [{ version: "2.0.0", publishedAt: old }],
      ),
    nativeSolver((t) =>
      t.some((t) => t.package.endsWith("incompatible") && t.version !== "1.0.0")
        ? "Project constraints cannot resolve target"
        : null
    ),
  );
  assertEquals(p.targets.map((t) => t.package), ["fixture/safe"]);
  assertEquals(p.blocked.length, 3);
  assert(p.blocked.some((b) => b.reason.includes("release-age")));
  assert(p.blocked.some((b) => b.reason.includes("constraints")));
  assertEquals(
    eligible(
      [{ version: "2.0.0", publishedAt: "2026-10-20T03:37:00Z" }],
      "1.0.0",
      7,
      Date.parse("2026-10-27T03:36:59Z"),
    ),
    [],
  );
});
Deno.test("older eligible targets are tried when a latest stable target conflicts", async () => {
  const p = await planBatch(
    [candidate("one")],
    {},
    () =>
      Promise.resolve([{ version: "3.0.0", publishedAt: old }, {
        version: "2.0.0",
        publishedAt: old,
      }]),
    nativeSolver((ts) =>
      ts[0].version === "3.0.0" ? "unsupported platform" : null
    ),
  );
  assertEquals(p.targets[0].version, "2.0.0");
});
function capacity() {
  const admissions: Admission[] = [], groups: Record<string, unknown>[] = [];
  return {
    admissions,
    groups,
    deps: {
      admissions,
      existing: [],
      http: () => Promise.resolve({ status: 200, body: { data: groups } }),
      save: (a: Admission) => {
        const i = admissions.findIndex((x) => x.key === a.key);
        if (i >= 0) admissions[i] = structuredClone(a);
        else admissions.push(structuredClone(a));
        return Promise.resolve();
      },
      now: () => old,
    },
  };
}
Deno.test("one shared cap counts routine, detect, package, provisioning and parked reservations; retry is idempotent", async () => {
  const f = capacity();
  for (let i = 1; i <= 4; i++) {
    const intent = {
      key: `k${i}`,
      projectId: i,
      mode: (["detect", "routine", "package"] as const)[i % 3],
      workItem: `tg-${i}`,
    };
    assert((await reserveMaintenance(intent, f.deps)).admitted);
    assert((await reserveMaintenance(intent, f.deps)).admitted);
    f.groups.push({
      id: i,
      status: i === 4 ? "waiting_for_review" : "running",
      vm_power: i === 2 ? "parked" : "running",
    });
  }
  assertEquals(f.admissions.length, 4);
  const next = {
    key: "k5",
    projectId: 5,
    mode: "package" as const,
    workItem: null,
  };
  assertEquals((await reserveMaintenance(next, f.deps)).admitted, false);
  Object.assign(f.groups[0], {
    status: "completed",
    vm_power: "destroyed",
    tasks: [{ status: "completed" }],
  });
  assert((await reserveMaintenance(next, f.deps)).admitted);
  assertEquals(f.admissions.filter((a) => !a.released).length, 4);
});
Deno.test("uncertain create reserves a place before a group exists; one active package/project and assistance retain it", async () => {
  const f = capacity();
  const first = {
    key: "batch",
    projectId: 20,
    mode: "package" as const,
    workItem: null,
  };
  assert((await reserveMaintenance(first, f.deps)).admitted);
  assertEquals(
    (await reserveMaintenance({ ...first, key: "next" }, f.deps)).admitted,
    false,
  );
  assertEquals(
    (await reserveMaintenance(
      { ...first, key: "detect", mode: "detect" },
      f.deps,
    )).admitted,
    false,
  );
  f.admissions[0].workItem = "tg-1";
  f.groups.push({
    id: 1,
    status: "cancelled",
    vm_power: "destroyed",
    tasks: [{ status: "cancelled" }],
    assistance_requested: true,
  });
  assertEquals(
    (await reserveMaintenance({ ...first, key: "next" }, f.deps)).admitted,
    false,
  );
  f.groups[0].assistance_requested = false;
  assert(
    (await reserveMaintenance({ ...first, key: "next" }, f.deps)).admitted,
  );
});
Deno.test("pre-existing eight scans above the limit remain intact and block admission", async () => {
  const f = capacity();
  const existing = Array.from(
    { length: 8 },
    (_, i) => ({
      key: `scan-${i}`,
      projectId: i + 1,
      mode: "detect" as const,
      workItem: `tg-${i + 1}`,
    }),
  );
  assertEquals(
    (await reserveMaintenance({
      key: "new",
      projectId: 50,
      mode: "package",
      workItem: null,
    }, { ...f.deps, existing })).admitted,
    false,
  );
  assertEquals(f.admissions, []);
  assertEquals(f.groups, []);
});
Deno.test("security projects go first, then least recently admitted project; fair order survives restart", () => {
  const history: Admission[] = [{
    key: "prior",
    projectId: 2,
    mode: "package",
    workItem: "tg-2",
    released: true,
    admittedAt: old,
  }];
  assertEquals(
    orderProjects([
      { projectId: 2, security: true },
      { projectId: 3, security: false },
      { projectId: 4, security: true },
      { projectId: 1, security: false },
    ], history).map((x) => x.projectId),
    [4, 2, 1, 3],
  );
});
Deno.test("findings remain data at the fleet cap; after cleanup one group contains the full batch", async () => {
  const f = fixture();
  try {
    const project =
      (await import("./_support/dependency_projects.ts")).WEBSITE_PROJECT;
    const reports = [{ schema: 1, candidates: [] }, {
      schema: 1,
      candidates: [],
    }];
    const plans = [{
      schema: 1,
      targets: [target("one"), target("two", "security")],
      blocked: [],
    }, {
      schema: 1,
      targets: [],
      blocked: [{
        path: ".",
        manager: "js",
        package: "unknown",
        reason: "No valid fixed target",
      }],
    }];
    const findings = await seedServicePlan(
      f,
      projectFindings(project, "source", reports, plans),
    );
    f.data.set("orbit-gateway/findings-20", findings);
    for (let i = 1; i <= 4; i++) {
      f.data.set(`orbit-gateway/admission-held${i}`, {
        key: `held${i}`,
        projectId: i,
        mode: i % 2 ? "routine" : "detect",
        workItem: `tg-${100 + i}`,
        released: false,
        admittedAt: old,
      });
      f.groups.set(100 + i, {
        id: 100 + i,
        status: "running",
        vm_power: "running",
      });
    }
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 0);
    assertEquals(f.starts(), 0);
    Object.assign(f.groups.get(101)!, {
      status: "completed",
      vm_power: "destroyed",
      tasks: [{ status: "completed" }],
    });
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
    const receipt = await batchReceipt(project, findings);
    const stored = f.data.get(`orbit-gateway/schedule-${receipt.key}`) as {
      targets: unknown[];
    };
    assertEquals(stored.targets.length, 2);
    await behavior.methods.project_work.execute({}, f.ctx);
    assertEquals(f.posts(), 1);
    assertEquals(f.starts(), 1);
  } finally {
    await f.close();
  }
});
Deno.test("failed capacity evidence refuses admission without persisting a reservation", async () => {
  const f = capacity();
  await assertRejects(
    () =>
      reserveMaintenance({
        key: "new",
        projectId: 20,
        mode: "package",
        workItem: null,
      }, {
        ...f.deps,
        http: () => Promise.resolve({ status: 503, body: null }),
      }),
    Error,
    "capacity evidence",
  );
  assertEquals(f.admissions, []);
});
Deno.test("joint solver can choose an older A for B and retain a coupled pair beside an unsatisfiable finding", async () => {
  const cs = [candidate("a"), candidate("b"), candidate("bad")];
  const p = await planBatch(
    cs,
    {},
    (c) =>
      Promise.resolve(
        (c.package.endsWith("a") ? ["3.0.0", "2.0.0"] : ["2.0.0"]).map(
          (version) => ({ version, publishedAt: old }),
        ),
      ),
    nativeSolver((ts) => {
      const version = (name: string) =>
        ts.find((t) => t.package === `fixture/${name}`)?.version ?? "1.0.0";
      if (version("bad") !== "1.0.0") return "Bad target cannot resolve";
      if (
        (version("a") === "2.0.0") !== (version("b") === "2.0.0")
      ) return "A2 and B2 must upgrade together";
      if (
        version("a") === "3.0.0" && version("b") === "2.0.0"
      ) return "B2 requires A2";
      return null;
    }),
  );
  assertEquals(p.targets.map((t) => [t.package, t.version]), [[
    "fixture/a",
    "2.0.0",
  ], ["fixture/b", "2.0.0"]]);
  assertEquals(p.blocked.map((b) => b.package), ["fixture/bad"]);
});
