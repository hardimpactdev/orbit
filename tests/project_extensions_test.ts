import {
  ConfigurationV3Schema,
  readProjectConfiguration,
} from "../extensions/behaviors/_lib/project_configuration.ts";
import { projectConfig } from "../extensions/behaviors/_lib/project_extension.ts";
import { inputIdentity } from "../extensions/behaviors/_lib/gateway_stage.ts";
import {
  assert,
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { behavior } from "../extensions/behaviors/projects/nckrtl-com/mod.ts";
import { projectConfigurations } from "../extensions/behaviors/_lib/project_config.ts";
import {
  batchReceipt,
  cleanedCancelledGroup,
  ensureProjectGroup,
  projectFindings,
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
const reports = JSON.parse(
  await Deno.readTextFile(
    new URL("./fixtures/project-extensions/detection.json", import.meta.url),
  ),
);
const registered = [{ projectId: 20, type: "@nckrtl/project-nckrtl-com" }];

Deno.test("project extension returns config without a repository script and produces identical worker inputs", async () => {
  const { context, getWrittenResources } = createModelTestContext();
  await behavior.methods.configuration.execute({}, context);
  const config = readProjectConfiguration(getWrittenResources()[0].data);
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
            targets: [{
              path: ".",
              manager: "composer" as const,
              package: "fixture/framework",
              version: "2.1.0",
              purpose: "major" as const,
              advisories: [],
              advisoryUrls: [],
            }],
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
  let config: unknown = { ...original, schema: 3 };
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
  config = { ...original, schema: 3, projectId: 99 };
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
Deno.test("project findings retain reports and blocked reasons and create one deterministic whole batch receipt", async () => {
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
    blocked: [],
  }));
  const findings = await projectFindings(original, "source", reports, plans);
  const receipt = await batchReceipt(original, findings);
  assertEquals(receipt.targets?.length, 2);
  assertEquals((receipt.request.tasks as unknown[]).length, 1);
  assertEquals(await batchReceipt(original, findings), receipt);
  assertThrows(
    () => projectFindings(original, "source", [], plans),
    Error,
    "missing an ecosystem",
  );
  assertThrows(
    () =>
      projectFindings(original, "source", reports, [{
        schema: 1,
        targets: [{ ...plans[0].targets[0], path: "outside" }],
        blocked: [],
      }, plans[1]]),
    Error,
    "not configured",
  );
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
    schema: 2,
    routine: true,
    dependencyProject: Object.fromEntries(
      Object.entries(original).filter(([key]) =>
        !["exceptions", "dependencyAutoMerge"].includes(key)
      ),
    ),
  });
  assert(inputIdentity({ ...args, detection: true }) !== identity);
});

Deno.test("unchanged config reads reuse the resource and changed config writes one new version", async () => {
  let saved: Record<string, unknown> | null = null;
  let versions = 0;
  const ctx = {
    readResource: (name: string) =>
      Promise.resolve(name === "configuration" ? saved : null),
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

Deno.test("pre-retirement configuration is rewritten as v3 before the Gateway reads it", async () => {
  let saved = JSON.parse(
    await Deno.readTextFile(
      new URL(
        "./fixtures/project-extensions/configuration-v1.json",
        import.meta.url,
      ),
    ),
  );
  let writes = 0;
  const ctx = {
    readResource: (name: string) =>
      Promise.resolve(name === "configuration" ? structuredClone(saved) : null),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      writes++;
      saved = structuredClone(value);
      return Promise.resolve({ name });
    },
  };
  const gateway = {
    runModel: async () => {
      await behavior.methods.configuration.execute({}, ctx);
      return { ok: true };
    },
    queryData: () => Promise.resolve([saved]),
  };
  assertEquals(await projectConfigurations([], registered, gateway, 20), [
    original,
  ]);
  assertEquals(saved.schema, 3);
  assertEquals("script" in saved, false);
  assertEquals(writes, 1);
  assertEquals(await projectConfigurations([], registered, gateway, 20), [
    original,
  ]);
  assertEquals(writes, 1);
});

Deno.test("configuration migration rejects unknown keys and versions instead of silently dropping them", () => {
  const legacy = JSON.parse(
    Deno.readTextFileSync(
      new URL(
        "./fixtures/project-extensions/configuration-v1.json",
        import.meta.url,
      ),
    ),
  );
  const { jsPackageManagers: _managers, ...legacyWorker } = original.worker;
  assertEquals(readProjectConfiguration(legacy), {
    ...original,
    worker: legacyWorker,
  });
  assertEquals(readProjectConfiguration({ ...original, schema: 3 }), original);
  for (
    const invalid of [
      { ...legacy, extra: true },
      { ...legacy, schema: 3 },
      { ...original, schema: 4 },
      { ...legacy, script: "../outside" },
      { ...legacy, worker: undefined },
    ]
  ) {
    assertEquals(ConfigurationV3Schema.safeParse(invalid).success, false);
    assertThrows(() => readProjectConfiguration(invalid));
  }
});

Deno.test("project exception receipts survive config refresh and duplicate delivery without resetting dates", async () => {
  const config = {
    ...original,
    exceptions: {
      schema: 1 as const,
      exceptions: [{
        path: ".",
        manager: "composer" as const,
        package: "seed/pkg",
        recorded_on: "2026-10-26",
        days: 7,
      }],
    },
  };
  const extension = projectConfig("@nckrtl/project-fixture", config);
  const resources = new Map<string, Record<string, unknown>>();
  let writes = 0;
  const context = {
    readResource: (name: string) =>
      Promise.resolve(structuredClone(resources.get(name) ?? null)),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      resources.set(name, structuredClone(value));
      writes++;
      return Promise.resolve({ name });
    },
  };
  const receipt = {
    schema: 1 as const,
    exceptions: [{
      path: ".",
      manager: "js" as const,
      package: "fresh",
      recorded_on: "2026-10-27",
      days: 7,
    }],
  };
  await extension.methods.record_exceptions.execute(receipt, context);
  await extension.methods.configuration.execute({}, context);
  const saved = readProjectConfiguration(resources.get("configuration"));
  assertEquals(saved.exceptions.exceptions, [
    ...config.exceptions.exceptions,
    ...receipt.exceptions,
  ]);
  const count = writes;
  await extension.methods.record_exceptions.execute({
    ...receipt,
    exceptions: [{
      ...receipt.exceptions[0],
      recorded_on: "2026-11-01",
      days: 14,
    }],
  }, context);
  await extension.methods.configuration.execute({}, context);
  assertEquals(writes, count);
  assertEquals(readProjectConfiguration(resources.get("configuration")), saved);
  await assertRejects(
    () =>
      extension.methods.record_exceptions.execute({
        ...receipt,
        exceptions: [{ ...receipt.exceptions[0], path: "other" }],
      }, context),
    Error,
    "not configured",
  );
});

Deno.test("schema 2 configuration migrates to schema 3 with the project exception seed", async () => {
  const { script: _script, ...previous } = JSON.parse(
    await Deno.readTextFile(
      new URL(
        "./fixtures/project-extensions/configuration-v1.json",
        import.meta.url,
      ),
    ),
  );
  const resources = new Map<string, Record<string, unknown>>([[
    "configuration",
    { ...previous, schema: 2 },
  ]]);
  const ctx = {
    readResource: (name: string) =>
      Promise.resolve(resources.get(name) ?? null),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      resources.set(name, value);
      return Promise.resolve({ name });
    },
  };
  await behavior.methods.configuration.execute({}, ctx);
  assertEquals(resources.get("configuration")!.schema, 3);
  assertEquals(
    readProjectConfiguration(resources.get("configuration")),
    original,
  );
});
