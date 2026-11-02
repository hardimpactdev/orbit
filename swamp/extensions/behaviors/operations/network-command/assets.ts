import { z } from "npm:zod@4.4.3";
import { Inspection, matchesPolicy, PeerDnsInput } from "../fleet/contract.ts";

const PublicKey = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Domains = z.array(
    z.string().max(253).regex(/^[A-Za-z0-9][A-Za-z0-9.-]*$/),
).min(1).max(512);
const Policy = PeerDnsInput.extend({
    server: z.ipv4(),
    dns_server: z.ipv4(),
    public_key: PublicKey,
    domains: Domains,
});
const Peer = z.object({
    server_public_key: PublicKey,
    endpoint: z.string().regex(/^[A-Za-z0-9.[\]:-]+:[0-9]+$/),
    address: z.cidrv4(),
    subnet: z.cidrv4(),
    dns_server: z.ipv4(),
    domains: Domains,
    operator_dns: z.ipv4().nullable(),
    retain: z.boolean(),
}).strict();
const Actions = {
    prepare_key: z.object({ existing_key: PublicKey.nullable() }).strict(),
    hub_peer: z.object({
        existing_key: PublicKey.nullable(),
        public_key: PublicKey,
        address: z.cidrv4(),
    }).strict(),
    peer_config: z.object({ public_key: PublicKey, peer: Peer }).strict(),
    apply_peer: z.object({}).strict(),
    dns_inspect: z.object({ server: z.ipv4() }).strict(),
    dns_persist: Policy,
};
const Action = z.enum(
    Object.keys(Actions) as [keyof typeof Actions, ...(keyof typeof Actions)[]],
);
const Observation = Inspection.extend({
    old_link: z.string().regex(/^[A-Za-z0-9_.:-]+$/),
    public_key: PublicKey,
}).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
async function save(ctx: Context, spec: string, value: unknown) {
    return { dataHandles: [await ctx.writeResource(spec, spec, value)] };
}
export function publicKey(
    stdout: string,
    existing: string | null,
    other: string[],
) {
    const key =
        z.object({ public_key: PublicKey }).strict().parse(JSON.parse(stdout))
            .public_key;
    if ((existing !== null && key !== existing) || other.includes(key)) {
        throw new Error("vpn.peer_key_mismatch");
    }
    return key;
}
export function dnsDecision(
    stdout: string,
    policy: z.infer<typeof Policy>,
    requireUplink = true,
) {
    const observed = Observation.parse(JSON.parse(stdout));
    if (observed.public_key !== policy.public_key) {
        throw new Error("vpn.peer_key_mismatch");
    }
    if (requireUplink && observed.old_link === "orbit" && !observed.uplink) {
        throw new Error("vpn.peer_dns_uplink_missing");
    }
    return {
        changed: !matchesPolicy(policy, observed),
        old_link: observed.old_link,
    };
}
export function dnsResult(
    stdout: string,
    policy: z.infer<typeof Policy>,
    requireUplink = true,
) {
    const observed = Observation.parse(JSON.parse(stdout));
    if (
        observed.public_key !== policy.public_key ||
        (requireUplink && !observed.uplink) ||
        !matchesPolicy(policy, observed)
    ) {
        throw new Error("vpn.peer_dns_reconcile_failed");
    }
    return Inspection.parse(observed);
}
export const behavior = {
    type: "@nckrtl/network-command",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({ script_body: z.string() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        key: {
            schema: z.object({ public_key: PublicKey }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        decision: {
            schema: z.object({ changed: z.boolean(), old_link: z.string() })
                .strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: {
            schema: z.object({ stdout: z.string(), exitCode: z.literal(0) })
                .strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Bind public input to one shipped host action; no command or transport execution",
            arguments: z.object({
                action: Action,
                input: z.record(z.string(), z.unknown()),
            }).strict(),
            execute: async (
                args: { action: keyof typeof Actions; input: unknown },
                ctx: Context,
            ) => {
                const input = Actions[args.action].parse(args.input);
                const common = await Deno.readTextFile(
                    ctx.extensionFile("scripts/common.py"),
                );
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                const body = common + "\n" +
                    source.replace(/^from common import .*\n/m, "");
                return await save(ctx, "assets", {
                    script_body: body.replace(
                        "NETWORK_INPUT = None",
                        "NETWORK_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
            },
        },
        key: {
            description:
                "Validate the public prepared key before hub publication",
            arguments: z.object({
                stdout: z.string(),
                existing_key: PublicKey.nullable(),
                other_public_keys: z.array(PublicKey),
            }).strict(),
            execute: async (
                args: {
                    stdout: string;
                    existing_key: string | null;
                    other_public_keys: string[];
                },
                ctx: Context,
            ) => await save(ctx, "key", {
                public_key: publicKey(
                    args.stdout,
                    args.existing_key,
                    args.other_public_keys,
                ),
            }),
        },
        decideDns: {
            description: "Decide resolver drift from one public observation",
            arguments: z.object({
                stdout: z.string(),
                policy: Policy,
                require_uplink: z.boolean(),
            })
                .strict(),
            execute: async (
                args: {
                    stdout: string;
                    policy: z.infer<typeof Policy>;
                    require_uplink: boolean;
                },
                ctx: Context,
            ) => await save(
                ctx,
                "decision",
                dnsDecision(args.stdout, args.policy, args.require_uplink),
            ),
        },
        dnsResult: {
            description:
                "Compare final native resolver output against the admitted policy",
            arguments: z.object({ stdout: z.string(), policy: Policy })
                .strict(),
            execute: async (
                args: { stdout: string; policy: z.infer<typeof Policy> },
                ctx: Context,
            ) => await save(ctx, "result", {
                stdout: JSON.stringify(dnsResult(args.stdout, args.policy)),
                exitCode: 0,
            }),
        },
        networkResult: {
            description:
                "Return the public terminal network result after all native effects",
            arguments: z.object({
                stdout: z.string(),
                policy: Policy,
                retained: z.boolean(),
                require_uplink: z.boolean(),
            }).strict(),
            execute: async (
                args: {
                    stdout: string;
                    policy: z.infer<typeof Policy>;
                    retained: boolean;
                    require_uplink: boolean;
                },
                ctx: Context,
            ) => {
                dnsResult(args.stdout, args.policy, args.require_uplink);
                return await save(ctx, "result", {
                    stdout: JSON.stringify({
                        network_proven: true,
                        public_key: args.policy.public_key,
                        retained: args.retained,
                    }),
                    exitCode: 0,
                });
            },
        },
    },
};
