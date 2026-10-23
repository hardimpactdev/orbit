// Pure helpers of @nckrtl/orbit-driver: ~/.swamp/deno/deno test tests/
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { pendingGateIds, preapprovalName, recordedThisEntry, tickOrder } from "../extensions/behaviors/orbit_driver.ts";

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
