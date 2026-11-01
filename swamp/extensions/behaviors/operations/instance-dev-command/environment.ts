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
        if (!input.framework_key) throw new Error("env.reference_unavailable");
        values.APP_KEY = input.framework_key;
    }
    const content = renderAccountEnvironment(values, "development", null);
    if (new TextEncoder().encode(content).length > 131072) {
        throw new Error("env.configuration_invalid");
    }
    return {
        values,
        content,
        testing_content: renderAccountEnvironment(
            { ...values, APP_ENV: "testing" },
            "development",
            null,
        ),
        keys: Object.keys(values).sort(),
    };
}
export const behavior = {
    type: "@nckrtl/instance-create-environment",
    version: "2026.11.01.3",
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
                "Merge a committed public template with Gateway-owned vault values",
            arguments: z.object({
                template: z.string().max(131072),
                env: z.object({
                    ORBIT_ENVIRONMENT: z.string().meta({ sensitive: true }),
                }),
                profile: z.object({ laravel: z.boolean() }).passthrough(),
                reference: Ref,
                directory: z.string(),
            }),
            execute: async (
                args: {
                    template: string;
                    env: { ORBIT_ENVIRONMENT: string };
                    profile: { laravel: boolean };
                    reference: z.infer<typeof Ref>;
                    directory: string;
                },
                ctx: Context,
            ) => {
                const result = assemble(
                    args.template,
                    JSON.parse(args.env.ORBIT_ENVIRONMENT),
                    args.profile,
                );
                await ctx.vaultService.put(
                    args.reference.vault,
                    args.reference.key,
                    JSON.stringify({ ...result, directory: args.directory }),
                );
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
