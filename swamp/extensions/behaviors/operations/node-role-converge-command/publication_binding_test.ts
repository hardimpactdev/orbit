import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { behavior } from "./assets.ts";
import { rolePublicationBindingSteps } from "./publication_binding.ts";

const candidate = { node_id: 31, wireguard_ip: "10.44.0.31", user: "orbit" };
const pending = {
    node_id: 31,
    control_node_id: 209 as const,
    connection: {
        nodeHost: "10.44.0.31",
        nodeUser: "orbit",
        nodePort: 22,
        nodeIdentityFile: "/approved/identity",
    },
    host_keys: null,
    before: { caddy: { content: "actual" }, php: null, dns: null },
    after: { caddy: { content: "candidate" }, php: null, dns: null },
};
const stored = {
    ...pending,
    node_id: 14,
    connection: { ...pending.connection, nodeHost: "10.44.0.14" },
    host_keys: ["ssh-ed25519 U1RPUkVE"],
};
const globals = {
    name: "enrollment-target-root",
    transport: {
        kind: "ssh",
        user: "orbit",
        port: 22,
        identityFile: "/approved/identity",
    },
    hosts: [{
        name: "target",
        address: "10.44.0.31",
        transport: { hostKeys: ["ssh-ed25519 VkVSSUZJRUQ="] },
    }],
};
const context = {
    extensionFile: (name: string) => name,
    writeResource: () => Promise.resolve(),
};

Deno.test("publication binding uses verified same-candidate globals and preserves every stored participant", async () => {
    const result = await behavior.methods.publication_binding.execute({
        publications: [pending, stored],
        candidate,
        full_globals: globals,
    }, context);
    assertEquals(result.publications, [
        { ...pending, host_keys: ["ssh-ed25519 VkVSSUZJRUQ="] },
        stored,
    ]);
    assertEquals(pending.host_keys, null);
    assertEquals(
        await behavior.methods.publication_binding.execute({
            publications: [stored],
            candidate: null,
            full_globals: {},
        }, context),
        { publications: [stored] },
    );
});

Deno.test("publication binding refuses wrong identity, target, account, port and missing or empty full pins", async () => {
    const valid = {
        publications: [pending, stored],
        candidate,
        full_globals: globals,
    };
    const cases = [
        { ...valid, candidate: null },
        { ...valid, candidate: { ...candidate, node_id: 32 } },
        { ...valid, candidate: { ...candidate, wireguard_ip: "10.44.0.32" } },
        { ...valid, candidate: { ...candidate, user: "other" } },
        {
            ...valid,
            publications: [{
                ...pending,
                connection: { ...pending.connection, nodePort: 2222 },
            }],
        },
        { ...valid, publications: [pending, { ...stored, host_keys: null }] },
        { ...valid, publications: [{ ...pending, host_keys: [] }] },
        ...[
            {
                ...globals,
                transport: { ...globals.transport, identityFile: "/other" },
            },
            { ...globals, transport: { ...globals.transport, user: "other" } },
            { ...globals, transport: { ...globals.transport, port: 2222 } },
            { ...globals, hosts: [] },
            { ...globals, hosts: [...globals.hosts, ...globals.hosts] },
            {
                ...globals,
                hosts: [{ ...globals.hosts[0], address: "10.44.0.32" }],
            },
            {
                ...globals,
                hosts: [{ ...globals.hosts[0], transport: { hostKeys: [] } }],
            },
            {
                ...globals,
                hosts: [{
                    ...globals.hosts[0],
                    transport: { hostKeys: ["SHA256:only-fingerprint"] },
                }],
            },
        ].map((full_globals) => ({ ...valid, full_globals })),
    ];
    for (const args of cases) {
        await assertRejects(() =>
            behavior.methods.publication_binding.execute(args, context)
        );
    }
});

Deno.test("publication binding composition retains full dynamic bootstrap globals and predecessor", () => {
    const dynamic = {
        ...globals,
        name: '${{ "enrollment-target-"+run.id }}',
        hosts: [{
            name: "target",
            address: "${{ inputs.intent.candidate.wireguard_ip }}",
            transport: {
                hostKeys: "${{ steps.bootstrap_pin.outputs.host_keys }}",
            },
        }],
    };
    const [step] = rolePublicationBindingSteps(
        "bind",
        "inputs.intent.publications",
        "inputs.intent.candidate",
        dynamic,
        "bootstrap_pin",
    );
    assertEquals(step.target, "orbit-node-209");
    assertEquals(step.dependsOn, [{
        step: "bootstrap_pin",
        condition: { type: "succeeded" },
    }]);
    assertEquals(
        (step.task as { inputs: { full_globals: unknown } }).inputs
            .full_globals,
        dynamic,
    );
});
