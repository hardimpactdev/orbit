// Pure helpers of @nckrtl/orbit-driver: ~/.swamp/deno/deno test tests/
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { behavior, pendingGateIds, preapprovalName, recordedThisEntry, tickOrder } from "../extensions/behaviors/orbit_driver.ts";

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

Deno.test("evidence counts for the current entry only when it was recorded in this run's era", () => {
  const ev = { stageId: "provision", cycle: 1, recordedAt: "2026-10-23T08:00:00Z" };
  assert(recordedThisEntry(ev, "provision", 1, "2026-10-23T07:00:00Z"));
  assert(!recordedThisEntry(ev, "provision", 1, "2026-10-23T09:00:00Z"));
  assert(!recordedThisEntry(ev, "provision", 2, "2026-10-23T07:00:00Z"));
  assert(!recordedThisEntry(ev, "baseline", 1, "2026-10-23T07:00:00Z"));
  assert(!recordedThisEntry(null, "provision", 1, null));
});

Deno.test("a tick starts at the first work item the last tick deferred", () => {
  assertEquals(tickOrder(["a", "b", "c", "d"], 0), ["a", "b", "c", "d"]);
  assertEquals(tickOrder(["a", "b", "c", "d"], 2), ["c", "d", "a", "b"]);
  assertEquals(tickOrder(["a", "b"], 5), ["b", "a"]);
  assertEquals(tickOrder([], 3), []);
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
    queryData: (predicate: string) => {
      if (predicate.includes('name == "status-tg-7"')) return Promise.resolve([status]);
      if (predicate.includes('name == "state-tg-7"')) return Promise.resolve([{ workItem: "tg-7", startedAt: era }]);
      if (predicate.includes('name == "preapproval-')) return Promise.resolve(pre ? [pre] : []);
      return Promise.resolve([]);
    },
    runModel: (call: (typeof calls)[number]) => {
      calls.push(call);
      if (!["status", "assistance", "approve"].includes(call.method)) throw new Error(`Unexpected effect: ${call.method}`);
      return Promise.resolve({ ok: true });
    },
    writeResource: (_spec: string, name: string, _data: Record<string, unknown>) => Promise.resolve({ name }),
    logger: { info: () => {}, warning: () => {} },
  };
  return { calls, tick: () => behavior.methods.tick.execute({ workItems: ["tg-7"], maxSteps: 1 }, context) };
}

Deno.test("parked tick passes the exact gate and factory to assistance without approval or effect dispatch", async () => {
  const h = parkedDriver();
  await h.tick();
  await h.tick(); // A board comment cannot change these factory status/approval resources.
  assertEquals(h.calls.map((c) => c.method), ["status", "assistance", "status", "assistance"]);
  assertEquals(h.calls[1].arguments, {
    workItem: "tg-7", parked: true, factory: "orbit-groups", gateIds: ["publish-retry"], stage: "publish", cycle: 1,
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
});
