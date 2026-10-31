import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Connection = z.object({
    nodeHost: z.string().min(1),
    nodeUser: z.string().min(1),
    nodePort: z.number().int().positive(),
    nodeIdentityFile: z.string().startsWith("/"),
}).passthrough();
const Publication = z.object({
    node_id: Id,
    control_node_id: z.literal(209),
    connection: Connection,
    host_keys: z.array(z.string().min(1)).min(1).nullable(),
    before: z.record(z.string(), z.unknown()),
    after: z.record(z.string(), z.unknown()),
}).passthrough();
export const PublicationBindingInput = z.object({
    publications: z.array(Publication),
    candidate: z.object({
        node_id: Id,
        wireguard_ip: z.ipv4(),
        user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    }).nullable(),
    full_globals: z.record(z.string(), z.unknown()),
}).strict();
export const PublicationBindingResult = z.object({
    publications: z.array(Publication.extend({
        host_keys: z.array(z.string().min(1)).min(1),
    })),
}).strict();
const BoundGlobals = z.object({
    transport: z.object({
        kind: z.literal("ssh"),
        user: z.string(),
        port: z.literal(22),
        identityFile: z.string(),
    }).passthrough(),
    hosts: z.array(
        z.object({
            name: z.string(),
            address: z.string(),
            transport: z.object({
                hostKeys: z.array(
                    z.string().regex(
                        /^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(?:256|384|521)) [A-Za-z0-9+/]+={0,3}$/,
                    ),
                ).min(1),
            }).passthrough(),
        }).passthrough(),
    ),
}).passthrough();

/** Bind only the explicit candidate row to the parent's already fingerprint-verified pins. */
export function bindRolePublications(raw: unknown) {
    const args = PublicationBindingInput.parse(raw);
    const pending = args.publications.filter((row) => row.host_keys === null);
    if (pending.length === 0) {
        return PublicationBindingResult.parse({
            publications: args.publications,
        });
    }
    const candidate = args.candidate;
    if (candidate === null || pending.length !== 1) {
        throw new Error("node.role_publication_binding_invalid");
    }
    const globals = BoundGlobals.parse(args.full_globals);
    const targets = globals.hosts.filter((host) => host.name === "target");
    const row = pending[0];
    const target = targets[0];
    if (
        targets.length !== 1 || row.node_id !== candidate.node_id ||
        row.connection.nodeHost !== candidate.wireguard_ip ||
        row.connection.nodeUser !== candidate.user ||
        row.connection.nodePort !== 22 ||
        globals.transport.user !== candidate.user ||
        globals.transport.identityFile !== row.connection.nodeIdentityFile ||
        target.address !== candidate.wireguard_ip
    ) throw new Error("node.role_publication_binding_invalid");
    return PublicationBindingResult.parse({
        publications: args.publications.map((publication) =>
            publication.host_keys === null
                ? { ...publication, host_keys: target.transport.hostKeys }
                : publication
        ),
    });
}

/** Pure placed binding; no host action runs before this contribution succeeds. */
export function rolePublicationBindingSteps(
    name: string,
    publicationsExpr: string,
    candidateExpr: string,
    fullGlobals: Record<string, unknown>,
    dependency?: string,
    guard?: string,
): Record<string, unknown>[] {
    return [{
        name,
        target: "orbit-node-209",
        labels: { node: "209" },
        ...(dependency
            ? {
                dependsOn: [{
                    step: dependency,
                    condition: { type: "succeeded" },
                }],
            }
            : {}),
        ...(guard ? { guard: "${{ " + guard + " }}" } : {}),
        task: {
            type: "behavior",
            behaviorType: "@nckrtl/node-role-converge-command",
            behaviorName: name,
            methodName: "publication_binding",
            inputs: {
                publications: "${{ " + publicationsExpr + " }}",
                candidate: "${{ " + candidateExpr + " }}",
                full_globals: fullGlobals,
            },
        },
    }];
}
