import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { dnsDecision, dnsResult, publicKey } from "./assets.ts";
import { workflow } from "./workflows.ts";
const key = "A".repeat(43) + "=";
const policy = {
    server: "10.44.0.1",
    dns_server: "10.44.0.1",
    public_key: key,
    domains: ["orbit"],
};
const observation = {
    state: "orbit\n10.44.0.1\norbit",
    hooks: String
        .raw`PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \~orbit`,
    dns: "Link 2 (orbit): 10.44.0.1",
    domains: "Link 2 (orbit): ~orbit",
    default_route: "Link 2 (orbit): no",
    uplink: true,
    old_link: "orbit",
    public_key: key,
};
Deno.test("matching DNS policy skips effects and native result strips helper fields", () => {
    assertEquals(dnsDecision(JSON.stringify(observation), policy), {
        changed: false,
        old_link: "orbit",
    });
    const result = dnsResult(JSON.stringify(observation), policy);
    assertEquals(Object.hasOwn(result, "old_link"), false);
    assertEquals(result.uplink, true);
});
Deno.test("drift on old uplink is explicit; failed final comparison never reports success", () => {
    const before = {
        ...observation,
        state: "eth0\n10.44.0.1\nold",
        old_link: "eth0",
    };
    assertEquals(dnsDecision(JSON.stringify(before), policy), {
        changed: true,
        old_link: "eth0",
    });
    for (
        const delta of [{ uplink: false }, { hooks: "bad" }, {
            domains: "Link 2 (orbit): ~.",
        }, { public_key: "B".repeat(43) + "=" }]
    ) {
        assertThrows(() =>
            dnsResult(JSON.stringify({ ...observation, ...delta }), policy)
        );
    }
});
Deno.test("public peer keys must match existing identity and not collide", () => {
    assertEquals(publicKey(JSON.stringify({ public_key: key }), null, []), key);
    assertThrows(() =>
        publicKey(JSON.stringify({ public_key: key }), "B".repeat(43) + "=", [])
    );
    assertThrows(() =>
        publicKey(JSON.stringify({ public_key: key }), null, [key])
    );
});
Deno.test("both direct roots select209 exclusively and expose one action per SSH step", () => {
    for (const network of [true, false]) {
        const w = workflow(network);
        assertEquals(w.labels, { role: "swamp" });
        for (const step of w.jobs[0].steps) {
            assertEquals(step.labels, { role: "swamp" });
            assertEquals(Object.hasOwn(step, "target"), false);
            const task = step.task as {
                behaviorType: string;
                methodName: string;
            };
            assertEquals(
                ["@nckrtl/network-command", "@swamp/ssh"].includes(
                    task.behaviorType,
                ),
                true,
            );
        }
    }
});

Deno.test("roleless operator DNS accepts its admitted private-only uplink exception", () => {
    const privateOnly = { ...observation, uplink: false };
    assertThrows(() => dnsDecision(JSON.stringify(privateOnly), policy));
    assertEquals(
        dnsDecision(JSON.stringify(privateOnly), policy, false).changed,
        false,
    );
    assertEquals(
        dnsResult(JSON.stringify(privateOnly), policy, false).uplink,
        false,
    );
});
