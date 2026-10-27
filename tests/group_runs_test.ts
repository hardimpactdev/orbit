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

Deno.test("discovery includes many groups in one pass and isolates an unavailable factory", async () => {
  const workItems = Array.from({ length: 120 }, (_, i) => `tg-${i + 1}`);
  const seen: string[] = [];
  let result: Record<string, unknown> = {};
  const context = {
    globalArgs: {
      factories: [
        { factory: "broken", preapprovalModel: "broken-driver" },
        { factory: "healthy", preapprovalModel: "healthy-driver" },
      ],
    },
    queryData: (predicate: string) =>
      Promise.resolve(
        predicate.includes("schedule-")
          ? ["tg-120"]
          : [workItems.map((workItem) => ({ workItem, status: "active" }))],
      ),
    runModel: ({ definition }: { definition: string }) => {
      seen.push(definition);
      if (definition === "broken") throw new Error("one factory unavailable");
      return Promise.resolve({ ok: true });
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
  assertEquals(seen, ["broken", "healthy"]);
  assertEquals(
    (result.groups as { workItem: string }[]).map((g) => g.workItem),
    workItems.slice(0, -1),
  );
  assertEquals((result.errors as unknown[]).length, 1);
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
    queryData: (predicate: string) => {
      const match = /modelName == "([^"]+)" && name == "([^"]+)"/.exec(
        predicate,
      );
      const value = match
        ? resources.get(`${match[1]}/${match[2]}`)
        : undefined;
      return Promise.resolve(value ? [value] : []);
    },
    runModel: ({ method }: { method: string }) => {
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
        queryData: (predicate: string) => {
          const match = /modelName == "([^"]+)" && name == "([^"]+)"/.exec(
            predicate,
          );
          const value = match
            ? records.get(`${match[1]}/${match[2]}`)
            : undefined;
          return Promise.resolve(value ? [value] : []);
        },
        runModel: async (call: { definition: string; method: string }) => {
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
    queryData: (predicate: string) => {
      const name = /name == "([^"]+)"/.exec(predicate)?.[1];
      return Promise.resolve(
        name && records.has(name) ? [records.get(name)!] : [],
      );
    },
    runModel: (
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
    queryData: () => Promise.resolve([failure]),
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      failure = value as RunFailures;
      return Promise.resolve({ name });
    },
    runModel: () => Promise.resolve({ ok: true }),
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
