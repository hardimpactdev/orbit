import { z } from "npm:zod@4.4.3";
import {
    CaddyInput,
    CaddyPublication,
    prepareCaddyInput,
} from "../caddy-input.ts";

const SectionInput = CaddyInput.extend({
    blocks: z.array(
        z.object({ source: z.string(), name: z.string(), block: z.string() })
            .strict(),
    ).optional(),
}).transform(({ blocks: _blocks, ...publication }) => publication);
const Args = z.object({
    run_id: z.uuid(),
    node_id: z.number().int().positive(),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    before: SectionInput,
    after: SectionInput,
    direction: z.enum(["forward", "restore"]),
}).strict();
const Assets = z.object({
    metadata_json: z.string(),
    input_json: z.string(),
    input_path: z.string(),
    stage_path: z.string(),
    scripts: z.record(z.string(), z.string()),
    versions: z.array(z.string()).optional(),
});
export async function publicationAssets(
    raw: unknown,
    extensionFile: (name: string) => string,
) {
    const args = Args.parse(raw);
    for (const caddy of [args.before, args.after]) {
        await prepareCaddyInput({
            node_id: args.node_id,
            target_node_id: args.node_id,
            run_id: args.run_id,
            publication: { node_id: args.node_id, user: args.user, caddy },
        });
    }
    return payloadAssets(args, "caddy", [
        "compose_caddy",
        "cleanup_payload",
    ], extensionFile);
}

async function payloadAssets(
    value: { run_id: string },
    family: "caddy" | "php" | "dns",
    names: string[],
    extensionFile: (name: string) => string,
) {
    const bytes = new TextEncoder().encode(JSON.stringify(value));
    // Full public input and metadata must fit the shared measured 8 MiB frame.
    if (bytes.length > 4194304) throw new Error("caddy.delta_input_too_large");
    const roots = {
        caddy: "/etc/caddy",
        php: "/etc/php",
        dns: "/var/lib/orbit/private-dns",
    };
    const stage_path = `${roots[family]}/.orbit-deltas/${value.run_id}`;
    return {
        metadata_json: JSON.stringify({ run_id: value.run_id, family }),
        input_json: new TextDecoder().decode(bytes),
        input_path: `${stage_path}/input.json`,
        stage_path,
        scripts: Object.fromEntries(
            names.map((name) => [name, extensionFile(`scripts/${name}.py`)]),
        ),
    };
}

const Php = z.object({
    account_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    configurations: z.record(
        z.string().regex(/^[1-9][0-9]*\.[0-9]+$/),
        z.string().max(1048576),
    ),
}).strict();
const PhpArgs = z.object({
    run_id: z.uuid(),
    node_id: z.number().int().positive().refine((id) => id !== 1 && id !== 18),
    user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
    before: Php,
    after: Php,
    direction: z.enum(["forward", "restore"]),
}).strict();
export async function phpAssets(
    raw: unknown,
    extensionFile: (name: string) => string,
) {
    const args = PhpArgs.parse(raw);
    if (
        args.before.account_user !== args.user ||
        args.after.account_user !== args.user
    ) throw new Error("php.account_conflict");
    const versions = [
        ...new Set([
            ...Object.keys(args.before.configurations),
            ...Object.keys(args.after.configurations),
        ]),
    ].sort();
    const output = await payloadAssets(args, "php", [
        "php_stage",
        "php_validate",
        "php_publish",
        "php_enable",
        "php_reload",
        "php_confirm",
        "php_restore",
        "php_restore_enable",
        "php_restore_runtime",
        "cleanup_payload",
    ], extensionFile);
    return { ...output, versions };
}

type Context = {
    extensionFile: (name: string) => string;
    writeResource: (
        spec: string,
        name: string,
        value: unknown,
    ) => Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/route-publication-command",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        publication: {
            schema: z.object({
                publication: CaddyPublication,
                result_json: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        php: {
            description:
                "Shape immutable shared PHP deltas and resolve one-action scripts",
            arguments: PhpArgs,
            execute: async (raw: unknown, ctx: Context) => {
                const value = await phpAssets(raw, ctx.extensionFile);
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        assets: {
            description:
                "Validate public section inputs and resolve native action files",
            arguments: Args,
            execute: async (raw: unknown, ctx: Context) => {
                const value = await publicationAssets(raw, ctx.extensionFile);
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        publication: {
            description:
                "Shape the public composed candidate and its observed generation precondition",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const value = CaddyPublication.parse(JSON.parse(args.stdout));
                const output = {
                    publication: value,
                    result_json: JSON.stringify({
                        ready: true,
                        version: value.caddy.version,
                    }),
                };
                await ctx.writeResource("publication", "publication", output);
                return output;
            },
        },
    },
};
