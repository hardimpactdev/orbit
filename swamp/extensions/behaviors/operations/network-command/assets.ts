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
const Observation = Inspection.extend({
    old_link: z.string().regex(/^[A-Za-z0-9_.:-]+$/),
    public_key: PublicKey,
}).strict();
type Context = {
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
    const key = PublicKey.parse(stdout.trim());
    if ((existing !== null && key !== existing) || other.includes(key)) {
        throw new Error("vpn.peer_key_mismatch");
    }
    return key;
}
/** The read step prints six sections: public key, all resolver servers, orbit domains, orbit default route, the DNS marker, the hook. */
export const INSPECT = [
    "wg show orbit public-key",
    "resolvectl dns",
    "resolvectl domain orbit",
    "resolvectl default-route orbit",
    "{ cat /etc/wireguard/orbit.dns-link 2>/dev/null || true; }",
    '{ grep -E "^PostUp[[:space:]]*=[[:space:]]*resolvectl dns %i " /etc/wireguard/orbit.conf || true; }',
].join(" && echo @@ && ");
const ip = (raw: string) => {
    const value = raw.split(/[%#]/)[0];
    return z.ipv4().safeParse(value).success ||
            z.ipv6().safeParse(value).success
        ? value
        : null;
};
const local = (value: string) => /^(127\.|0\.0\.0\.0$|::1?$)/.test(value);
export function inspection(stdout: string, server: string) {
    const parts = stdout.split(/^@@$/m).map((part) => part.trim());
    if (parts.length !== 6) {
        throw new Error("wireguard.peer_dns_state_unsupported");
    }
    const [key, servers, domains, route, state, hooks] = parts;
    const fields = state.split("\n");
    const link = state ? fields[0] : "orbit";
    if (!/^[A-Za-z0-9_.:-]+$/.test(link)) {
        throw new Error("wireguard.peer_dns_state_unsupported");
    }
    const lines = servers.split("\n");
    const uplink = lines.some((line) =>
        /^(Global|Link [0-9]+ \([^)]+\)):/.test(line) &&
        !/^Link [0-9]+ \(orbit\):/.test(line) &&
        line.slice(line.indexOf(":") + 1).trim().split(/\s+/).some((raw) => {
            const value = ip(raw);
            return value !== null && !local(value) &&
                ![server, fields[1] ?? ""].includes(value);
        })
    );
    return Observation.parse({
        state,
        hooks,
        dns: lines.find((line) => /^Link [0-9]+ \(orbit\):/.test(line)) ?? "",
        domains,
        default_route: route,
        uplink,
        old_link: link,
        public_key: key,
    });
}
export function dnsDecision(
    stdout: string,
    policy: z.infer<typeof Policy>,
    requireUplink = true,
) {
    const observed = inspection(stdout, policy.server);
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
    const observed = inspection(stdout, policy.server);
    if (
        observed.public_key !== policy.public_key ||
        (requireUplink && !observed.uplink) ||
        !matchesPolicy(policy, observed)
    ) {
        throw new Error("vpn.peer_dns_reconcile_failed");
    }
    const { old_link: _link, public_key: _key, ...result } = observed;
    return Inspection.parse(result);
}
export const behavior = {
    type: "@nckrtl/network-command",
    version: "2026.11.02.1",
    resources: {
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
