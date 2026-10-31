import { z } from "npm:zod@4.4.3";

const Caddy = z.object({
    content: z.string(),
    version: z.string().regex(/^[a-f0-9]{32}$/),
    listen_addresses: z.array(z.ipv4()).max(1024),
    unserved: z.boolean(),
}).strict();
const Publication = z.object({
    node_id: z.number().int().positive(),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    caddy: Caddy,
}).strict();
const Args = z.object({
    node_id: z.number().int().positive(),
    target_node_id: z.number().int().positive(),
    run_id: z.uuid(),
    publication: Publication,
}).strict();
const Output = z.object({
    metadata_json: z.string(),
    chunks: z.array(z.object({ json: z.string() })),
});

/** Pure public-data shaping. No host commands, Gateway client or private material. */
export async function prepareCaddyInput(raw: unknown) {
    const { node_id, target_node_id, run_id, publication } = Args.parse(raw);
    if (
        publication.node_id !== target_node_id || target_node_id === 1 ||
        target_node_id === 18
    ) {
        throw new Error("caddy.placement_unavailable");
    }
    const value = publication.caddy;
    const bytes = new TextEncoder().encode(value.content);
    const fullDigest = Array.from(
        new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
        (byte) => byte.toString(16).padStart(2, "0"),
    ).join("");
    if (
        bytes.length > 4194304 ||
        !value.content.startsWith("# Managed by Orbit: Node Caddy build\n") ||
        fullDigest.slice(0, 32) !== value.version
    ) throw new Error("caddy.input_invalid");
    // Linux bounds each individual environment value. Fan-out writes disjoint
    // public chunks so the established 4 MiB config limit remains usable.
    const chunks: { json: string }[] = [];
    for (let offset = 0; offset < bytes.length; offset += 32768) {
        const data = btoa(
            Array.from(
                bytes.subarray(offset, offset + 32768),
                (byte) => String.fromCharCode(byte),
            ).join(""),
        );
        chunks.push({ json: JSON.stringify({ offset, data }) });
    }
    return {
        metadata_json: JSON.stringify({
            node_id: target_node_id,
            root_node_id: node_id,
            run_id,
            version: value.version,
            sha256: fullDigest,
            content_bytes: bytes.length,
            listen_addresses: value.listen_addresses,
            unserved: value.unserved,
        }),
        chunks,
    };
}

const Observation = z.object({
    prior_digest: z.string().regex(/^[a-f0-9]{64}$/).nullable(),
    unchanged: z.boolean(),
    publish: z.boolean(),
    withdraw: z.boolean(),
    active: z.boolean(),
    enabled: z.boolean(),
}).strict();

type Context = {
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/caddy-public-input",
    version: "2026.10.31.1",
    resources: {
        observation: {
            description: "Public pre-publication host observation",
            schema: Observation,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        input: {
            description:
                "Admitted public Caddy metadata and bounded content chunks",
            schema: Output,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        observe: {
            description: "Shape public shell observation without host I/O",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "observation",
                        "observation",
                        Observation.parse(JSON.parse(args.stdout)),
                    ),
                ],
            }),
        },
        prepare: {
            description:
                "Shape the complete public publication input for shell actions",
            arguments: Args,
            execute: async (args: unknown, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "input",
                        "input",
                        await prepareCaddyInput(args),
                    ),
                ],
            }),
        },
    },
};
