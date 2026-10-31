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
    chunks: z.array(z.object({ json: z.string() })),
    scripts: z.record(z.string(), z.string()),
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
    const bytes = new TextEncoder().encode(JSON.stringify(args));
    // Base64 and native metadata must fit the shared measured 8 MiB frame.
    if (bytes.length > 4194304) throw new Error("caddy.delta_input_too_large");
    const sha256 = Array.from(
        new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
        (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const chunks = [];
    for (let offset = 0; offset < bytes.length; offset += 32768) {
        const data = btoa(
            Array.from(
                bytes.subarray(offset, offset + 32768),
                (b) => String.fromCharCode(b),
            ).join(""),
        );
        chunks.push({ json: JSON.stringify({ offset, data }) });
    }
    return {
        metadata_json: JSON.stringify({
            run_id: args.run_id,
            content_bytes: bytes.length,
            sha256,
        }),
        chunks,
        scripts: Object.fromEntries(
            [
                "prepare_payload",
                "write_payload_chunk",
                "compose_caddy",
                "cleanup_payload",
            ].map((name) => [name, extensionFile(`scripts/${name}.py`)]),
        ),
    };
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
