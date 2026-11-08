import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
    behavior,
    dnsDecision,
    dnsResult,
    INSPECT,
    inspection,
    publicKey,
} from "./assets.ts";
import { workflow } from "./workflows.ts";
const key = "A".repeat(43) + "=";
const policy = {
    server: "10.44.0.1",
    dns_server: "10.44.0.1",
    public_key: key,
    domains: ["orbit"],
};
const hook = String
    .raw`PostUp = resolvectl dns %i 10.44.0.1; resolvectl domain %i \~orbit`;
/** The six sections the read step prints, in INSPECT order. */
const observed = (delta: Partial<Record<string, string>> = {}) => {
    const parts = {
        key,
        servers:
            "Global:\nLink 2 (orbit): 10.44.0.1\nLink 3 (eth0): 192.0.2.53",
        domains: "Link 2 (orbit): ~orbit",
        route: "Link 2 (orbit): no",
        state: "orbit\n10.44.0.1\norbit",
        hooks: hook,
        ...delta,
    };
    return [
        parts.key,
        parts.servers,
        parts.domains,
        parts.route,
        parts.state,
        parts.hooks,
    ]
        .join("\n@@\n") + "\n";
};
Deno.test("the read step prints six sections the parser splits", () => {
    assertEquals(INSPECT.split(" && echo @@ && ").length, 6);
    assertEquals(inspection(observed(), "10.44.0.1"), {
        state: "orbit\n10.44.0.1\norbit",
        hooks: hook,
        dns: "Link 2 (orbit): 10.44.0.1",
        domains: "Link 2 (orbit): ~orbit",
        default_route: "Link 2 (orbit): no",
        uplink: true,
        old_link: "orbit",
        public_key: key,
    });
    assertThrows(() => inspection(key + "\n@@\n", "10.44.0.1"));
});
Deno.test("the uplink ignores the orbit link, loopback, the VPN server and the old marker server", () => {
    for (
        const servers of [
            "Global:\nLink 2 (orbit): 10.44.0.1",
            "Global: 127.0.0.53\nLink 2 (orbit): 10.44.0.1",
            "Link 3 (eth0): 10.44.0.1 10.44.0.9%eth0",
        ]
    ) {
        assertEquals(
            inspection(
                observed({ servers, state: "eth0\n10.44.0.9\norbit" }),
                "10.44.0.1",
            ).uplink,
            false,
        );
    }
    assertEquals(
        inspection(
            observed({ servers: "Link 3 (eth0): 192.0.2.53#dns.example" }),
            "10.44.0.1",
        ).uplink,
        true,
    );
});
Deno.test("a missing marker means the orbit link; an unsafe marker link is refused", () => {
    assertEquals(
        inspection(observed({ state: "" }), "10.44.0.1").old_link,
        "orbit",
    );
    assertThrows(() =>
        inspection(observed({ state: "eth0;rm\n10.44.0.1" }), "10.44.0.1")
    );
});
Deno.test("matching DNS policy skips effects and native result strips helper fields", () => {
    assertEquals(dnsDecision(observed(), policy), {
        changed: false,
        old_link: "orbit",
    });
    const result = dnsResult(observed(), policy);
    assertEquals(Object.hasOwn(result, "old_link"), false);
    assertEquals(Object.hasOwn(result, "public_key"), false);
    assertEquals(result.uplink, true);
});
Deno.test("drift on old uplink is explicit; failed final comparison never reports success", () => {
    assertEquals(
        dnsDecision(observed({ state: "eth0\n10.44.0.1\nold" }), policy),
        {
            changed: true,
            old_link: "eth0",
        },
    );
    for (
        const delta of [
            { servers: "Link 2 (orbit): 10.44.0.1" },
            { hooks: "bad" },
            { domains: "Link 2 (orbit): ~." },
            { key: "B".repeat(43) + "=" },
        ]
    ) {
        assertThrows(() => dnsResult(observed(delta), policy));
    }
});
Deno.test("public peer keys must match existing identity and not collide", () => {
    assertEquals(publicKey(key + "\n", null, []), key);
    assertThrows(() => publicKey(key, "B".repeat(43) + "=", []));
    assertThrows(() => publicKey(key, null, [key]));
    assertThrows(() => publicKey("not a key", null, []));
});
Deno.test("both direct roots keep Swamp placement for native children and machine phases", () => {
    for (const network of [true, false]) {
        const w = workflow(network);
        assertEquals(w.labels, { role: "swamp" });
        for (const step of w.jobs[0].steps) {
            assertEquals(step.labels, { role: "swamp" });
            assertEquals(Object.hasOwn(step, "target"), false);
            const task = step.task as {
                type: string;
                behaviorType: string;
                methodName: string;
                workflowIdOrName?: string;
            };
            if (task.type === "workflow") {
                assertEquals(task.workflowIdOrName, "operation-fleet-peer-dns");
                continue;
            }
            assertEquals(
                task.behaviorType === "@nckrtl/network-command" ||
                    task.behaviorType === "@nckrtl/ansible-input" ||
                    task.behaviorType === "command/shell" ||
                    task.behaviorType === "@swamp/ssh" &&
                        task.methodName === "exec" ||
                    task.behaviorType.startsWith("@swamp/cfgmgmt/"),
                true,
                step.name as string,
            );
        }
    }
});
Deno.test("no WireGuard file content carries a private key", () => {
    const text = JSON.stringify(workflow(true));
    assertEquals(text.includes("PrivateKey = %s"), true);
    for (const step of workflow(true).jobs[0].steps) {
        const args =
            (step.task as { globalArgs?: { content?: string } }).globalArgs;
        if (args?.content) {
            assertEquals(args.content.includes("PrivateKey"), false);
        }
    }
});
Deno.test("roleless operator DNS accepts its admitted private-only uplink exception", () => {
    const privateOnly = observed({ servers: "Link 2 (orbit): 10.44.0.1" });
    assertThrows(() => dnsDecision(privateOnly, policy));
    assertEquals(dnsDecision(privateOnly, policy, false).changed, false);
    assertEquals(dnsResult(privateOnly, policy, false).uplink, false);
});

Deno.test("network decision repairs DNS drift without a digest change and skips a compliant hub", async () => {
    const decisions: unknown[] = [];
    const ctx = {
        writeResource: (_spec: string, _name: string, value: unknown) => {
            decisions.push(value);
            return Promise.resolve({});
        },
    };
    const input = {
        required: false,
        observations: [{ public_key: key, port: null, exact: true, peers: [] }],
        stdout: `interface: orbit\n  public key: ${key}\n`,
        hub_stdout: "",
        addresses: "",
        dns_stdout: observed(),
        policy: [policy],
    };
    await behavior.methods.compare.execute(input, ctx);
    await behavior.methods.compare.execute({
        ...input,
        dns_stdout: observed({ domains: "Link 2 (orbit): ~old" }),
    }, ctx);
    await behavior.methods.compare.execute({
        ...input,
        policy: [],
        dns_stdout: "",
    }, ctx);
    await behavior.methods.compare.execute({
        ...input,
        policy: [],
        required: true,
    }, ctx);
    assertEquals(decisions, [{ changed: false }, { changed: true }, {
        changed: false,
    }, { changed: true }]);
});
