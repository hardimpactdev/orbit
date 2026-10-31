import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { roleCertificatesResult } from "./result.ts";

const leaf = { node_id: 9, scope: "role-metrics", version: "1234567890abcdef" };
const completed = () => ({
    status: "succeeded",
    outputs: { result: { ready: true, ...leaf } },
});
Deno.test("certificate contribution requires the exact nested admitted child receipt", () => {
    const input = {
        leaves: [leaf],
        prefix: "cert_leaf",
        outcomes: { "cert_leaf-0": completed() },
    };
    assertEquals(roleCertificatesResult(input), {
        ready: true,
        publication_phases: [{ phase: "certificates_published", node_id: 9 }],
    });
    for (
        const result of [
            { status: "skipped", outputs: completed().outputs },
            { status: "failed", outputs: completed().outputs },
            { status: "succeeded", outputs: completed().outputs.result },
            {
                status: "succeeded",
                outputs: { result: { ...leaf, ready: true, node_id: 10 } },
            },
            {
                status: "succeeded",
                outputs: { result: { ...leaf, ready: true, version: "wrong" } },
            },
            {
                status: "succeeded",
                outputs: {
                    result: { ...leaf, ready: true, scope: "role-analytics" },
                },
            },
        ]
    ) {
        assertThrows(() =>
            roleCertificatesResult({
                ...input,
                outcomes: { "cert_leaf-0": result },
            })
        );
    }
    assertThrows(() => roleCertificatesResult({ ...input, outcomes: {} }));
    assertThrows(() =>
        roleCertificatesResult({
            ...input,
            outcomes: { ...input.outcomes, "cert_leaf-1": completed() },
        })
    );
});
Deno.test("empty certificate plan proves zero work and multiple leaves share one Node contribution", () => {
    assertEquals(
        roleCertificatesResult({
            leaves: [],
            prefix: "cert_leaf",
            outcomes: {},
        }),
        {
            ready: true,
            publication_phases: [],
        },
    );
    const second = { ...leaf, scope: "role-analytics" };
    assertEquals(
        roleCertificatesResult({
            leaves: [leaf, second],
            prefix: "cert_leaf",
            outcomes: {
                "cert_leaf-0": completed(),
                "cert_leaf-1": {
                    status: "succeeded",
                    outputs: { result: { ready: true, ...second } },
                },
            },
        }).publication_phases,
        [{ phase: "certificates_published", node_id: 9 }],
    );
});
