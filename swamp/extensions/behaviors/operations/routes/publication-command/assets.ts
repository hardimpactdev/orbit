import { z } from "npm:zod@4.4.3";
import {
    CaddyInput,
    CaddyPublication,
    prepareCaddyInput,
    PublicationConnection,
} from "../caddy-input.ts";
import { mergeDns } from "../mutation-command/dns.ts";
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
    // The primary gid, as route-create-site writes it into every pool.
    const account = z.object({
        user: Name,
        group: z.string().regex(/^[0-9]{1,10}$/),
        home: z.string().startsWith("/"),
    }).strict().parse(JSON.parse(value.account));
    if (account.user !== value.user) throw new Error("php.account_conflict");
    const current = z.record(Version, z.string()).parse(
        JSON.parse(value.current),
    );
    const resolve = (text: string) =>
        text.replaceAll("__ORBIT_NODE_GROUP__", account.group).replaceAll(
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
        const content = mergePhp(previous, desired, current[version]);
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
    // The phase reads the plan from its run folder: a Caddyfile can exceed the limit of one environment value.
    plan_path: z.string().regex(
        /^\/tmp\/orbit-ansible-[0-9a-f-]{36}\/plan\.json$/,
    ),
    read_path: z.string().regex(
        /^\/tmp\/orbit-ansible-[0-9a-f-]{36}\/read\.json$/,
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
const Read = z.object({
    account: z.string(),
    pools: z.record(z.string(), z.string()),
    caddy: z.string(),
    records: z.string(),
    catalog: z.string(),
}).strict();
const decode = (text: string) =>
    new TextDecoder().decode(
        Uint8Array.from(atob(text), (c) => c.charCodeAt(0)),
    );
/** The read phase writes its findings as JSON into the run folder; file contents inside stay base64 until here. */
export function parseRead(text: string) {
    const read = Read.parse(JSON.parse(text));
    const pools: Record<string, string> = {};
    for (const [path, content] of Object.entries(read.pools)) {
        const version =
            /^\/etc\/php\/([1-9][0-9]*\.[0-9]+)\/fpm\/pool\.d\/orbit-scopes\.conf$/
                .exec(path)?.[1];
        if (version) pools[version] = decode(content);
    }
    return {
        account: read.account,
        pools,
        caddy: decode(read.caddy),
        records: decode(read.records),
        catalog: decode(read.catalog),
    };
}
/**
 * One Node's withdrawal: the captured states applied in order to what the Node holds now, so each file is written once.
 * The first step of Caddy and DNS always runs, as the prepare step of a mutation does; a later step runs only when its
 * states differ, as the withdraw and live steps do for a destroy. Every step keeps the three-way revision checks.
 */
export async function composeWithdrawal(raw: unknown, readText: string) {
    const value = WithdrawArgs.parse(raw);
    const read = parseRead(readText);
    const placement = {
        node_id: value.node_id,
        user: value.user,
        control_node_id: value.control_node_id,
        host_keys: value.host_keys,
        connection: value.connection,
        direction: "forward" as const,
    };
    let pools: z.infer<typeof File>[] = [];
    if ("before" in value.php) {
        const fields = read.account.split(":");
        const account = { user: fields[0], group: fields[3], home: fields[5] };
        const { versions } = planPhp({ ...placement, ...value.php });
        pools = composePhp({
            ...placement,
            ...value.php,
            current: JSON.stringify(
                Object.fromEntries(
                    versions.map((
                        version,
                    ) => [version, read.pools[version] ?? ""]),
                ),
            ),
            account: JSON.stringify(account),
        }).files;
    }
    let caddy: { content: string; version: string } | null = null;
    if ("before" in value.caddy) {
        const chain = [
            value.caddy.before,
            value.caddy.prepared,
            value.caddy.withdrawn,
            value.caddy.after,
        ];
        let content = read.caddy;
        let version = "";
        for (let i = 0; i < 3; i++) {
            if (i > 0 && chain[i].version === chain[i + 1].version) continue;
            const composed = await composeCaddy({
                ...placement,
                before: chain[i],
                after: chain[i + 1],
                current: content,
                alternatives: chain,
                retain_removed: false,
            });
            content = composed.caddy.content;
            version = composed.caddy.version;
        }
        caddy = { content, version };
    }
    let dns: { records: string; catalog: string } | null = null;
    if ("before" in value.dns) {
        const chain = [
            value.dns.before,
            value.dns.prepared,
            value.dns.withdrawn,
            value.dns.after,
        ];
        dns = { records: read.records, catalog: read.catalog };
        for (let i = 0; i < 3; i++) {
            const [from, to] = [chain[i], chain[i + 1]];
            if (
                i > 0 && from.records === to.records &&
                from.catalog === to.catalog
            ) continue;
            dns = mergeDns(from, to, dns, chain);
        }
    }
    // What the phase finds on the Node must still be what was read: composing and applying are seconds apart.
    const expect: Record<string, string> = {};
    const hash = async (text: string) =>
        Array.from(
            new Uint8Array(
                await crypto.subtle.digest(
                    "SHA-256",
                    new TextEncoder().encode(text),
                ),
            ),
            (b) => b.toString(16).padStart(2, "0"),
        ).join("");
    if (caddy && read.caddy) {
        expect["/etc/caddy/Caddyfile"] = await hash(read.caddy);
    }
    for (const pool of pools) {
        if (read.pools[pool.version]) {
            expect[pool.path] = await hash(read.pools[pool.version]);
        }
    }
    if (dns) {
        expect["/etc/dnsmasq.d/orbit-records.conf"] = await hash(read.records);
        expect["/var/lib/orbit/private-dns/catalog.json"] = await hash(
            read.catalog,
        );
    }
    return { pools, caddy, dns, cleanup: value.cleanup, expect };
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
    version: "2026.11.06.1",
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
                "Compose one Node's whole Route withdrawal against what it holds now and write the plan for its phase",
            arguments: WithdrawArgs,
            execute: async (args: unknown, ctx: Context) => {
                const value = WithdrawArgs.parse(args);
                const plan = await composeWithdrawal(
                    value,
                    await Deno.readTextFile(value.read_path),
                );
                await Deno.writeTextFile(
                    value.plan_path,
                    JSON.stringify(plan),
                    {
                        mode: 0o600,
                    },
                );
                return {
                    dataHandles: [
                        await ctx.writeResource("withdrawal", "withdrawal", {
                            pools: plan.pools.map((pool) => pool.path),
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
