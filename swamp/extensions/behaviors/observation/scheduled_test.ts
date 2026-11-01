import { assertEquals, assertThrows } from "jsr:@std/assert";
import { scheduledResult, selectContext } from "./scheduled.ts";

const now = Date.parse("2026-11-01T04:00:00Z");
const context = {
    channel: "fleet",
    revision: 4,
    expectations: "a".repeat(64),
    captured_at: new Date(now).toISOString(),
    node_id: 209,
    intent: { nodes: [], families: ["node"], collect_problems: true },
};
Deno.test("late publication cannot replace a newer immutable expectation revision", () => {
    assertEquals(
        selectContext([{ ...context, revision: 9 }, context], now).revision,
        9,
    );
});
Deno.test("missing expired and future contexts fail instead of reporting health", () => {
    for (
        const candidates of [[], [{
            ...context,
            captured_at: new Date(now - 3600001).toISOString(),
        }], [{ ...context, captured_at: new Date(now + 60001).toISOString() }]]
    ) {
        assertThrows(
            () => selectContext(candidates, now),
            Error,
            "observation.context_unavailable",
        );
    }
});
Deno.test("scheduled output carries the exact revision and refuses an unusable native result", () => {
    assertThrows(() => scheduledResult(context, { stdout: "{}", exitCode: 1 }));
    assertThrows(() => scheduledResult(context, { stdout: "{}", exitCode: 0 }));
});
