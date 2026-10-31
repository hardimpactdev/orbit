import { recordQuery } from "./fixtures/worker_query.ts";
import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  claimGroupRun,
  clearedFailures,
  ensureGroupRun,
  failedRun,
  type GroupRun,
  type GroupRunControl,
  groupRunKey,
  type NativeGroupRun,
  type RunFailures,
} from "../extensions/behaviors/_lib/group_runs.ts";
import { discoverGroups } from "../extensions/behaviors/orbit_group_runs.ts";

function fixture() {
  let intent: GroupRun | null = null, owner: GroupRun | null = null;
  const runs = new Map<string, NativeGroupRun>();
  let launches = 0, loseReply = false, loseRequest = false;
  const control: GroupRunControl = {
    read: () => Promise.resolve(structuredClone(intent)),
    save: (next) => {
      intent = structuredClone(next);
      return Promise.resolve();
    },
    owner: () => Promise.resolve(structuredClone(owner)),
    settled: () => Promise.resolve(true),
    search: (key) =>
      Promise.resolve([...runs.values()].filter((r) => r.inputs.key === key)),
    get: (id) => Promise.resolve(runs.get(id)!),
    launch: (next) => {
      assertEquals(intent, next, "persist intent before transport");
      launches++;
      const id = `run-${launches}`;
      if (!loseRequest) {
        runs.set(id, { id, status: "running", inputs: { ...next } });
      }
      if (loseReply || loseRequest) throw new Error("connection lost");
      return Promise.resolve(id);
    },
  };
  return {
    control,
    runs,
    saved: () => structuredClone(intent!),
    launches: () => launches,
    loseReply: (value: boolean) => loseReply = value,
    loseRequest: (value: boolean) => loseRequest = value,
    claim: (id: string) => {
      const request = { ...runs.get(id)!.inputs, runId: id } as GroupRun & {
        runId: string;
      };
      const admitted = claimGroupRun(intent, owner, request);
      if (admitted) owner = structuredClone(request);
      return admitted;
    },
  };
}

Deno.test("a restart after a lost launch reply finds the same group generation", async () => {
  const f = fixture();
  f.loseReply(true);
  await assertRejects(() =>
    ensureGroupRun("tg-7", "dependency-detect", f.control)
  );
  assertEquals(f.saved().key, "tg-7.g1");
  assertEquals(f.saved().runId, null);
  assert(f.claim("run-1"));
  f.loseReply(false);
  const resumed = await ensureGroupRun("tg-7", "dependency-detect", f.control);
  assertEquals(resumed.intent.runId, "run-1");
  assertEquals(f.launches(), 1);
});

Deno.test("lost requests retry the same generation and delayed duplicates never become a second owner", async () => {
  const f = fixture();
  f.loseRequest(true);
  await assertRejects(() => ensureGroupRun("tg-7", "orbit-groups", f.control));
  const pending = f.saved();
  f.loseRequest(false);
  await ensureGroupRun("tg-7", "orbit-groups", f.control);
  // The earlier request arrives after its retry. Both carry the same intent.
  f.runs.set("delayed", {
    id: "delayed",
    status: "running",
    inputs: { ...pending },
  });
  assert(f.claim("delayed"));
  assert(!f.claim("run-2"));
  assert(f.claim("delayed"), "owner's checkpoint recovery is idempotent");
  f.runs.get("run-2")!.status = "succeeded";
  const result = await ensureGroupRun("tg-7", "orbit-groups", f.control);
  assertEquals(
    result.intent.runId,
    "delayed",
    "a rejected duplicate's end cannot start the next generation",
  );
  assertEquals(result.intent.generation, 1);
  assertEquals(f.launches(), 2);
  f.runs.get("delayed")!.status = "succeeded";
  await ensureGroupRun("tg-7", "orbit-groups", f.control);
  assertEquals(f.saved().key, groupRunKey("tg-7", 2));
  assert(!f.claim("delayed"), "an old generation cannot reclaim the group");
  assert(f.claim("run-3"));
});

Deno.test("running, waiting and interrupted owners hold their generation until native terminal proof", async () => {
  const f = fixture();
  await ensureGroupRun("tg-7", "orbit-groups", f.control);
  assert(f.claim("run-1"));
  for (const status of ["running", "suspended", "interrupted"]) {
    f.runs.get("run-1")!.status = status;
    const result = await ensureGroupRun("tg-7", "orbit-groups", f.control);
    assertEquals(result.intent.generation, 1);
    assertEquals(result.resume, status === "running" ? null : "run-1");
    assertEquals(f.launches(), 1);
  }
  f.runs.get("run-1")!.inputs.key = "tg-8.g1";
  await assertRejects(
    () => ensureGroupRun("tg-7", "orbit-groups", f.control),
    Error,
    "identity differs",
  );
  assertEquals(f.launches(), 1);
});

Deno.test("discovery rotates four supervisors per pass and isolates an unavailable factory", async () => {
  const workItems = Array.from({ length: 120 }, (_, i) => `tg-${i + 1}`);
  let result: Record<string, unknown> = {};
  const context = {
    definition: { name: "orbit-group-runs" },
    globalArgs: {
      factories: [
        { factory: "broken", preapprovalModel: "broken-driver" },
        { factory: "healthy", preapprovalModel: "healthy-driver" },
      ],
    },
    queryData: recordQuery((predicate: string) => {
      if (predicate.includes('"broken"')) {
        throw new Error("one factory unavailable");
      }
      if (predicate.includes("schedule-")) return Promise.resolve(["tg-120"]);
      if (predicate.includes('name == "groups"')) {
        return Promise.resolve([result]);
      }
      if (predicate.includes('name.startsWith("state-")')) {
        return Promise.resolve(
          workItems.map((workItem) => ({ workItem, status: "active" })),
        );
      }
      return Promise.resolve([]);
    }),
    invokeModel: () => {
      throw new Error("discovery must not materialize status or reports");
    },
    writeResource: (
      _spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      result = data;
      return Promise.resolve({ name });
    },
    logger: { warning() {} },
  };
  await discoverGroups(context);
  const visited = new Set<string>();
  for (let pass = 0; pass < 30; pass++) {
    const groups = result.groups as { workItem: string }[];
    assert(groups.length <= 4);
    groups.forEach((g) => visited.add(g.workItem));
    assertEquals((result.errors as unknown[]).length, 1);
    await discoverGroups(context);
  }
  assertEquals([...visited].sort(), workItems.slice(0, -1).sort());
});

Deno.test("per-group drivers retain and consume pre-approvals from the original named driver", async () => {
  const { behavior: driver } = await import(
    "../extensions/behaviors/orbit_driver.ts"
  );
  const resources = new Map<string, unknown>();
  const name = "preapproval-tg-7-publish-retry-publish-c1";
  resources.set(`original/${name}`, {
    workItem: "tg-7",
    gateId: "publish-retry",
    stage: "publish",
    cycle: 1,
    actor: "nick",
    note: null,
    appliedAt: null,
    createdAt: "2026-10-27T12:01:00Z",
  });
  resources.set("factory/state-tg-7", { startedAt: "2026-10-27T12:00:00Z" });
  resources.set("factory/status-tg-7", {
    started: true,
    stage: { id: "publish", cycle: 1, terminal: false },
    unresolvedBindings: [],
    transitions: [{
      name: "retry",
      to: "publish",
      manual: false,
      satisfied: false,
      cycleLimitBlocked: false,
      gates: [{
        type: "human-approval",
        pass: false,
        reasons: ["awaiting human approval 'publish-retry'"],
      }],
    }],
  });
  let approvals = 0;
  const context = {
    globalArgs: driver.globalArguments.parse({
      factory: "factory",
      preapprovalModel: "original",
    }),
    definition: { name: "orbit-group-tg-7" },
    queryData: recordQuery((predicate: string) => {
      const match = /modelName == "([^"]+)" && name == "([^"]+)"/.exec(
        predicate,
      );
      const value = match
        ? resources.get(`${match[1]}/${match[2]}`)
        : undefined;
      return Promise.resolve(value ? [value] : []);
    }),
    invokeModel: ({ method }: { method: string }) => {
      if (method === "approve") approvals++;
      return Promise.resolve({ ok: true });
    },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      resources.set(`orbit-group-tg-7/${name}`, value);
      return Promise.resolve({ name });
    },
    logger: { info() {}, warning() {} },
  };
  await driver.methods.tick.execute(
    { workItems: ["tg-7"], maxSteps: 1 },
    context,
  );
  // Even if an old status is read again, the per-group consumption receipt holds.
  await driver.methods.tick.execute(
    { workItems: ["tg-7"], maxSteps: 1 },
    context,
  );
  assertEquals(approvals, 1);
});

Deno.test("forty independent group drives progress concurrently beyond one invocation's call limit", async () => {
  const { behavior: groupRun } = await import(
    "../extensions/behaviors/orbit_group_run.ts"
  );
  let active = 0, peak = 0, progressed = 0, totalCalls = 0;
  const counts: number[] = [];
  const results = await Promise.allSettled(
    Array.from({ length: 40 }, async (_, index) => {
      const workItem = `tg-${index + 1}`;
      const request = {
        workItem,
        factory: "factory",
        generation: 1,
        key: `${workItem}.g1`,
        runId: `owner-${index}`,
      };
      const definition = `orbit-group-${workItem}`;
      const records = new Map<string, unknown>([
        [`${definition}/intent`, request],
        [`${definition}/owner`, request],
        [`factory/state-${workItem}`, { startedAt: "2026-10-27T00:00:00Z" }],
        [`factory/status-${workItem}`, {
          started: true,
          stage: { id: "work", cycle: 1, terminal: false },
          unresolvedBindings: [],
          transitions: [],
          dispatch: { required: true, executed: false, attempts: 0, limit: 3 },
          work: {
            mode: "method",
            resultEvidence: "result",
            method: {
              modelIdOrName: `stage-${workItem}`,
              methodName: "work",
              inputs: {},
            },
          },
        }],
      ]);
      let calls = 0;
      const context = {
        globalArgs: groupRun.globalArguments.parse({
          factory: "factory",
          preapprovalModel: "original",
        }),
        repoDir: "/fixture",
        definition: { name: definition },
        queryData: recordQuery((predicate: string) => {
          const match = /modelName == "([^"]+)" && name == "([^"]+)"/.exec(
            predicate,
          );
          const value = match
            ? records.get(`${match[1]}/${match[2]}`)
            : undefined;
          return Promise.resolve(value ? [value] : []);
        }),
        invokeModel: async (call: { definition: string; method: string }) => {
          calls++;
          totalCalls++;
          if (call.method === "work") {
            if (index === 0) throw new Error("one group failed");
            active++;
            peak = Math.max(peak, active);
            await Promise.resolve();
            active--;
            progressed++;
            records.set(`${call.definition}/job-${workItem}.work.c1`, {
              state: "running",
              launchedNow: true,
              launches: 1,
              artifacts: {},
              evidence: null,
            });
          }
          return { ok: true };
        },
        writeResource: (
          _spec: string,
          name: string,
          value: Record<string, unknown>,
        ) => {
          records.set(`${definition}/${name}`, value);
          return Promise.resolve({ name });
        },
        logger: { info() {}, warning() {} },
      };
      try {
        await groupRun.methods.drive.execute(request, context);
      } finally {
        counts.push(calls);
      }
    }),
  );
  assertEquals(progressed, 39);
  assertEquals(results.filter((r) => r.status === "rejected").length, 1);
  assert(peak > 1, "groups must be in flight concurrently");
  assert(
    totalCalls > 100,
    "fleet work must exceed a single invocation's limit",
  );
  assert(counts.every((n) => n <= 60), "each group retains its own budget");
});

Deno.test("failed generations spend three attempts once, then hold across repeated supervisor visits", async () => {
  const f = fixture();
  let failures: RunFailures | null = null;
  f.control.settled = (intent, run) => {
    if (run.status === "failed") {
      failures = failedRun(failures, intent, "broken stage", 3);
    }
    return Promise.resolve(!failures?.parked);
  };
  await ensureGroupRun("tg-7", "factory", f.control);
  for (let generation = 1; generation <= 3; generation++) {
    assert(f.claim(`run-${generation}`));
    f.runs.get(`run-${generation}`)!.status = "failed";
    await ensureGroupRun("tg-7", "factory", f.control);
  }
  for (let visit = 0; visit < 5; visit++) {
    await ensureGroupRun("tg-7", "factory", f.control);
  }
  assertEquals(f.launches(), 3);
  assertEquals((failures as RunFailures | null)?.consecutive, 3);
  assertEquals((failures as RunFailures | null)?.parked, true);
  // Explicit retry clears the budget but retains the already-counted failed key.
  failures = { ...clearedFailures(f.saved()), lastOutcome: "retry" };
  await ensureGroupRun("tg-7", "factory", f.control);
  assertEquals(f.launches(), 4);
  assertEquals((failures as RunFailures).consecutive, 0);
});

Deno.test("failure assistance stays current before status, survives notification failure, and clears only by explicit retry", async () => {
  const { behavior: groupRun, settleGroupRun } = await import(
    "../extensions/behaviors/orbit_group_run.ts"
  );
  const model = "orbit-group-tg-7";
  const records = new Map<string, Record<string, unknown>>();
  const updates: Record<string, unknown>[] = [];
  let reason = "broken 1", notifyFails = false, healthy = false;
  const context = {
    globalArgs: groupRun.globalArguments.parse({
      factory: "factory",
      preapprovalModel: "original",
      assistanceModel: "orbit-gateway",
    }),
    repoDir: "/fixture",
    definition: { name: model },
    queryData: recordQuery((predicate: string) => {
      const name = /name == "([^"]+)"/.exec(predicate)?.[1];
      return Promise.resolve(
        name && records.has(name) ? [records.get(name)!] : [],
      );
    }),
    invokeModel: (
      call: { method: string; arguments?: Record<string, unknown> },
    ) => {
      if (call.method === "status") {
        if (!healthy) throw new Error(reason);
        return Promise.resolve({ ok: true });
      }
      assertEquals(call.method, "assistance");
      updates.push(call.arguments!);
      return Promise.resolve({ ok: !notifyFails });
    },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      records.set(name, value);
      return Promise.resolve({ name });
    },
    logger: { info() {}, warning() {} },
  };
  for (let generation = 1; generation <= 3; generation++) {
    const request = {
      workItem: "tg-7",
      factory: "factory",
      generation,
      key: `tg-7.g${generation}`,
      runId: `run-${generation}`,
    };
    records.set("intent", request);
    records.set("owner", request);
    reason = `broken ${generation}`;
    notifyFails = generation === 3;
    await assertRejects(
      () => groupRun.methods.drive.execute(request, context),
      Error,
      reason,
    );
    const failure = records.get("failures")!;
    assertEquals(failure.consecutive, generation);
    assertEquals(failure.parked, generation === 3);
    assert(String(failure.reason).includes(reason));
    assertEquals(failure.lastKey, request.key);
  }
  assertEquals(updates.length, 1);
  assertEquals(updates[0].parked, true);
  assertEquals(updates[0].gateIds, ["driver-retry-g3"]);
  assert(String(updates[0].reason).includes("broken 3"));
  // Persisted parking is not undone by failed assistance delivery.
  assertEquals(records.get("failures")!.parked, true);
  notifyFails = false;
  await groupRun.methods.retry.execute(
    { actor: "nick", note: "fixed" },
    context,
  );
  assertEquals(records.get("failures")!.consecutive, 0);
  assertEquals(records.get("failures")!.parked, false);
  assertEquals(records.get("retry")!.gateId, "driver-retry-g3");
  assertEquals(records.get("retry")!.actor, "nick");
  assertEquals(updates.at(-1)!.parked, false);
  for (const generation of [4, 5, 6]) {
    const request = {
      workItem: "tg-7",
      factory: "factory",
      generation,
      key: `tg-7.g${generation}`,
      runId: `run-${generation}`,
    };
    records.set("intent", request);
    records.set("owner", request);
    if (generation < 6) {
      await assertRejects(() =>
        groupRun.methods.drive.execute(request, context)
      );
      assertEquals(records.get("failures")!.consecutive, generation - 3);
    } else {
      healthy = true;
      records.set("status-tg-7", {
        stage: { id: "completed", cycle: 1, terminal: true },
      });
      await groupRun.methods.drive.execute(request, context);
      assertEquals(
        records.get("failures")!.consecutive,
        2,
        "work is not a successful native run yet",
      );
      await settleGroupRun(context, request, {
        id: request.runId,
        status: "succeeded",
        inputs: { ...request },
      });
      assertEquals(records.get("failures")!.consecutive, 0);
      assertEquals(records.get("failures")!.parked, false);
    }
  }
});

Deno.test("a native workflow failure after successful work still exhausts the run budget", async () => {
  const { behavior: groupRun, settleGroupRun } = await import(
    "../extensions/behaviors/orbit_group_run.ts"
  );
  const request = (generation: number) => ({
    workItem: "tg-7",
    factory: "factory",
    generation,
    key: `tg-7.g${generation}`,
    runId: `run-${generation}`,
  });
  let failure = failedRun(
    failedRun(null, request(1), "earlier", 3),
    request(2),
    "earlier",
    3,
  );
  const context = {
    globalArgs: groupRun.globalArguments.parse({
      factory: "factory",
      preapprovalModel: "original",
    }),
    definition: { name: "orbit-group-tg-7" },
    repoDir: "/fixture",
    queryData: recordQuery(() => Promise.resolve([failure])),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      failure = value as RunFailures;
      return Promise.resolve({ name });
    },
    invokeModel: () => Promise.resolve({ ok: true }),
    logger: { info() {}, warning() {} },
  };
  const run = {
    id: "run-3",
    status: "failed",
    inputs: { ...request(3) },
    failureReason: "workflow failed after drive succeeded",
  };
  assertEquals(await settleGroupRun(context, request(3), run), false);
  assertEquals(failure.consecutive, 3);
  assertEquals(failure.parked, true);
  assertEquals(failure.reason, run.failureReason);
  assertEquals(await settleGroupRun(context, request(3), run), false);
  assertEquals(
    failure.consecutive,
    3,
    "the supervisor cannot count the same native outcome again",
  );
});

Deno.test("checkpoint recovery reserves attempts, parks before more work, and retries the same owner", async () => {
  const { behavior: group } = await import(
    "../extensions/behaviors/orbit_group_run.ts"
  );
  const request = {
    workItem: "tg-7",
    factory: "factory",
    generation: 1,
    key: "tg-7.g1",
    runId: "owner",
  };
  const records = new Map<string, Record<string, unknown>>([
    ["intent", request],
    ["owner", request],
  ]);
  const updates: Record<string, unknown>[] = [];
  let notifyFails = false;
  const ctx = {
    globalArgs: group.globalArguments.parse({
      factory: "factory",
      preapprovalModel: "fleet",
      assistanceModel: "gateway",
    }),
    repoDir: "/fixture",
    definition: { name: "orbit-group-tg-7" },
    queryData: recordQuery((p: string) => {
      const name = /name == "([^"]+)"/.exec(p)?.[1];
      return Promise.resolve(
        name && records.has(name) ? [records.get(name)!] : [],
      );
    }),
    writeResource: (
      _spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      records.set(name, data);
      return Promise.resolve({ name });
    },
    invokeModel: (
      call: { method: string; arguments?: Record<string, unknown> },
    ) => {
      assertEquals(
        call.method,
        "assistance",
        "no factory operation while parked",
      );
      updates.push(call.arguments!);
      return Promise.resolve({ ok: !notifyFails });
    },
    logger: { info() {}, warning() {} },
  };
  for (let attempt = 1; attempt <= 3; attempt++) {
    await group.methods.checkpoint.execute({ ...request, phase: "begin" }, ctx);
    assertEquals(records.get("recovery")?.attempts, attempt);
    assertEquals(records.get("recovery")?.allowed, true);
    // The second process dies before saving its outcome. The reservation remains.
    if (attempt !== 2) {
      notifyFails = attempt === 3;
      const call = () =>
        group.methods.checkpoint.execute({ ...request, phase: "failed" }, ctx);
      if (notifyFails) await assertRejects(call);
      else await call();
    }
  }
  assertEquals(records.get("failures")?.parked, true);
  assert(String(records.get("failures")?.reason).includes("3 times"));
  notifyFails = false;
  for (let visit = 0; visit < 2; visit++) {
    await group.methods.checkpoint.execute({ ...request, phase: "begin" }, ctx);
    assertEquals(records.get("recovery")?.attempts, 3);
    assertEquals(records.get("recovery")?.allowed, false);
    await group.methods.ensure.execute({ workItem: "tg-7" }, ctx);
    assertEquals(records.get("reconcile")?.runId, null);
    assertEquals(records.get("intent"), request);
  }
  assertEquals(updates.at(-1)?.gateIds, ["driver-retry-g1"]);
  await group.methods.retry.execute(
    { actor: "nick", note: "fixed checkpoint" },
    ctx,
  );
  assertEquals(records.get("recovery")?.attempts, 0);
  await group.methods.checkpoint.execute({ ...request, phase: "begin" }, ctx);
  assertEquals(records.get("recovery")?.allowed, true);
  await group.methods.checkpoint.execute(
    { ...request, phase: "recovered" },
    ctx,
  );
  assertEquals(records.get("recovery")?.attempts, 0);
  await assertRejects(() =>
    group.methods.checkpoint.execute({
      ...request,
      runId: "foreign",
      phase: "begin",
    }, ctx)
  );
});

Deno.test("many conflicting factory memberships retain assistance identities without spending the discovery call budget", async () => {
  const records = new Map<string, Record<string, unknown>>();
  const workItems = Array.from({ length: 120 }, (_, i) => `tg-${i + 1}`);
  const ctx = {
    definition: { name: "orbit-group-runs" },
    globalArgs: {
      factories: ["a", "b"].map((factory) => ({
        factory,
        preapprovalModel: factory,
      })),
    },
    queryData: recordQuery((p: string) => {
      if (p.includes("schedule-")) return Promise.resolve([]);
      if (p.includes("conflict-")) {
        const name = /name == "([^"]+)"/.exec(p)?.[1];
        return Promise.resolve(
          name && records.has(name) ? [records.get(name)!] : [],
        );
      }
      if (!p.includes('name.startsWith("state-")')) return Promise.resolve([]);
      return Promise.resolve([
        ...workItems.map((workItem) => ({ workItem, status: "active" })),
        ...(p.includes('"a"')
          ? [{ workItem: "tg-999", status: "active" }]
          : []),
      ]);
    }),
    invokeModel: (call: { method: string }) => {
      assertEquals(
        call.method,
        "status",
        "notification runs in its own native workflow step",
      );
      return Promise.resolve({ ok: true });
    },
    writeResource: (
      _spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      records.set(name, data);
      return Promise.resolve({ name });
    },
    logger: { warning() {} },
  };
  await discoverGroups(ctx);
  const identity = records.get("conflict-tg-7")?.startedAt;
  assertEquals(
    (records.get("groups")?.groups as { workItem: string }[]).map((g) =>
      g.workItem
    ),
    ["tg-999"],
  );
  assertEquals((records.get("groups")?.conflicts as unknown[]).length, 120);
  await discoverGroups(ctx);
  assertEquals(records.get("conflict-tg-7")?.startedAt, identity);
  assertEquals(records.get("conflict-tg-7")?.factories, ["a", "b"]);
});

Deno.test("unchanged human waits stay idle; approval changes wake them; terminal groups stay out", async () => {
  const { groupFingerprint } = await import(
    "../extensions/behaviors/_lib/group_activity.ts"
  );
  let approval = "before",
    fingerprint = "",
    result: Record<string, unknown> = {};
  const ctx = {
    definition: { name: "orbit-group-runs" },
    globalArgs: {
      factories: [{ factory: "factory", preapprovalModel: "original" }],
    },
    queryData: recordQuery((p: string) =>
      Promise.resolve(
        p.includes("modelName in")
          ? [{ approval }]
          : p.includes('name == "activity"')
          ? [{ idle: true, fingerprint }]
          : p.includes('name.startsWith("state-")')
          ? [{ workItem: "tg-7", status: "active" }, {
            workItem: "tg-8",
            status: "terminal",
          }]
          : [],
      )
    ),
    invokeModel: () => {
      throw new Error("discovery must not run factory methods");
    },
    writeResource: (_s: string, _n: string, v: Record<string, unknown>) => {
      result = v;
      return Promise.resolve({ name: "groups" });
    },
    logger: { warning() {} },
  };
  fingerprint = await groupFingerprint(ctx, "factory", "tg-7", "original");
  await discoverGroups(ctx);
  assertEquals(result.groups, []);
  approval = "after";
  await discoverGroups(ctx);
  assertEquals(result.groups, [{
    workItem: "tg-7",
    factory: "factory",
    preapprovalModel: "original",
  }]);
});

Deno.test("a late supervisor cannot launch another generation for a terminal factory", async () => {
  const { behavior: group } = await import(
    "../extensions/behaviors/orbit_group_run.ts"
  );
  const result = await group.methods.ensure.execute({ workItem: "tg-7" }, {
    globalArgs: group.globalArguments.parse({
      factory: "factory",
      preapprovalModel: "original",
    }),
    definition: { name: "orbit-group-tg-7" },
    repoDir: "/fixture",
    queryData: recordQuery(() => Promise.resolve([{ status: "terminal" }])),
    invokeModel: () => {
      throw new Error("must not launch or recover");
    },
    writeResource: (_s: string, _n: string, v: Record<string, unknown>) => {
      assertEquals(v, { runId: null });
      return Promise.resolve({ name: "reconcile" });
    },
    logger: { info() {}, warning() {} },
  });
  assertEquals(result, { dataHandles: [{ name: "reconcile" }] });
});

Deno.test("existing owners hold all four slots across ticks; terminal owners release them", async () => {
  const root = await Deno.makeTempDir();
  const { join } = await import("jsr:@std/path@1.1.4");
  const workflow = crypto.randomUUID();
  const owners = Array.from(
    { length: 4 },
    (_, i) => ({
      workItem: `tg-${i + 1}`,
      runId: crypto.randomUUID(),
      generation: 1,
    }),
  );
  let completed = false, result: Record<string, unknown> = {};
  try {
    await Deno.mkdir(join(root, "workflows"), { recursive: true });
    await Deno.writeTextFile(
      join(root, "workflows/workflow-group-run.yaml"),
      JSON.stringify({ id: workflow }),
    );
    const directory = join(root, ".swamp/workflow-runs", workflow);
    await Deno.mkdir(directory, { recursive: true });
    for (const o of owners) {
      await Deno.writeTextFile(
        join(directory, `workflow-run-${o.runId}.yaml`),
        JSON.stringify({ status: "running" }),
      );
    }
    const ctx = {
      repoDir: root,
      definition: { name: "orbit-group-runs" },
      globalArgs: { factories: [{ factory: "f", preapprovalModel: "p" }] },
      queryData: recordQuery((p: string) =>
        Promise.resolve(
          p.includes("modelName.startsWith")
            ? owners
            : p.includes('name.startsWith("state-")')
            ? Array.from(
              { length: 5 },
              (_, i) => ({
                workItem: `tg-${i + 1}`,
                status: completed && i === 0 ? "terminal" : "active",
              }),
            )
            : [],
        )
      ),
      invokeModel: () => Promise.resolve({ ok: true }),
      logger: { warning() {} },
      writeResource: (_s: string, _n: string, v: Record<string, unknown>) => {
        result = v;
        return Promise.resolve({ name: "groups" });
      },
    };
    for (let i = 0; i < 3; i++) {
      await discoverGroups(ctx);
      assertEquals(result.groups, []);
    }
    completed = true;
    await discoverGroups(ctx);
    assertEquals(result.groups, [{
      workItem: "tg-5",
      factory: "f",
      preapprovalModel: "p",
    }]);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
