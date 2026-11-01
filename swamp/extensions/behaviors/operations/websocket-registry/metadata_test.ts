import { assertEquals } from "jsr:@std/assert@1.0.19";
import { terminal } from "./metadata.ts";
const id = "8f61a312-2b19-4ef7-92d5-39dff3d42380";
const other = "52eb4df2-ee4b-4950-9c46-d6f9fb1836e9";
const intent = {
    start_tag: id,
    registry_operation_id: id,
    epoch: "fixture",
    revision: 7,
    change: "sync",
    app_id: null,
    credential_generation: null,
    missing_node_ids: [],
    runtimes: [{
        process_id: id,
        node_id: 9,
        port: 8790,
        credential_field: "runtime_8f61a312_2b19_4ef7_92d5_39dff3d42380",
        ssh: {
            identity_file: "/fixture/key",
            target: { host: "fixture", user: "orbit", port: 22, host_keys: [] },
        },
    }],
};
const step = (value: unknown) => ({
    status: "succeeded",
    outputs: { stdout: JSON.stringify(value) },
});
const result = {
    process_id: id,
    boot_id: other,
    epoch: "fixture",
    revision: 7,
};
const data = (value: ReturnType<typeof terminal>) => JSON.parse(value.stdout);
Deno.test("native WebSocket aggregation accepts every exact admitted runtime", () => {
    assertEquals(data(terminal(intent, { "apply-0": step(result) })), {
        outcome: "succeeded",
        data: { backend: "applied" },
    });
    assertEquals(terminal(intent, { "apply-0": step(result) }).accepted, true);
});
Deno.test("native WebSocket aggregation refuses missing membership and failed steps without reading missing outputs", () => {
    assertEquals(
        data(terminal({ ...intent, runtimes: [] }, {})).error_code,
        "websocket.registry_pending",
    );
    assertEquals(
        data(terminal({ ...intent, missing_node_ids: [10] }, {})).error_code,
        "websocket.registry_pending",
    );
    for (
        const steps of [{}, { "apply-0": { status: "failed" } }, {
            "apply-0": { status: "skipped" },
        }] as Parameters<typeof terminal>[1][]
    ) {
        assertEquals(
            data(terminal(intent, steps)).error_code,
            "websocket.registry_unavailable",
        );
    }
});
Deno.test("native WebSocket aggregation preserves safe provider refusal and rejects foreign or private output", () => {
    assertEquals(
        data(
            terminal(intent, {
                "apply-0": step({
                    error_code: "websocket.registry_superseded",
                }),
            }),
        ).error_code,
        "websocket.registry_superseded",
    );
    for (
        const value of [
            { ...result, process_id: other },
            { ...result, revision: 6 },
            { ...result, epoch: "foreign" },
            { ...result, secret: "private-fixture" },
            { error_code: "private-fixture" },
        ]
    ) {
        assertEquals(
            data(terminal(intent, { "apply-0": step(value) })).error_code,
            "websocket.registry_mismatch",
        );
    }
});
Deno.test("native WebSocket aggregation cannot count one runtime twice", () => {
    const two = {
        ...intent,
        runtimes: [...intent.runtimes, {
            ...intent.runtimes[0],
            process_id: other,
        }],
    };
    assertEquals(
        data(
            terminal(two, { "apply-0": step(result), "apply-1": step(result) }),
        ).error_code,
        "websocket.registry_mismatch",
    );
});

Deno.test("phase timeout is public pending without parsing empty command output", () => {
    for (const exitCode of [124, 137]) {
        assertEquals(
            data(
                terminal(intent, {
                    "apply-0": {
                        status: "succeeded",
                        outputs: { exitCode, stdout: "" },
                    },
                }),
            ).error_code,
            "websocket.registry_pending",
        );
    }
});
