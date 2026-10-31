import { z } from "npm:zod@4.4.3";

export const PublicationConnection = z.object({
    nodeHost: z.string().min(1),
    nodePort: z.number().int().min(1).max(65535),
    nodeUser: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    nodeIdentityFile: z.string().startsWith("/"),
    become: z.literal(true),
}).strict();
export const CaddyInput = z.object({
    content: z.string().max(4194304),
    version: z.string().regex(/^[a-f0-9]{32}$/),
    listen_addresses: z.array(z.ipv4()).max(1024),
    unserved: z.boolean(),
}).strict();
export const CaddyPublication = z.object({
    node_id: z.number().int().positive(),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    caddy: CaddyInput,
    control_node_id: z.literal(209),
    host_keys: z.array(z.string().min(1)).min(1),
    connection: PublicationConnection,
}).strict();

/** Validate public intent; official SSH owns target effects. */
export async function prepareCaddyInput(raw: unknown) {
    const publication = CaddyPublication.parse(raw);
    const digest = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(publication.caddy.content),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    if (
        !publication.caddy.content.startsWith(
            "# Managed by Orbit: Node Caddy build\n",
        ) ||
        digest.slice(0, 32) !== publication.caddy.version
    ) {
        throw new Error("caddy.input_invalid");
    }
    return publication;
}
export const behavior = {
    type: "@nckrtl/caddy-public-input",
    version: "2026.10.31.3",
    resources: {
        input: {
            schema: CaddyPublication,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        prepare: {
            description:
                "Validate the complete public configuration before applying it",
            arguments: z.object({ publication: CaddyPublication }).strict(),
            execute: async (args: { publication: unknown }, ctx: {
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "input",
                        "input",
                        await prepareCaddyInput(args.publication),
                    ),
                ],
            }),
        },
    },
};
