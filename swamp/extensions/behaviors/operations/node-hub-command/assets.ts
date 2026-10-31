import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Peer = z.object({
    node_id: Id,
    name: z.string().regex(/^[A-Za-z0-9._-]{1,253}$/),
    address: z.ipv4(),
    public_key: Key,
}).strict();
export const HubIntent = z.object({
    schema_version: z.literal(1),
    hub_id: Id,
    hub_address: z.string().regex(/^[0-9.]+\/(?:[0-9]|[12][0-9]|3[0-2])$/),
    port: z.number().int().min(1).max(65535),
    context_node_id: Id,
    inventory: z.array(Peer).max(4096),
    before: Peer.nullable(),
    desired: Peer.omit({ public_key: true }).extend({
        public_key: Key.nullable(),
    }),
}).strict().superRefine((v, ctx) => {
    if (v.desired.address === v.hub_address.split("/")[0]) {
        ctx.addIssue({
            code: "custom",
            message: "A peer cannot claim the hub address",
        });
    }
    if (
        v.hub_id === v.context_node_id ||
        v.desired.node_id !== v.context_node_id ||
        (v.before && v.before.node_id !== v.context_node_id)
    ) {
        ctx.addIssue({
            code: "custom",
            message: "Hub peer ownership differs from the parent context",
        });
    }
    for (const key of ["node_id", "address", "public_key"] as const) {
        if (
            new Set(v.inventory.map((p) => p[key])).size !== v.inventory.length
        ) {
            ctx.addIssue({
                code: "custom",
                message: "Hub inventory is ambiguous",
            });
        }
    }
});
const Prepared = z.object({ node_id: Id, public_key: Key }).strict();
const Mode = z.enum(["publish", "remove", "restore-publish", "restore-remove"]);
const Args = z.object({
    intent: HubIntent,
    mode: Mode,
    prepared_peer: Prepared.nullable().default(null),
}).strict();
export function hubPeerAction(raw: unknown) {
    const { intent, mode, prepared_peer } = Args.parse(raw);
    if (prepared_peer && prepared_peer.node_id !== intent.context_node_id) {
        throw new Error("The prepared key belongs to another Node");
    }
    const removing = mode === "remove" || mode === "restore-remove";
    const key = prepared_peer?.public_key ?? intent.desired.public_key;
    if (!removing && key === null) {
        throw new Error("Publishing the peer requires its prepared public key");
    }
    const desired = removing ? null : { ...intent.desired, public_key: key! };
    const restoring = mode.startsWith("restore-");
    return {
        schema_version: 1,
        hub_id: intent.hub_id,
        hub_address: intent.hub_address,
        port: intent.port,
        context_node_id: intent.context_node_id,
        context_address: intent.desired.address,
        restoring,
        before: restoring ? desired : intent.before,
        after: restoring ? intent.before : desired,
    };
}
const Assets = z.object({
    script: z.string(),
    action_json: z.string().max(8192),
}).strict();
export const behavior = {
    type: "@nckrtl/node-hub-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Validate public peer intent and resolve the shipped one-action script",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    extensionFile(name: string): string;
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = Assets.parse({
                    script: ctx.extensionFile("scripts/hub_peer.py"),
                    action_json: JSON.stringify(hubPeerAction(args)),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
    },
};
