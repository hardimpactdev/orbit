import { assertEquals } from "jsr:@std/assert@1";
import { routerResult } from "../cluster-router/result.ts";
const result = (steps: Record<string, { status: string }>, extra = {}) =>
    JSON.parse(
        routerResult({
            noop: false,
            dns_count: 1,
            old_count: 1,
            steps,
            ...extra,
        }).stdout,
    );
Deno.test("Router terminal selection retains cleanup failure and exact native restoration", () => {
    assertEquals(
        result({
            "forward-0": { status: "succeeded" },
            "retire-0": { status: "failed" },
        }),
        {
            ready: false,
            selected: true,
            restored: false,
            cleanup_complete: false,
            migrated_node_ids: [],
            failed_step: "retire-0",
            error_code: "cluster.router_failed",
        },
    );
    assertEquals(
        result({
            "forward_prepare_dns-0": { status: "succeeded" },
            "restore-0": { status: "succeeded" },
        }).selected,
        false,
    );
    assertEquals(
        result({
            "forward_prepare_dns-0": { status: "succeeded" },
            "restore-0": { status: "failed" },
        }).selected,
        true,
    );
});
Deno.test("Router completion requires every obsolete baseline and accepts genuine no-op", () => {
    assertEquals(
        result({
            "forward-0": { status: "succeeded" },
            "retire-0": { status: "succeeded" },
        }).ready,
        true,
    );
    assertEquals(
        result({ "forward-0": { status: "succeeded" } }, { old_count: 2 })
            .ready,
        false,
    );
    assertEquals(result({}, { noop: true }).ready, true);
});
