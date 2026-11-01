import { z } from "npm:zod@4.4.3";
import { parseTemplate } from "./template.ts";
import { renderAccountEnvironment } from "../instance-deploy/dotenv.ts";
const Ref = z.object({
    vault: z.literal("orbit-control-inputs"),
    key: z.string().regex(/^instance-create-environment-result-[1-9][0-9]*$/),
}).strict();
type Context = {
    vaultService: {
        put(vault: string, key: string, value: string): Promise<void>;
    };
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function assemble(
    template: string,
    material: unknown,
    profile: { laravel: boolean },
) {
    const input = z.object({
        values: z.record(z.string(), z.string()),
        name: z.string(),
        framework_key: z.string().nullable(),
    }).strict().parse(material);
    const parsed = parseTemplate(template);
    const values = { ...parsed, ...input.values };
    values.APP_NAME ??= input.name;
    values.APP_ENV ??= "development";
    values.APP_DEBUG ??= "true";
    if (profile.laravel && !values.APP_KEY) {
        values.APP_KEY = input.framework_key ??
            "base64:" +
                btoa(
                    String.fromCharCode(
                        ...crypto.getRandomValues(new Uint8Array(32)),
                    ),
                );
    }
    const content = renderAccountEnvironment(values, "development", null);
    if (new TextEncoder().encode(content).length > 131072) {
        throw new Error("env.configuration_invalid");
    }
    return { values, content, keys: Object.keys(values).sort() };
}
export const behavior = {
    type: "@nckrtl/instance-create-environment",
    version: "2026.11.01.1",
    resources: {
        result: {
            schema: z.object({
                ready: z.literal(true),
                reference: Ref,
                keys: z.array(z.string()),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        capture: {
            description:
                "Capture one private template into native vault output; return names and keys only",
            arguments: z.object({
                path: z.string(),
                env: z.string().meta({ sensitive: true }),
                profile: z.object({ laravel: z.boolean() }).passthrough(),
                reference: Ref,
                directory: z.string(),
            }),
            execute: async (
                args: {
                    path: string;
                    env: string;
                    profile: { laravel: boolean };
                    reference: z.infer<typeof Ref>;
                    directory: string;
                },
                ctx: Context,
            ) => {
                const metadata = await Deno.lstat(args.path);
                if (
                    !metadata.isFile || metadata.isSymlink ||
                    metadata.size > 131072
                ) throw new Error("env.configuration_invalid");
                const result = assemble(
                    await Deno.readTextFile(args.path),
                    JSON.parse(args.env),
                    args.profile,
                );
                await ctx.vaultService.put(
                    args.reference.vault,
                    args.reference.key,
                    JSON.stringify({ ...result, directory: args.directory }),
                );
                await Deno.remove(args.path);
                const value = {
                    ready: true,
                    reference: args.reference,
                    keys: result.keys,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
