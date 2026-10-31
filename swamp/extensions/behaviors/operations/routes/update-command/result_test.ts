import { assertEquals } from "jsr:@std/assert@1.0.19";
import { phaseResult, updateResult } from "./result.ts";
const state = (ready: boolean, nodes: number[] = []) => ({
    status: "succeeded",
    outputs: { result: { ready, migrated_node_ids: nodes } },
});
function result(steps: Record<string, unknown>, effectful = true) {
    return JSON.parse(
        updateResult({ effectful, route_id: 1, result_route_id: 2, steps })
            .stdout,
    );
}
Deno.test("only complete native contributions prove forward selection", () => {
    assertEquals(result({ forward: state(true, [9]) }).selected, "after");
    assertEquals(result({ forward: state(true, [9]) }).migrated_node_ids, [9]);
    assertEquals(result({ forward: state(false, [9]) }).ready, false);
    assertEquals(result({ forward: state(false, [9]) }).selected, "unknown");
    assertEquals(
        phaseResult({
            mode: "forward",
            steps: { "forward_php-0": state(true) },
        }).ready,
        false,
    );
});
Deno.test("complete restoration preserves original failure and reports prior publication", () => {
    const restored = result({
        forward: state(false, [9]),
        restore: state(true, [9]),
    });
    assertEquals(restored.ready, false);
    assertEquals(restored.restored, true);
    assertEquals(restored.selected, "before");
    assertEquals(restored.error_code, "route.update_failed");
    assertEquals(restored.migrated_node_ids, [9]);
});
Deno.test("incomplete restoration never claims an authoritative physical selection", () => {
    assertEquals(
        result({ forward: state(false), restore: state(false) }).selected,
        "unknown",
    );
    assertEquals(
        result({ forward: state(false), restore: { status: "failed" } })
            .restored,
        false,
    );
});
Deno.test("an unchanged or pending update needs no host contribution", () => {
    const noop = result({}, false);
    assertEquals(noop.ready, true);
    assertEquals(noop.restored, false);
    assertEquals(noop.migrated_node_ids, []);
});
