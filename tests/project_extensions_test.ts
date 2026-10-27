import { projectConfig } from "../extensions/behaviors/_lib/project_extension.ts";
import { inputIdentity } from "../extensions/behaviors/_lib/gateway_stage.ts";
import {
  assert,
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior } from "../extensions/behaviors/projects/nckrtl-com/mod.ts";
import { projectConfigurations } from "../extensions/behaviors/_lib/project_config.ts";
import {
  cleanedCancelledGroup,
  ensureProjectGroup,
  findingReceipts,
  scheduleKey,
  type ScheduleReceipt,
  scheduleReceipt,
} from "../extensions/behaviors/_lib/project_schedule.ts";
import { maintenanceInputs } from "../extensions/behaviors/_lib/swamp_maintenance.ts";
import { type DependencyProject } from "../extensions/behaviors/_lib/dependency_projects.ts";
import { type Http } from "../extensions/behaviors/_lib/gateway_stage.ts";
const original: DependencyProject = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/nckrtl-com.json", import.meta.url),
  ),
);
const reports: unknown[] = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/detection.json", import.meta.url),
  ),
);
const registered = [{ projectId: 20, type: "@nckrtl/project-nckrtl-com" }];

Deno.test("project extension returns the old config exactly and produces identical worker inputs", async () => {
  const { context, getWrittenResources } = createModelTestContext();
  await behavior.methods.configuration.execute({}, context);
  const config = behavior.resources.configuration.schema.parse(
    getWrittenResources()[0].data,
  );
  assertEquals(config, original);
  for (const mode of ["routine", "detect", "summary", "package"]) {
    const args = {
      workItem: "tg-7",
      stage: "script" as const,
      key: "tg-7.script.c1",
      ...(mode === "package"
        ? {
          packageTarget: {
            workItem: "tg-7",
            manager: "composer" as const,
            package: "fixture/framework",
            version: "2.1.0",
            purpose: "major" as const,
            advisories: [],
            advisoryUrls: [],
          },
        }
        : {}),
    };
    assertEquals(
      maintenanceInputs(config, args, 7, { mode, cwd: "/vm/repo" }),
      maintenanceInputs(original, args, 7, { mode, cwd: "/vm/repo" }),
    );
  }
});

Deno.test("config refresh refuses stale, missing, wrong-project and duplicate extension records", async () => {
  const calls: unknown[] = [];
  let ok = true;
  let config: unknown = original;
  const ctx = {
    runModel: (args: unknown) => {
      calls.push(args);
      return Promise.resolve({ ok });
    },
    queryData: () => Promise.resolve(config ? [config] : []),
  };
  assertEquals(await projectConfigurations([], registered, ctx, 20), [
    original,
  ]);
  assertEquals(calls, [{
    modelType: registered[0].type,
    name: "project-nckrtl-com",
    method: "configuration",
    arguments: {},
  }]);
  calls.length = 0;
  assertEquals(await projectConfigurations([], registered, ctx, 99), []);
  assertEquals(calls, []); // Unrelated projects cannot multiply nested calls in a driver tick.
  ok = false;
  await assertRejects(
    () => projectConfigurations([], registered, ctx),
    Error,
    "cannot read project extension",
  );
  ok = true;
  config = { ...original, projectId: 99 };
  await assertRejects(
    () => projectConfigurations([], registered, ctx),
    Error,
    "another Project ID",
  );
  config = null;
  await assertRejects(() => projectConfigurations([], registered, ctx));
  await assertRejects(
    () => projectConfigurations([original], registered, ctx),
    Error,
    "must be unique",
  );
  await assertRejects(() =>
    projectConfigurations([], [...registered, ...registered], ctx)
  );
});

function store() {
  const receipts = new Map<string, ScheduleReceipt>();
  const groups: Record<string, unknown>[] = [];
  let posts = 0;
  let lost = false;
  const http: Http = (method, _path, body) => {
    if (method === "GET") {
      return Promise.resolve({ status: 200, body: { data: groups } });
    }
    posts++;
    const group = { ...body, id: posts, project_id: body!.project_id };
    groups.push(group);
    return Promise.resolve(
      lost ? { status: 0, body: null } : { status: 201, body: { data: group } },
    );
  };
  const deps = {
    http,
    read: (key: string) =>
      Promise.resolve(structuredClone(receipts.get(key) ?? null)),
    save: (receipt: ScheduleReceipt) => {
      receipts.set(receipt.key, structuredClone(receipt));
      return Promise.resolve();
    },
  };
  return {
    receipts,
    groups,
    deps,
    posts: () => posts,
    loseReply: () => {
      lost = true;
    },
  };
}
Deno.test("scheduled creation resumes a saved intent without duplicate groups, including a lost reply", async () => {
  const s = store();
  const desired = scheduleReceipt(
    original,
    "routine",
    new Date("2026-10-27T10:00:00Z"),
  );
  s.loseReply();
  await assertRejects(
    () => ensureProjectGroup(desired, s.deps),
    Error,
    "not confirmed",
  );
  const receipt = await ensureProjectGroup(desired, s.deps);
  assertEquals(receipt.workItem, "tg-1");
  assertEquals(await ensureProjectGroup(desired, s.deps), receipt);
  assertEquals(s.posts(), 1);
  assertEquals(s.groups[0].orchestration, "external");
  assertEquals((s.groups[0].tasks as unknown[]).length, 1);
  assertEquals(s.groups[0].status, "backlog");
  await assertRejects(
    () =>
      ensureProjectGroup({
        ...desired,
        request: { ...desired.request, brief: "different" },
      }, s.deps),
    Error,
    "input conflict",
  );
});
Deno.test("a crash before create and ambiguous lookup require reconciliation without a second POST", async () => {
  const s = store();
  const desired = scheduleReceipt(original, "detect");
  await s.deps.save({ ...desired, attempted: true });
  await assertRejects(
    () => ensureProjectGroup(desired, s.deps),
    Error,
    "uncertain create",
  );
  assertEquals(s.posts(), 0);
  s.groups.push({ ...desired.request, id: 1, project_id: 20 }, {
    ...desired.request,
    id: 2,
    project_id: 20,
  });
  await assertRejects(
    () => ensureProjectGroup(desired, s.deps),
    Error,
    "uncertain create",
  );
  assertEquals(s.posts(), 0);
});
Deno.test("weekly slots cross year boundaries and daily detection stays separate", () => {
  assertEquals(
    scheduleKey(20, "routine", new Date("2027-01-03T23:59:59Z")),
    "20-routine-2026-12-28",
  );
  assertEquals(
    scheduleKey(20, "routine", new Date("2027-01-04T00:00:00Z")),
    "20-routine-2027-01-04",
  );
  assertEquals(
    scheduleKey(20, "detect", new Date("2027-01-03T23:59:59Z")),
    "20-detect-2027-01-03",
  );
});
Deno.test("detection creates one task per major or advisory and never invents a security target", async () => {
  const findings = await findingReceipts(original, reports);
  assertEquals(findings.length, 5);
  assertEquals(findings.filter((f) => f.target).length, 2);
  assert(
    findings.every((f) =>
      f.mode === "package" && (f.request.tasks as unknown[]).length === 1
    ),
  );
  const security = findings.filter((f) => !f.target);
  for (const id of ["PKSA-first", "PKSA-second", "123456"]) {
    assert(
      security.some((f) => String(f.request.brief).includes(`Finding: ${id}`)),
    );
  }
  for (const finding of security) {
    assertStringIncludes(
      String(finding.request.brief),
      "https://github.com/advisories/",
    );
    assertStringIncludes(
      String(finding.request.brief),
      "minimumReleaseAgeDays",
    );
  }
  const duplicated = (structuredClone(reports) as { candidates: unknown[] }[])
    .map((report) => ({
      ...report,
      candidates: [...report.candidates, ...report.candidates],
    }));
  assertEquals(await findingReceipts(original, duplicated), findings);
  await assertRejects(
    () => findingReceipts(original, []),
    Error,
    "missing an ecosystem report",
  );
  const otherPath = structuredClone(reports) as {
    candidates: { path: string }[];
  }[];
  otherPath[0].candidates[0].path = "../escape";
  await assertRejects(
    () => findingReceipts(original, otherPath),
    Error,
    "not configured",
  );
  await assertRejects(() => findingReceipts(original, [{ schema: 1 }]));
});

Deno.test("detection cannot reuse a routine stage key with another maintenance mode", () => {
  const args = {
    workItem: "tg-7",
    stage: "script" as const,
    key: "tg-7.script.c1",
    routine: true,
    dependencyProject: original,
  };
  const identity = inputIdentity(args);
  assertEquals(JSON.parse(identity), {
    routine: true,
    dependencyProject: original,
  });
  assert(inputIdentity({ ...args, detection: true }) !== identity);
});

Deno.test("unchanged config reads reuse the resource and changed config writes one new version", async () => {
  let saved: Record<string, unknown> | null = null;
  let versions = 0;
  const ctx = {
    readResource: () => Promise.resolve(saved),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      versions++;
      saved = value;
      return Promise.resolve({ name });
    },
  };
  await behavior.methods.configuration.execute({}, ctx);
  assertEquals(versions, 1);
  assertEquals(await behavior.methods.configuration.execute({}, ctx), {
    dataHandles: [],
  });
  assertEquals(versions, 1);
  const changed = projectConfig(behavior.type, {
    ...original,
    check: "composer verify",
  });
  await changed.methods.configuration.execute({}, ctx);
  assertEquals(versions, 2);
  assertEquals(saved!.check, "composer verify");
});

Deno.test("cancelled schedule cleanup requires ended children, no assistance and a removed or absent VM", () => {
  const clean = {
    status: "cancelled",
    taskable_id: null,
    vm_power: null,
    tasks: [{ status: "cancelled" }],
  };
  assertEquals(cleanedCancelledGroup(clean), true);
  assertEquals(
    cleanedCancelledGroup({ ...clean, taskable_id: 7, vm_power: "destroyed" }),
    true,
  );
  for (
    const changes of [
      { status: "failed" },
      { taskable_id: 7 },
      { vm_power: "running" },
      { vm_power: "parked" },
      { vm_power: undefined },
      { assistance_requested: true },
      { assistance_reason: "Review cancellation" },
      { tasks: [{ status: "running" }] },
      { tasks: [{ status: "cancelled", assistance_requested: true }] },
    ]
  ) assertEquals(cleanedCancelledGroup({ ...clean, ...changes }), false);
});
