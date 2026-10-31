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
    if (!removing && key === null && !mode.startsWith("restore-")) {
        throw new Error("Publishing the peer requires its prepared public key");
    }
    const desired = removing || key === null
        ? null
        : { ...intent.desired, public_key: key };
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
const Inventory = z.object({
    schema_version: z.literal(1),
    mode: z.literal("inventory"),
    hub_id: Id,
    hub_address: z.string(),
    port: z.number().int().min(1).max(65535),
    inventory: z.array(Peer).max(4096),
    retiring: z.array(Peer).max(4096),
}).strict();
const PublicResult = z.object({
    schema_version: z.literal(1),
    hub_id: Id,
    context_node_id: Id,
    projected: z.literal(true),
    restored: z.boolean(),
    changed: z.boolean(),
    retained_peer_count: z.number().int().nonnegative().optional(),
}).strict();
export function hubPeerResult(hubId: number, stdout: string) {
    const result = PublicResult.parse(JSON.parse(stdout));
    if (result.hub_id !== hubId) {
        throw new Error("Hub output belongs to another Node");
    }
    return {
        schema_version: 1 as const,
        hub_id: hubId,
        status: "succeeded" as const,
        projected: true as const,
    };
}
const Assets = z.object({
    script: z.string(),
    action_json: z.string().max(524288),
    script_body: z.string().max(1048576),
}).strict();
export const behavior = {
    type: "@nckrtl/node-hub-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        terminal: {
            description:
                "Expose the public official SSH stdout for the shared terminal projector",
            arguments: z.object({ stdout: z.string().max(65536) }).strict(),
            execute: async (args: { stdout: string }) => ({
                stdout: JSON.stringify(
                    PublicResult.parse(JSON.parse(args.stdout)),
                ),
            }),
        },
        result: {
            description:
                "Project one fixed command stdout as public hub readiness only",
            arguments: z.object({ hub_id: Id, stdout: z.string().max(65536) })
                .strict(),
            execute: async (args: { hub_id: number; stdout: string }) =>
                hubPeerResult(args.hub_id, args.stdout),
        },
        inventory: {
            description:
                "Read complete public hub intent and locate its one action",
            arguments: z.object({ intent: Inventory }).strict(),
            execute: async (
                args: { intent: z.infer<typeof Inventory> },
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
                    action_json: JSON.stringify(Inventory.parse(args.intent)),
                    script_body: (await Deno.readTextFile(
                        ctx.extensionFile("scripts/hub_peer.py"),
                    )).replace(
                        'HUB_ACTION_JSON = ""',
                        "HUB_ACTION_JSON = " +
                            JSON.stringify(
                                JSON.stringify(
                                    Inventory.parse(args.intent),
                                ),
                            ),
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
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
                    script_body: (await Deno.readTextFile(
                        ctx.extensionFile("scripts/hub_peer.py"),
                    )).replace(
                        'HUB_ACTION_JSON = ""',
                        "HUB_ACTION_JSON = " +
                            JSON.stringify(
                                JSON.stringify(hubPeerAction(args)),
                            ),
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
    },
};
