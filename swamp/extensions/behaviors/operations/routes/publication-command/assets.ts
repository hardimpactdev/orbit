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
    control_node_id: z.literal(209),
    host_keys: z.array(z.string().min(1)).min(1),
    connection: PublicationConnection,
    direction: z.enum(["forward", "restore"]),
});
const Section = CaddyInput.extend({
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
        const { blocks: _blocks, ...input } = caddy;
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
    configurations: z.record(Version, z.string().max(1048576)),
}).strict();
const PhpArgs = Placement.extend({ before: Php, after: Php }).strict();
export function planPhp(raw: unknown) {
    const value = PhpArgs.parse(raw);
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
    const account = z.object({
        user: Name,
        group: Name,
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
type Context = {
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-publication-command",
    version: "2026.10.31.4",
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
