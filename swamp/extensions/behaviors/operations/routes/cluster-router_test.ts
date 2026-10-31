import { assertEquals } from "jsr:@std/assert@1";
import { phaseResult, routerResult } from "../cluster-router/result.ts";
const phase = (
    ready: boolean,
    selected = ready,
    migrations: number[] = [],
) => ({
    status: "succeeded",
    outputs: { result: { ready, selected, migrated_node_ids: migrations } },
});
const result = (steps: Record<string, unknown>, extra = {}) =>
    JSON.parse(
        routerResult({ noop: false, old_count: 1, steps, ...extra }).stdout,
    );
Deno.test("Router terminal selection retains cleanup failure and exact native restoration", () => {
    assertEquals(
        result({ forward: phase(true), "retire-0": { status: "failed" } }),
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
        result({ forward: phase(false, true), restore: phase(true) }).selected,
        false,
    );
    assertEquals(
        result({ forward: phase(false, true), restore: phase(false) }).selected,
        true,
    );
});
Deno.test("Router completion requires every obsolete assignment and accepts genuine no-op", () => {
    assertEquals(
        result({ forward: phase(true), "retire-0": { status: "succeeded" } })
            .ready,
        true,
    );
    assertEquals(
        result({ forward: phase(true) }, { old_count: 2 }).ready,
        false,
    );
    assertEquals(result({}, { noop: true }).ready, true);
});
Deno.test("Phase summaries require final contribution proof and retain observed selection and migrations after failure", () => {
    const raw = {
        mode: "forward",
        dns_count: 2,
        steps: {
            "forward_prepare_dns-0": { status: "succeeded" },
            "forward_prepare_dns-1": { status: "succeeded" },
            "forward_lan-0": {
                status: "succeeded",
                outputs: { result: { migrated_node_ids: [9] } },
            },
            "forward_cleanup-0": { status: "failed" },
        },
    };
    assertEquals(phaseResult(raw), {
        ready: false,
        selected: true,
        migrated_node_ids: [9],
        failed_step: "forward_cleanup-0",
    });
    assertEquals(phaseResult({ ...raw, dns_count: 3 }).selected, false);
    assertEquals(phaseResult({ ...raw, mode: "restore" }).selected, false);
    assertEquals(
        result({
            forward: phase(false, true, [9]),
            restore: phase(true, false, [13]),
        }).migrated_node_ids,
        [9, 13],
    );
});

Deno.test("Router records candidate firewall migrations even if forward preparation fails", () => {
    assertEquals(
        result({
            prepare: {
                status: "succeeded",
                outputs: { firewall: { result: { migrated_node_ids: [9] } } },
            },
            forward: phase(false, false),
        }).migrated_node_ids,
        [9],
    );
});
