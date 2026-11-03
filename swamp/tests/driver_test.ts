import { recordQuery } from "./fixtures/worker_query.ts";
// Pure helpers of @nckrtl/orbit-driver: ~/.swamp/deno/deno test tests/
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { planConsentNote } from "../extensions/behaviors/_lib/repository_analysis.ts";
import { behavior, pendingGateIds, preapprovalName, recordedThisEntry } from "../extensions/behaviors/orbit_driver.ts";

const gate = (type: string, pass: boolean, reasons: string[] = []) => ({ type, pass, reasons });
const awaiting = (id: string) => `awaiting human approval '${id}' (0/1) for stage 'baseline' cycle 1 — a human must run approve with gateId=${id}`;

Deno.test("a pre-approval is named by its exact work item, gate, stage and cycle", () => {
  const name = preapprovalName("tg-7", "baseline-retry", "baseline", 1);
  assertEquals(name, "preapproval-tg-7-baseline-retry-baseline-c1");
  assert(name !== preapprovalName("tg-8", "baseline-retry", "baseline", 1));
  assert(name !== preapprovalName("tg-7", "baseline-retry", "handoff", 1));
  assert(name !== preapprovalName("tg-7", "baseline-retry", "baseline", 2));
  assert(name !== preapprovalName("tg-7", "baseline-override", "baseline", 1));
});

Deno.test("pending gate ids come from human gates on otherwise passing transitions, and cycle overrides", () => {
  const status = {
    transitions: [
      { name: "retry", to: "baseline", manual: false, satisfied: false, cycleLimitBlocked: false, gates: [gate("evidence-recorded", true), gate("human-approval", false, [awaiting("baseline-retry")])] },
      { name: "override", to: "implement", manual: false, satisfied: false, cycleLimitBlocked: false, gates: [gate("cel", false, ["no"]), gate("human-approval", false, [awaiting("baseline-override")])] },
      { name: "rework", to: "implement", manual: false, satisfied: false, cycleLimitBlocked: true, gates: [gate("cel", true)] },
      { name: "abort", to: "cancelled", manual: false, satisfied: false, cycleLimitBlocked: false, gates: [gate("human-approval", false, [awaiting("abort-confirmation")])] },
    ],
  };
  assertEquals(pendingGateIds(status), ["baseline-retry", "cycle-override:implement"]);
});

Deno.test("a consent gate that reads its own approval waits on that human gate, not on itself", () => {
  const noKey = "CEL gate evaluation failed: No such key: plan_authorized\n\n>    1 | [approvals.plan_authorized.filter(a,";
  const status = {
    transitions: [
      { name: "authorized", to: "analyze", manual: false, satisfied: false, cycleLimitBlocked: false,
        gates: [gate("artifact-exists", true), gate("human-approval", false, [awaiting("plan-authorized")]), gate("cel", false, [noKey])] },
      { name: "other", to: "analyze", manual: false, satisfied: false, cycleLimitBlocked: false,
        gates: [gate("human-approval", false, [awaiting("other-gate")]), gate("cel", false, ["No such key: plan_authorized"])] },
    ],
  };
  assertEquals(pendingGateIds(status), ["plan-authorized"], "a CEL gate on another gate's key still blocks");
});

Deno.test("a later plan cycle waits on its consent gate once an earlier cycle's approval exists", () => {
  const later = "awaiting human approval 'plan-authorized' with note=planConsentNote(fresh plan payload): the latest decision of this cycle must authorize this exact plan";
  const status = {
    transitions: [
      { name: "authorized", to: "analyze", manual: false, satisfied: false, cycleLimitBlocked: false,
        gates: [gate("artifact-exists", true), gate("human-approval", false, [awaiting("plan-authorized")]), gate("cel", false, [later])] },
      { name: "other", to: "analyze", manual: false, satisfied: false, cycleLimitBlocked: false,
        gates: [gate("human-approval", false, [awaiting("other-gate")]), gate("cel", false, [later])] },
    ],
  };
  assertEquals(pendingGateIds(status), ["plan-authorized"], "a CEL gate awaiting another gate still blocks");
});

Deno.test("evidence counts for the current entry only when it was recorded in this run's era", () => {
  const ev = { stageId: "provision", cycle: 1, recordedAt: "2026-10-23T08:00:00Z" };
  assert(recordedThisEntry(ev, "provision", 1, "2026-10-23T07:00:00Z"));
  assert(!recordedThisEntry(ev, "provision", 1, "2026-10-23T09:00:00Z"));
  assert(!recordedThisEntry(ev, "provision", 2, "2026-10-23T07:00:00Z"));
  assert(!recordedThisEntry(ev, "baseline", 1, "2026-10-23T07:00:00Z"));
  assert(!recordedThisEntry(null, "provision", 1, null));
});

// Exercise the actual tick, with fixture resources and no effect-capable target.
function parkedDriver(pre: Record<string, unknown> | null = null) {
  const calls: { definition: string; method: string; arguments?: Record<string, unknown> }[] = [];
  const era = "2026-10-23T12:00:00.000Z";
  const status = {
    started: true, stage: { id: "publish", cycle: 1, terminal: false }, unresolvedBindings: [],
    transitions: [{ name: "retry", to: "publish", manual: false, satisfied: false, cycleLimitBlocked: false,
      gates: [gate("human-approval", false, ["awaiting human approval 'publish-retry'"])] }],
  };
  const context = {
    globalArgs: behavior.globalArguments.parse({ factory: "orbit-groups", assistanceModel: "orbit-gateway" }),
    definition: { name: "orbit-driver-groups" },
    queryData: recordQuery((predicate: string) => {
      if (predicate.includes('name == "status-tg-7"')) return Promise.resolve([status]);
      if (predicate.includes('name == "state-tg-7"')) return Promise.resolve([{ workItem: "tg-7", startedAt: era }]);
      if (predicate.includes('name == "preapproval-')) return Promise.resolve(pre ? [pre] : []);
      return Promise.resolve([]);
    }),
    invokeModel: (call: (typeof calls)[number]) => {
      calls.push(call);
      if (!["status", "assistance", "approve"].includes(call.method)) throw new Error(`Unexpected effect: ${call.method}`);
      return Promise.resolve({ ok: true });
    },
    writeResource: (_spec: string, name: string, _data: Record<string, unknown>) => Promise.resolve({ name }),
    logger: { info: () => {}, warning: () => {} },
  };
  return { calls, tick: () => behavior.methods.tick.execute({ workItem: "tg-7", maxSteps: 1 }, context) };
}

Deno.test("parked tick passes the exact gate and factory to assistance without approval or effect dispatch", async () => {
  const h = parkedDriver();
  await h.tick();
  await h.tick(); // A board comment cannot change these factory status/approval resources.
  assertEquals(h.calls.map((c) => c.method), ["status", "assistance", "status", "assistance"]);
  assertEquals(h.calls[1].arguments, {
    workItem: "tg-7", parked: true, factory: "orbit-groups", driverDefinition: "orbit-driver-groups", gateIds: ["publish-retry"], stage: "publish", cycle: 1,
    reason: "awaiting human approval 'publish-retry'",
  });
});

Deno.test("parked tick rejects mismatched or pre-reset preapprovals before factory approve", async () => {
  const pre = { workItem: "tg-7", gateId: "publish-retry", stage: "publish", cycle: 1, actor: "nick", note: null, appliedAt: null, createdAt: "2026-10-23T12:01:00.000Z" };
  for (const bad of [{ workItem: "tg-8" }, { gateId: "republish" }, { stage: "published" }, { cycle: 2 }, { createdAt: "2026-10-23T11:00:00.000Z" }, { createdAt: "invalid" }]) {
    const h = parkedDriver({ ...pre, ...bad });
    await h.tick();
    assertEquals(h.calls.map((c) => c.method), ["status", "assistance"], JSON.stringify(bad));
  }
  const valid = parkedDriver(pre);
  await valid.tick();
  assertEquals(valid.calls.map((c) => c.method), ["status", "approve", "assistance"]);
  assertEquals(valid.calls[2].arguments!.parked, false);
  const exact = parkedDriver({ ...pre, note: "plan-v1:3:abc" });
  await exact.tick();
  assertEquals(exact.calls[1].arguments!.note, "plan-v1:3:abc", "a gate that checks the note exactly gets it verbatim");
});

Deno.test("a group's failed status is recorded for retry, and a later tick retries it", async () => {
  for (const failure of ["throw", "result"] as const) {
    const visited: string[] = [];
    const ticks: Record<string, unknown>[] = [];
    let broken = true;
    const status = { started: true, stage: { id: "completed", cycle: 1, terminal: true }, unresolvedBindings: [], transitions: [] };
    const context = {
      globalArgs: behavior.globalArguments.parse({ factory: "orbit-groups" }),
      definition: { name: "orbit-driver-groups" },
      queryData: recordQuery((predicate: string) => Promise.resolve(predicate.includes('name == "status-') ? [status] : [])),
      invokeModel: (call: { method: string; arguments?: Record<string, unknown> }) => {
        visited.push(String(call.arguments?.workItem));
        if (broken) {
          if (failure === "throw") throw new Error("group unavailable");
          return Promise.resolve({ ok: false, error: { message: "group unavailable" } });
        }
        return Promise.resolve({ ok: true });
      },
      writeResource: (_spec: string, name: string, data: Record<string, unknown>) => {
        ticks.push(data);
        return Promise.resolve({ name });
      },
      logger: { info: () => {}, warning: () => {} },
    };
    await behavior.methods.tick.execute({ workItem: "tg-7", maxSteps: 3 }, context);
    assertEquals(visited, ["tg-7"]);
    const [failed] = ticks[0].items as { workItem: string; error?: string; stage: string | null; cycle: number | null }[];
    assert(failed.error?.includes("group unavailable"));
    assertEquals([failed.workItem, failed.stage, failed.cycle], ["tg-7", null, null]);
    // Failure is visible, not a terminal factory state. A later tick retries it.
    broken = false;
    await behavior.methods.tick.execute({ workItem: "tg-7", maxSteps: 3 }, context);
    const [retried] = ticks[1].items as { error?: string; stage: string | null }[];
    assertEquals([retried.error, retried.stage], [undefined, "completed"]);
  }
});

Deno.test("start begins exactly one work item in the driver's factory on the worker", async () => {
  const calls: { definition: string; method: string; arguments?: Record<string, unknown> }[] = [];
  const context = {
    globalArgs: behavior.globalArguments.parse({ factory: "orbit-groups" }),
    definition: { name: "orbit-driver-groups" },
    queryData: recordQuery(() => Promise.resolve([])),
    invokeModel: (call: (typeof calls)[number]) => {
      calls.push(call);
      return Promise.resolve({ ok: true });
    },
    writeResource: (_spec: string, name: string) => Promise.resolve({ name }),
    logger: { info: () => {}, warning: () => {} },
  };
  await behavior.methods.start.execute({ workItem: "tg-2314" }, context);
  assertEquals(calls, [{ definition: "orbit-groups", method: "start", arguments: { workItem: "tg-2314" } }]);
  const active = { ...context, queryData: recordQuery(() => Promise.resolve([{ status: "active" }])) };
  await behavior.methods.start.execute({ workItem: "tg-2314" }, active);
  assertEquals(calls.length, 1, "an active work item is not started again");
  const refused = { ...context, invokeModel: () => Promise.resolve({ ok: false, error: { message: "already started" } }) };
  let failed = false;
  await behavior.methods.start.execute({ workItem: "tg-2314" }, refused).catch(() => (failed = true));
  assert(failed, "a refused factory start fails the step");
});

Deno.test("plan records the operator's plan and its exact consent only at the plan stage", async () => {
  const plan = {
    manifest: { id: "tg-2314", revision: 1, source: { brief_id: "tg-2314", revision_sha256: "a".repeat(64) }, asserted_by: "operator",
      complete: true as const, paths: [{ change: "modify" as const, path: "docs/reference/metrics.md" }] },
    plan_sha256: "b".repeat(64), base: "c".repeat(40), repository: "github.com/hardimpactdev/orbit", branch: "task-2314", docsSubtask: 2324,
  };
  for (const stage of ["plan", "analyze"]) {
    const calls: { method: string; arguments?: Record<string, unknown> }[] = [];
    const writes: Record<string, unknown>[] = [];
    const context = {
      globalArgs: behavior.globalArguments.parse({ factory: "orbit-groups" }),
      definition: { name: "orbit-driver-groups" },
      queryData: recordQuery(() => Promise.resolve([{ started: true, stage: { id: stage, cycle: 2, terminal: false }, unresolvedBindings: [], transitions: [] }])),
      invokeModel: (call: (typeof calls)[number]) => {
        calls.push(call);
        return Promise.resolve({ ok: true });
      },
      writeResource: (_spec: string, name: string, data: Record<string, unknown>) => {
        writes.push(data);
        return Promise.resolve({ name });
      },
      logger: { info: () => {}, warning: () => {} },
    };
    const run = behavior.methods.plan.execute({ workItem: "tg-2314", plan, actor: "operator" }, context);
    if (stage !== "plan") {
      let refused = false;
      await run.catch(() => (refused = true));
      assert(refused, "a plan outside the plan stage is refused");
      assertEquals(calls.map((c) => c.method), ["status"]);
      continue;
    }
    await run;
    assertEquals(calls.map((c) => c.method), ["status", "record_artifact"]);
    assertEquals(calls[1].arguments, { workItem: "tg-2314", name: "plan", payload: plan });
    assertEquals(writes[0].gateId, "plan-authorized");
    assertEquals(writes[0].cycle, 2);
    assertEquals(writes[0].note, planConsentNote(plan));
  }
});
