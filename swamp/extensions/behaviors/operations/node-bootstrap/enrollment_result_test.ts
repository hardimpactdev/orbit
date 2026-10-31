import { assertEquals } from "jsr:@std/assert@1";
import { behavior, enrollmentResult } from "./enrollment_result.ts";

const input = {
    node_id: 31,
    name: "dev-enrollment",
    mode: "linux",
    forward: { bootstrap: 1, network: 1, roles: 2, routes: 1 },
    restore: {
        restore_peer: 1,
        restore_hub: 1,
        restore_roles: 2,
        restore_routes: 1,
    },
    outcomes: {
        "bootstrap-0": { status: "succeeded" },
        "network-0": { status: "succeeded" },
        "roles-0": { status: "succeeded" },
        "roles-1": { status: "succeeded" },
        "routes-0": { status: "succeeded" },
    },
    observation: {
        architecture: "x86_64",
        host_type: "ssh-ed25519",
        public_key: "AAAA",
        host_key_fingerprint: "SHA256:admitted",
    },
    wireguard_public_key: "A".repeat(43) + "=",
    routes_required: true,
    route_result: {
        ready: true,
        restored: false,
        routes: [],
        contributions: { route_publish: 1 },
    },
};
const result = (overrides: Record<string, unknown> = {}) =>
    JSON.parse(enrollmentResult({ ...input, ...overrides }).stdout);

Deno.test("enrollment completes only after every expanded native child and physical result", () => {
    assertEquals(result().enrolled, true);
    assertEquals(result().restored, false);
    assertEquals(result().wireguard_public_key, input.wireguard_public_key);
    for (const status of ["failed", "skipped", "running"]) {
        assertEquals(
            result({ outcomes: { ...input.outcomes, "roles-1": { status } } })
                .enrolled,
            false,
        );
    }
    const { "roles-1": _omitted, ...missing } = input.outcomes;
    assertEquals(result({ outcomes: missing }).enrolled, false);
    assertEquals(result({ observation: null }).enrolled, false);
    assertEquals(result({ wireguard_public_key: null }).enrolled, false);
    assertEquals(result({ route_result: null }).enrolled, false);
    assertEquals(result({ forward: {} }).enrolled, false);
});
Deno.test("whole-parent restoration includes roles and routes after a later failure", () => {
    const outcomes = {
        ...input.outcomes,
        "roles-1": { status: "failed" },
        "restore_peer-0": { status: "succeeded" },
        "restore_hub-0": { status: "succeeded" },
        "restore_roles-0": { status: "succeeded" },
        "restore_roles-1": { status: "succeeded" },
        "restore_routes-0": { status: "succeeded" },
    };
    const restored = {
        outcomes,
        route_result: { ...input.route_result, restored: true },
    };
    assertEquals(result(restored).restored, true);
    assertEquals(result(restored).enrolled, false);
    assertEquals(
        result({
            ...restored,
            outcomes: { ...outcomes, "restore_roles-1": { status: "failed" } },
        }).restored,
        false,
    );
    assertEquals(
        result({ ...restored, route_result: input.route_result }).restored,
        false,
    );
    assertEquals(result({ ...restored, restore: {} }).restored, false);
});
Deno.test("TLD-only completion needs no new host observation or peer key", () => {
    assertEquals(
        result({
            mode: "tld_only",
            observation: null,
            wireguard_public_key: null,
        }).enrolled,
        true,
    );
});
Deno.test("native terminal output retains bounded public facts without raw child outputs", async () => {
    const writes: unknown[] = [];
    const output = await behavior.methods.read.execute(
        {
            ...input,
            outcomes: {
                ...input.outcomes,
                "bootstrap-0": {
                    status: "succeeded",
                    outputs: { script_body: "public script" },
                },
            },
        } as Parameters<typeof behavior.methods.read.execute>[0],
        {
            writeResource: (...args) => Promise.resolve(writes.push(args)),
        },
    );
    assertEquals(output.stdout.includes("script_body"), false);
    assertEquals(writes, [["terminal", "terminal", output]]);
});
