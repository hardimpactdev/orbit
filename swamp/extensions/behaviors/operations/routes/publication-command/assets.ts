import { z } from "npm:zod@4.4.3";
import {
    CaddyInput,
    CaddyPublication,
    prepareCaddyInput,
    PublicationConnection,
} from "../caddy-input.ts";
import { mergeCaddy, mergePhp, phpSections } from "./sections.ts";
const Name = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Version = z.string().regex(/^[1-9][0-9]*\.[0-9]+$/);
const Placement = z.object({
    node_id: z.number().int().positive(),
    user: Name,
    control_node_id: z.number().int().positive(),
    host_keys: z.array(z.string().min(1)).min(1),
    connection: PublicationConnection,
    direction: z.enum(["forward", "restore"]),
});
/** Sparse unserved means no affected blocks; full snapshots retain their own served groups. */
const Section = CaddyInput.extend({
    had_served_groups: z.boolean().optional(),
    blocks: z.array(
        z.object({ source: z.string(), name: z.string(), block: z.string() }),
    ).optional(),
});
const CaddyArgs = Placement.extend({
    before: Section,
    after: Section,
    current: z.string(),
    alternatives: z.array(Section).default([]),
    retain_removed: z.boolean().default(false),
}).strict();
export async function composeCaddy(raw: unknown) {
    const value = CaddyArgs.parse(raw);
    for (const caddy of [value.before, value.after, ...value.alternatives]) {
        const { blocks: _blocks, had_served_groups: _served, ...input } = caddy;
        await prepareCaddyInput({
            node_id: value.node_id,
            user: value.user,
            control_node_id: value.control_node_id,
            host_keys: value.host_keys,
            connection: value.connection,
            caddy: input,
        });
    }
    const [before, after] = value.direction === "forward"
        ? [value.before, value.after]
        : [value.after, value.before];
    const content = mergeCaddy(
        before.content,
        after.content,
        value.current,
        value.alternatives.map((state) => state.content),
        value.retain_removed,
        before.had_served_groups,
    );
    const version = Array.from(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(content),
            ),
        ),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("").slice(0, 32);
    return {
        node_id: value.node_id,
        user: value.user,
        control_node_id: value.control_node_id,
        host_keys: value.host_keys,
        connection: value.connection,
        caddy: {
            content,
            version,
            listen_addresses: [
                ...new Set([
                    ...before.listen_addresses,
                    ...after.listen_addresses,
                ]),
            ].sort(),
            unserved: !content.includes("# orbit: "),
        },
    };
}
const Php = z.object({
    account_user: Name,
    // The Gateway stores intent as decoded JSON, so a Node without pools arrives as [] rather than {}.
    configurations: z.preprocess(
        (value) => Array.isArray(value) && value.length === 0 ? {} : value,
        z.record(Version, z.string().max(1048576)),
    ),
}).strict();
const PhpArgs = Placement.extend({ before: Php, after: Php }).strict();
export function planPhp(raw: unknown) {
    const value = PhpArgs.parse(raw);
    if (
        Object.keys(value.before.configurations).length === 0 &&
        Object.keys(value.after.configurations).length === 0
    ) return { versions: [], versions_json: "[]" };
    if (
        value.before.account_user !== value.user ||
        value.after.account_user !== value.user
    ) throw new Error("php.account_conflict");
    const indices = [value.before, value.after].map((input) => {
        const index = new Map<string, string>();
        for (const [version, text] of Object.entries(input.configurations)) {
            for (const name of phpSections(text).groups.keys()) {
                if (index.has(name)) throw new Error("php.shared_pool_invalid");
                index.set(name, version);
            }
        }
        return index;
    });
    for (const [name, version] of indices[0]) {
        if (indices[1].has(name) && indices[1].get(name) !== version) {
            throw new Error("php.version_move_requires_withdrawal");
        }
    }
    const versions = [
        ...new Set([
            ...Object.keys(value.before.configurations),
            ...Object.keys(value.after.configurations),
        ]),
    ].sort();
    return { versions, versions_json: JSON.stringify(versions) };
}
const PhpCompose = PhpArgs.extend({ current: z.string(), account: z.string() });
const File = z.object({
    version: Version,
    path: z.string(),
    content: z.string(),
    ensure: z.enum(["present", "absent"]),
});
export function composePhp(raw: unknown) {
    const value = PhpCompose.parse(raw);
    const { current: _current, account: _account, ...plan } = value;
    const { versions } = planPhp(plan);
    // The primary gid, as route-create-site writes it into every pool. A publisher before it wrote the group's name, so a scope of the
    // Route in that form counts as the captured one.
    const account = z.object({
        user: Name,
        group: z.string().regex(/^[0-9]{1,10}$/),
        group_name: z.string().min(1).nullable(),
        home: z.string().startsWith("/"),
    }).strict().parse(JSON.parse(value.account));
    if (account.user !== value.user) throw new Error("php.account_conflict");
    const current = z.record(Version, z.string()).parse(
        JSON.parse(value.current),
    );
    const resolve = (text: string, group = account.group) =>
        text.replaceAll("__ORBIT_NODE_GROUP__", () => group).replaceAll(
            "/__ORBIT_NODE_HOME__",
            account.home,
        );
    const [before, after] = value.direction === "forward"
        ? [value.before, value.after]
        : [value.after, value.before];
    const files = versions.flatMap((version) => {
        const previous = resolve(before.configurations[version] ?? ""),
            desired = resolve(after.configurations[version] ?? "");
        if (previous === desired) return [];
        if (!(version in current)) {
            throw new Error("php.current_config_missing");
        }
        const named = account.group_name
            ? [before, after].map((side) =>
                resolve(side.configurations[version] ?? "", account.group_name!)
            )
            : [];
        const content = mergePhp(previous, desired, current[version], named);
        return [{
            version,
            path: `/etc/php/${version}/fpm/pool.d/orbit-scopes.conf`,
            content,
            ensure: content ? "present" as const : "absent" as const,
        }];
    });
    return { files };
}
const DnsState = z.object({
    records: z.string(),
    catalog: z.string(),
    digest: z.string(),
}).passthrough();
const none = z.object({}).strict();
const states = <T extends z.ZodType>(state: T) =>
    z.union([
        z.object({
            before: state,
            prepared: state,
            withdrawn: state,
            after: state,
        }).strict(),
        none,
    ]);
const WithdrawArgs = z.object({
    node_id: z.number().int().positive(),
    user: Name,
    control_node_id: z.number().int().positive(),
    host_keys: z.array(z.string().min(1)).min(1),
    connection: PublicationConnection,
    // The apply phase reads the plan from its run folder: a Caddyfile can exceed the limit of one environment value.
    plan_path: z.string().regex(
        /^\/tmp\/orbit-ansible-[0-9a-f-]{36}\/plan\.json$/,
    ),
    php: z.union([z.object({ before: Php, after: Php }).strict(), none]),
    caddy: states(Section),
    dns: states(DnsState),
    cleanup: z.array(
        z.object({
            scope: z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]*$/),
            user: Name,
        }).strict(),
    ),
}).strict();
/**
 * One Node's withdrawal plan: the captured states, validated, and the merge steps the Node applies to what it holds under its
 * publication lock (swamp/ansible/module_utils/orbit_route_withdraw.py). The first step of Caddy and DNS always runs, as the
 * prepare step of a mutation does; a later step runs only when its states differ, as the withdraw and live steps do for a destroy.
 * Every step keeps the three-way revision checks on the Node.
 */
export async function planWithdrawal(raw: unknown) {
    const value = WithdrawArgs.parse(raw);
    const placement = {
        node_id: value.node_id,
        user: value.user,
        control_node_id: value.control_node_id,
        host_keys: value.host_keys,
        connection: value.connection,
        direction: "forward" as const,
    };
    let php: {
        versions: string[];
        before: Record<string, string>;
        after: Record<string, string>;
    } | null = null;
    if ("before" in value.php) {
        const { versions } = planPhp({ ...placement, ...value.php });
        if (versions.length) {
            php = {
                versions,
                before: value.php.before.configurations,
                after: value.php.after.configurations,
            };
        }
    }
    let caddy: {
        chain: string[];
        steps: { from: number; to: number; had_served_groups: boolean }[];
        version: string;
    } | null = null;
    if ("before" in value.caddy) {
        const chain = [
            value.caddy.before,
            value.caddy.prepared,
            value.caddy.withdrawn,
            value.caddy.after,
        ];
        for (const state of chain) {
            const { blocks: _blocks, had_served_groups: _served, ...input } = state;
            const { direction: _direction, ...publication } = placement;
            await prepareCaddyInput({ ...publication, caddy: input });
        }
        caddy = {
            chain: chain.map((state) => state.content),
            steps: [0, 1, 2].filter((i) =>
                i === 0 || chain[i].version !== chain[i + 1].version
            ).map((i) => ({
                from: i,
                to: i + 1,
                had_served_groups: chain[i].had_served_groups ?? false,
            })),
            version: chain[3].version,
        };
    }
    let dns: {
        chain: { records: string; catalog: string }[];
        steps: number[];
    } | null = null;
    if ("before" in value.dns) {
        const chain = [
            value.dns.before,
            value.dns.prepared,
            value.dns.withdrawn,
            value.dns.after,
        ].map((state) => ({ records: state.records, catalog: state.catalog }));
        dns = {
            chain,
            steps: [0, 1, 2].filter((i) =>
                i === 0 || chain[i].records !== chain[i + 1].records ||
                chain[i].catalog !== chain[i + 1].catalog
            ),
        };
    }
    return { user: value.user, caddy, php, dns, cleanup: value.cleanup };
}
const Withdrawal = z.object({
    pools: z.array(z.string()),
    caddy: z.string().nullable(),
    dns: z.boolean(),
    cleanup: z.number().int().nonnegative(),
});
type Context = {
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-publication-command",
    version: "2026.11.16.1",
    resources: {
        caddy: {
            schema: CaddyPublication,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        plan: {
            schema: z.object({
                versions: z.array(Version),
                versions_json: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        php: {
            schema: z.object({ files: z.array(File) }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        withdrawal: {
            schema: Withdrawal,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        caddy: {
            description:
                "Compose captured Route sections against current public Caddy text",
            arguments: CaddyArgs,
            execute: async (args: unknown, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "caddy",
                        "caddy",
                        await composeCaddy(args),
                    ),
                ],
            }),
        },
        phpPlan: {
            description: "Select admitted shared PHP versions",
            arguments: PhpArgs,
            execute: async (args: unknown, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource("plan", "plan", planPhp(args)),
                ],
            }),
        },
        withdraw: {
            description:
                "Validate one Node's Route withdrawal and write the merge plan that its Node applies under its publication lock",
            arguments: WithdrawArgs,
            execute: async (args: unknown, ctx: Context) => {
                const value = WithdrawArgs.parse(args);
                const plan = await planWithdrawal(value);
                await Deno.writeTextFile(
                    value.plan_path,
                    JSON.stringify(plan),
                    { mode: 0o600 },
                );
                return {
                    dataHandles: [
                        await ctx.writeResource("withdrawal", "withdrawal", {
                            pools: (plan.php?.versions ?? []).map((version) =>
                                `/etc/php/${version}/fpm/pool.d/orbit-scopes.conf`
                            ),
                            caddy: plan.caddy?.version ?? null,
                            dns: plan.dns !== null,
                            cleanup: plan.cleanup.length,
                        }),
                    ],
                };
            },
        },
        php: {
            description:
                "Compose captured PHP pools against current public text",
            arguments: PhpCompose,
            execute: async (args: unknown, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource("php", "php", composePhp(args)),
                ],
            }),
        },
    },
};
