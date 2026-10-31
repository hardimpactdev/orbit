import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { preparedRequest, preparingIntent } from "./host-intent.ts";
const leaf = {
    node_id: 9,
    route_id: 19,
    user: "orbit",
    agent_sha256: "a".repeat(64),
    domain: "analytics.example.test",
    scope: "route-19-router",
    version: "b".repeat(16),
    root_hash: "c".repeat(64),
};
Deno.test("request output carries only public CSR and exact admitted identity", () => {
    const csr =
        "-----BEGIN CERTIFICATE REQUEST-----\nYWJj\n-----END CERTIFICATE REQUEST-----\n";
    assertEquals(
        preparedRequest(
            leaf,
            JSON.stringify({ state: "request", request_base64: btoa(csr) }),
        ),
        {
            node_id: 9,
            scope: leaf.scope,
            state: "request",
            request_base64: btoa(csr),
        },
    );
});
Deno.test("a current leaf has no CSR or signing request", () => {
    assertEquals(preparedRequest(leaf, '{"state":"current"}'), {
        node_id: 9,
        scope: leaf.scope,
        state: "current",
    });
});
Deno.test("missing version or root and a foreign scope refuse before output", () => {
    for (
        const changed of [{ ...leaf, version: undefined }, {
            ...leaf,
            root_hash: undefined,
        }, { ...leaf, scope: "route-20-router" }]
    ) {
        assertThrows(() => preparedRequest(changed, '{"state":"current"}'));
    }
});
Deno.test("private keys, extra fields and malformed request output never become resources", () => {
    for (
        const value of [
            {
                state: "request",
                request_base64:
                    "-----BEGIN PRIVATE KEY-----\nYWJj\n-----END PRIVATE KEY-----",
            },
            { state: "current", credential: "private-value" },
            { state: "request", request_base64: "not a CSR" },
        ]
    ) {
        assertThrows(() => preparedRequest(leaf, JSON.stringify(value)));
    }
});
Deno.test("canonical bounded refusals preserve their public code only", () => {
    assertThrows(
        () => preparedRequest(leaf, '{"error_code":"certificate.leaf_failed"}'),
        Error,
        "certificate.leaf_failed",
    );
    assertThrows(() =>
        preparedRequest(
            leaf,
            '{"error_code":"invalid code with private bytes"}',
        )
    );
});

Deno.test("malformed command diagnostics never expose raw stdout fragments", () => {
    const sentinel = "private-command-output-sentinel";
    try {
        preparedRequest(leaf, `{"unexpected":"${sentinel}`);
        throw new Error("Expected refusal");
    } catch (error) {
        assertEquals(
            String(error),
            "Error: analytics.certificate_response_invalid",
        );
    }
});

Deno.test("request intent is canonical public material without Route-only metadata", () => {
    const intent = preparingIntent({ ...leaf, in_use: false });
    assertEquals(intent, {
        node_id: leaf.node_id,
        user: leaf.user,
        agent_sha256: leaf.agent_sha256,
        domain: leaf.domain,
        scope: leaf.scope,
        version: leaf.version,
        root_hash: leaf.root_hash,
    });
    assertThrows(() => preparingIntent({ ...leaf, scope: "route-20-router" }));
});
