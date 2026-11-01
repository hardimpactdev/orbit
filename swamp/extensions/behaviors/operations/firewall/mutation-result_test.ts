import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { mutationResult } from "./mutation-result.ts";
Deno.test("mutation result requires the admitted rule state from an active UFW observation", () => {
    for (const status of ["compliant", "applied"]) {
        assertEquals(
            mutationResult({
                ensure: "present",
                state: {
                    status,
                    current: { backend: "ufw", ruleExists: true },
                },
            }),
            { backend: "active" },
        );
        assertEquals(
            mutationResult({
                ensure: "absent",
                state: {
                    status,
                    current: { backend: "ufw", ruleExists: false },
                },
            }),
            { backend: "absent" },
        );
    }
    for (const [ensure, ruleExists] of [["present", false], ["absent", true]]) {
        assertThrows(
            () =>
                mutationResult({
                    ensure,
                    state: {
                        status: "applied",
                        current: { backend: "ufw", ruleExists },
                    },
                }),
            Error,
            "firewall.verify_failed",
        );
    }
    assertThrows(() =>
        mutationResult({
            ensure: "present",
            state: {
                status: "failed",
                current: { backend: "ufw", ruleExists: true },
            },
        })
    );
    assertThrows(() =>
        mutationResult({
            ensure: "present",
            state: {
                status: "applied",
                current: { backend: "iptables", ruleExists: true },
            },
        })
    );
});

Deno.test("published pins require an exact target entry and reject stale or different keys", async () => {
    const { requirePublishedPins } = await import("./mutation-result.ts");
    const intent = {
        ssh: {
            target: {
                host: "10.44.0.9",
                port: 22,
                host_keys: ["ssh-ed25519 admitted"],
            },
        },
        rule: {
            node_id: 9,
            name: "web",
            action: "allow",
            source: "any",
            port: "443",
            protocol: "tcp",
        },
        tuples: [{ port: 443, ensure: "present" }],
        ensure: "present",
    };
    assertEquals(
        requirePublishedPins(intent, "10.44.0.9 ssh-ed25519 admitted\n"),
        { ready: true },
    );
    for (
        const snapshot of [
            "",
            "10.44.0.99 ssh-ed25519 admitted\n",
            "#10.44.0.9 ssh-ed25519 admitted\n",
            "10.44.0.9 ssh-ed25519 different\n",
            "10.44.0.9 ssh-ed25519 admitted\n10.44.0.9 ssh-ed25519 stale\n",
        ]
    ) {
        assertThrows(
            () => requirePublishedPins(intent, snapshot),
            Error,
            "firewall.known_hosts_unpublished",
        );
    }
});

Deno.test("native completion requires every expected compliant tuple and no residual selected tag", async () => {
    const { result } = await import("./mutation-result.ts");
    const intent = {
        ssh: {
            target: {
                host: "10.44.0.9",
                port: 22,
                host_keys: ["ssh-ed25519 admitted"],
            },
        },
        rule: {
            node_id: 9,
            name: "web",
            action: "allow",
            source: "any",
            port: "443:444",
            protocol: "tcp",
        },
        tuples: [{ port: 443, ensure: "absent" }, {
            port: 444,
            ensure: "present",
        }],
        ensure: "absent",
    };
    const outcomes = {
        "verify-any-0": {
            status: "succeeded",
            outputs: {
                status: "compliant",
                current: { backend: "ufw", ruleExists: false },
            },
        },
        "verify-any-1": {
            status: "succeeded",
            outputs: {
                status: "compliant",
                current: { backend: "ufw", ruleExists: true },
            },
        },
    };
    assertEquals(result({ intent, stdout: "Status: active\n", outcomes }), {
        backend: "absent",
    });
    assertThrows(() =>
        result({
            intent,
            stdout: "Status: active\n",
            outcomes: { "verify-any-0": outcomes["verify-any-0"] },
        })
    );
    assertThrows(
        () => result({ intent, stdout: "Status: inactive\n", outcomes }),
        Error,
        "firewall.backend_inactive",
    );
    assertThrows(
        () =>
            result({
                intent,
                stdout:
                    "Status: active\n[1] 443/tcp ALLOW IN Anywhere # orbit:node:9:firewall:web\n",
                outcomes,
            }),
        Error,
        "firewall.remove_verify_failed",
    );
    assertThrows(() =>
        result({
            intent,
            stdout: "Status: active\n",
            outcomes: { ...outcomes, "verify-any-1": { status: "skipped" } },
        })
    );
});
