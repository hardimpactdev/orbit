import { z } from "npm:zod@4.4.3";
import { Leaf } from "./input.ts";
import { CertificateRequestRecord, signCertificateBatch } from "./signing.ts";

const Leaves = z.array(Leaf).min(1).max(256).refine((leaves) =>
    leaves.every((leaf) =>
        leaf.node_id === leaves[0].node_id &&
        leaf.root_hash === leaves[0].root_hash
    ) && new Set(leaves.map((leaf) => leaf.scope)).size === leaves.length
);
const Read = z.object({ node_id: z.number().int().positive(), leaves: Leaves })
    .strict();
const Requests = z.array(CertificateRequestRecord).min(1).max(256);
const Inspected = z.object({
    node_id: Leaf.shape.node_id,
    scope: Leaf.shape.scope,
    version: Leaf.shape.version,
    present: z.literal(true),
}).strict();
const retained = <T>(schema: T) => ({
    schema,
    lifetime: "infinite",
    garbageCollection: 10,
});
type Context = {
    extensionFile(path: string): string | Promise<string>;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
function requestsFor(leaves: z.infer<typeof Leaves>, raw: unknown) {
    const requests = Requests.parse(raw);
    const seen = new Set<string>();
    for (const request of requests) {
        if (
            seen.has(request.scope) ||
            !leaves.some((leaf) =>
                leaf.node_id === request.node_id && leaf.scope === request.scope
            )
        ) throw new Error("certificate.request_batch_mismatch");
        seen.add(request.scope);
        if (request.state === "request") {
            const pem = atob(request.request_base64);
            if (
                !pem.startsWith("-----BEGIN CERTIFICATE REQUEST-----") ||
                pem.includes("PRIVATE KEY")
            ) throw new Error("certificate.request_invalid");
        }
    }
    if (requests.length !== leaves.length) {
        throw new Error("certificate.request_batch_mismatch");
    }
    return requests;
}

/** Pure Node batch metadata and public result validation; native steps own effects. */
export const behavior = {
    type: "@nckrtl/node-certificate-assets",
    version: "2026.11.01.1",
    resources: {
        assets: retained(z.object({
            input_json: z.string(),
            scripts_directory: z.string(),
        })),
        requests: retained(z.object({ requests: Requests })),
        signed: retained(z.object({ publish_json: z.string() })),
        completion: retained(z.object({
            ready: z.literal(true),
            node_id: Leaf.shape.node_id,
            leaves: z.array(Inspected).min(1).max(256),
        })),
    },
    methods: {
        read: {
            description: "Resolve shared scripts once for one admitted Node",
            arguments: Read,
            execute: async (raw: z.infer<typeof Read>, ctx: Context) => {
                const { node_id, leaves } = Read.parse(raw);
                if (leaves[0].node_id !== node_id) {
                    throw new Error("certificate.node_placement_unavailable");
                }
                const script = await ctx.extensionFile("scripts/node_batch.py");
                const result = {
                    input_json: JSON.stringify({ leaves }),
                    scripts_directory: script.replace(/\/[^/]+$/, ""),
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        requests: {
            description:
                "Account for every current leaf or public CSR in the Node batch",
            arguments: z.object({
                leaves: Leaves,
                stdout: z.string().max(16_777_216),
            }).strict(),
            execute: async (
                args: { leaves: z.infer<typeof Leaves>; stdout: string },
                ctx: Context,
            ) => {
                const leaves = Leaves.parse(args.leaves);
                const { requests } = z.object({ requests: Requests }).strict()
                    .parse(JSON.parse(args.stdout));
                const result = { requests: requestsFor(leaves, requests) };
                await ctx.writeResource("requests", "requests", result);
                return result;
            },
        },
        signed: {
            description:
                "Decode the existing Gateway batch signer without private material",
            arguments: z.object({
                leaves: Leaves,
                requests: Requests,
                stdout: z.string().max(16_777_216),
            }).strict(),
            execute: async (
                args: {
                    leaves: z.infer<typeof Leaves>;
                    requests: z.infer<typeof Requests>;
                    stdout: string;
                },
                ctx: Context,
            ) => {
                const leaves = Leaves.parse(args.leaves);
                const requests = requestsFor(leaves, args.requests);
                const signed = signCertificateBatch(
                    leaves,
                    requests,
                    args.stdout,
                );
                const result = {
                    publish_json: JSON.stringify({ leaves, ...signed }),
                };
                await ctx.writeResource("signed", "signed", result);
                return result;
            },
        },
        result: {
            description:
                "Prove every admitted Node, scope and version before parent cutover",
            arguments: z.object({
                leaves: Leaves,
                stdout: z.string().max(16_777_216),
            }).strict(),
            execute: async (
                args: { leaves: z.infer<typeof Leaves>; stdout: string },
                ctx: Context,
            ) => {
                const leaves = Leaves.parse(args.leaves);
                const result = z.object({
                    leaves: z.array(Inspected).min(1).max(256),
                }).strict().parse(JSON.parse(args.stdout));
                const seen = new Set<string>();
                for (const item of result.leaves) {
                    if (
                        seen.has(item.scope) ||
                        !leaves.some((leaf) =>
                            leaf.node_id === item.node_id &&
                            leaf.scope === item.scope &&
                            leaf.version === item.version
                        )
                    ) {
                        throw new Error("certificate.presence_batch_mismatch");
                    }
                    seen.add(item.scope);
                }
                if (result.leaves.length !== leaves.length) {
                    throw new Error("certificate.presence_batch_mismatch");
                }
                const completion = {
                    ready: true as const,
                    node_id: leaves[0].node_id,
                    ...result,
                };
                await ctx.writeResource("completion", "completion", completion);
                return completion;
            },
        },
    },
};
