import { z } from "npm:zod@4.4.3";

const Id = z.number().int().positive();
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Sha = z.string().regex(/^[a-f0-9]{64}$/);
const Domain = z.string().min(1).max(253).regex(
    /^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/,
);
const Cidr = z.string().regex(/^[0-9.]+\/(?:[0-9]|[12][0-9]|3[0-2])$/).refine((
    value,
) => z.ipv4().safeParse(value.split("/")[0]).success);
const Rule = z.object({
    action: z.enum(["allow", "deny", "reject", "limit"]),
    source: z.string(),
    destination: z.string(),
    port: z.string(),
    protocol: z.string(),
    comment: z.string(),
    direction: z.string(),
    in_interface: z.string().nullable(),
    out_interface: z.string().nullable(),
    family: z.string().nullable(),
}).strict();
export const NetworkCommandIntent = z.object({
    schema_version: z.literal(1),
    node_id: Id,
    agent_sha256: Sha,
    hub_id: Id,
    existing_key: Key.nullable(),
    architecture: z.literal("x86_64"),
    peer: z.object({
        server_public_key: Key,
        endpoint: z.string().max(262).regex(/^[A-Za-z0-9.[\]:-]+$/),
        address: Cidr,
        subnet: Cidr,
        dns_server: z.ipv4(),
        domains: z.array(Domain).min(1).max(512),
        operator_dns: z.ipv4().nullable(),
        retain: z.boolean(),
    }).strict(),
    trust: z.object({
        desired: z.array(
            z.object({ shape: Rule, replace_port: z.boolean() }).strict(),
        ),
        remove: z.array(Rule),
        enable: z.boolean(),
    }).strict(),
}).strict().superRefine((intent, ctx) => {
    if (intent.peer.retain !== (intent.existing_key !== null)) {
        ctx.addIssue({
            code: "custom",
            message: "Peer retention differs from the admitted key",
        });
    }
    if (intent.node_id === intent.hub_id || [1, 18].includes(intent.node_id)) {
        ctx.addIssue({
            code: "custom",
            message: "The peer requires a proven direct command worker",
        });
    }
    if (
        new Set(intent.peer.domains).size !== intent.peer.domains.length ||
        intent.peer.domains.some((domain, index, domains) =>
            index > 0 && domains[index - 1] >= domain
        )
    ) {
        ctx.addIssue({
            code: "custom",
            message: "DNS domains are not canonical",
        });
    }
});
export const PreparedPeerKey = z.object({ public_key: Key }).strict();

/** Pure intent/result shaping. Host actions are visible command/shell workflow steps. */
export function networkCommandInputs(raw: unknown, nodeId: number) {
    const intent = NetworkCommandIntent.parse(raw);
    if (intent.node_id !== nodeId) {
        throw new Error("Network intent belongs to another Node");
    }
    return { network_json: JSON.stringify(intent) };
}

const BeforeImage = z.object({
    captured: z.literal(true),
    legacy_link: z.string().regex(/^[A-Za-z0-9_.:+-]{1,64}$/).nullable(),
}).strict();
const ResolverSetting = z.object({
    link: z.string().regex(/^[A-Za-z0-9_.:+-]{1,64}$/),
    property: z.enum([
        "dns",
        "domain",
        "default-route",
        "llmnr",
        "mdns",
        "dnssec",
        "dnsovertls",
        "nta",
    ]),
    value: z.array(z.string().max(4096)).max(512),
}).strict();
const RestoredView = z.object({
    present: z.boolean(),
    active: z.boolean(),
    enabled: z.string().max(64),
    settings: z.array(ResolverSetting.extend({ json: z.string() })).max(1024),
}).strict();
export function restorationView(stdout: string) {
    const input = z.discriminatedUnion("present", [
        z.object({ present: z.literal(false) }).strict(),
        z.object({
            present: z.literal(true),
            active: z.boolean(),
            enabled: z.string().max(64),
            settings: z.array(ResolverSetting).max(1024),
        }).strict(),
    ]).parse(JSON.parse(stdout));
    return input.present
        ? {
            ...input,
            settings: input.settings.map((setting) => ({
                ...setting,
                json: JSON.stringify(setting),
            })),
        }
        : { present: false, active: false, enabled: "disabled", settings: [] };
}

type Context = {
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};

export function publicPeerKey(stdout: string) {
    return PreparedPeerKey.parse(JSON.parse(stdout));
}

export const behavior = {
    type: "@nckrtl/node-network-command-inputs",
    version: "2026.10.31.1",
    resources: {
        before: {
            description: "Public before-image ownership metadata",
            schema: BeforeImage,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        restoration: {
            description: "Public before-image metadata",
            schema: RestoredView,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        intent: {
            description: "Validated non-secret action input",
            schema: z.object({ network_json: z.string().max(262144) }).strict(),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        key: {
            description: "Prepared public peer key",
            schema: PreparedPeerKey,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        beforeImage: {
            description:
                "Decode the prior DNS link name without private before-image bytes",
            arguments: z.object({ stdout: z.string().max(4096) }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const output = BeforeImage.parse(JSON.parse(args.stdout));
                await ctx.writeResource("before", "before", output);
                return output;
            },
        },
        restoration: {
            description:
                "Shape public restoration metadata without exposing before-image bytes",
            arguments: z.object({ stdout: z.string().max(262144) }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const output = restorationView(args.stdout);
                await ctx.writeResource("restoration", "restoration", output);
                return output;
            },
        },
        prepare: {
            description: "Validate and encode the supplied public peer intent",
            arguments: z.object({ node_id: Id, intent: NetworkCommandIntent })
                .strict(),
            execute: async (
                args: { node_id: number; intent: unknown },
                ctx: Context,
            ) => {
                const output = networkCommandInputs(args.intent, args.node_id);
                await ctx.writeResource("intent", "intent", output);
                return output;
            },
        },
        publication: {
            description:
                "Shape public peer publication data after the canonical hub step",
            arguments: z.object({
                node_id: Id,
                intent: NetworkCommandIntent,
                public_key: Key,
            }).strict(),
            execute: async (
                args: { node_id: number; intent: unknown; public_key: string },
                ctx: Context,
            ) => {
                const intent = NetworkCommandIntent.parse(args.intent);
                networkCommandInputs(intent, args.node_id);
                if (
                    intent.existing_key !== null &&
                    intent.existing_key !== args.public_key
                ) {
                    throw new Error(
                        "The prepared public key differs from the admitted identity",
                    );
                }
                const output = {
                    network_json: JSON.stringify({
                        ...intent,
                        public_key: args.public_key,
                    }),
                };
                await ctx.writeResource("intent", "intent", output);
                return output;
            },
        },
        publicKey: {
            description: "Decode only a public WireGuard preparation result",
            arguments: z.object({ stdout: z.string().max(4096) }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const output = publicPeerKey(args.stdout);
                await ctx.writeResource("key", "key", output);
                return output;
            },
        },
    },
};
