import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
    networkCommandInputs,
    publicPeerKey,
    restorationView,
} from "./network_command_inputs.ts";

const key = "AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=";
const intent = {
    schema_version: 1,
    node_id: 13,
    hub_id: 1,
    agent_sha256: "a".repeat(64),
    existing_key: key,
    architecture: "x86_64",
    peer: {
        server_public_key: key,
        endpoint: "vpn.example.test:51820",
        address: "10.44.0.13/24",
        subnet: "10.44.0.0/24",
        dns_server: "10.44.0.1",
        domains: ["orbit"],
        operator_dns: null,
        retain: true,
    },
    trust: { desired: [], remove: [], enable: false },
};

Deno.test("command input shaping retains every non-secret network field", () => {
    assertEquals(
        JSON.parse(networkCommandInputs(intent, 13).network_json),
        intent,
    );
});
Deno.test("command intent refuses a foreign Node or unproved builtin proxy target", () => {
    assertThrows(() => networkCommandInputs(intent, 12));
    for (const node_id of [1, 18]) {
        assertThrows(() =>
            networkCommandInputs({ ...intent, node_id }, node_id)
        );
    }
});
Deno.test("command input refuses replacement keys, malformed DNS and private fields", () => {
    for (
        const invalid of [
            { ...intent, existing_key: null },
            { ...intent, private_key: "private-sentinel" },
            {
                ...intent,
                peer: { ...intent.peer, domains: ["orbit", "orbit"] },
            },
            {
                ...intent,
                peer: { ...intent.peer, domains: ["z.test", "a.test"] },
            },
            { ...intent, peer: { ...intent.peer, dns_server: "not-an-ip" } },
        ]
    ) assertThrows(() => networkCommandInputs(invalid, 13));
});
Deno.test("public key shaping refuses diagnostics or a private key field", () => {
    assertEquals(
        publicPeerKey(JSON.stringify({ public_key: key })),
        { public_key: key },
    );
    for (
        const value of [
            { public_key: key, private_key: "private-sentinel" },
            { error_code: "wireguard.peer_key_failed" },
            [],
            null,
        ]
    ) {
        assertThrows(() => publicPeerKey(JSON.stringify(value)));
    }
});

Deno.test("missing restoration metadata provides safe empty native foreach inputs", () => {
    assertEquals(restorationView('{"present":false}'), {
        present: false,
        active: false,
        enabled: "disabled",
        settings: [],
    });
});
Deno.test("restoration output excludes private before-image fields", () => {
    assertThrows(() =>
        restorationView(
            JSON.stringify({
                present: true,
                active: true,
                enabled: "enabled",
                settings: [],
                config: "private-sentinel",
            }),
        )
    );
    const result = restorationView(
        JSON.stringify({
            present: true,
            active: true,
            enabled: "enabled",
            settings: [{
                link: "orbit",
                property: "dns",
                value: ["10.44.0.1"],
            }],
        }),
    );
    assertEquals(JSON.parse(result.settings[0].json), {
        link: "orbit",
        property: "dns",
        value: ["10.44.0.1"],
    });
});
