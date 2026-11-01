import { z } from "npm:zod@4.4.3";
import { Site } from "../analytics/plausible.ts";
const Input = z.object({ base_url: z.string().url(), site: Site }).strict();
const Assets = z.object({ input_json: z.string(), site_script: z.string() })
    .strict();
const Common = z.object({
    ready: z.literal(true),
    domain: Site.shape.domain.nullable(),
    timezone: z.string().nullable(),
});
const Enabled = Common.extend({
    enabled: z.literal(true),
    readable: z.literal(true),
    domain: Site.shape.domain,
    timezone: z.string().min(1).max(100),
}).strict();
const Disabled = Common.extend({
    enabled: z.literal(false),
    detached: z.literal(true),
}).strict();
const Result = z.discriminatedUnion("enabled", [Enabled, Disabled]);
const ResultOutput = z.discriminatedUnion("enabled", [
    Enabled.extend({ stdout: z.string() }),
    Disabled.extend({ stdout: z.string() }),
]);
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
export function assets(
    input: z.infer<typeof Input>,
    ctx: Pick<Context, "extensionFile">,
) {
    return {
        input_json: JSON.stringify(Input.parse(input)),
        site_script: ctx.extensionFile("scripts/site.py"),
    };
}
export function result(raw: string) {
    const value = Result.parse(JSON.parse(raw));
    return { ...value, stdout: JSON.stringify(value) };
}
export const behavior = {
    type: "@nckrtl/analytics-site",
    version: "2026.11.01.2",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        result: {
            schema: ResultOutput,
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description:
                "Resolve one shipped Plausible site action group and its public input",
            arguments: Input,
            execute: async (args: z.infer<typeof Input>, ctx: Context) => {
                const value = assets(args, ctx);
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        result: {
            description:
                "Return the admitted public enable or disable terminal result",
            arguments: z.object({ result_json: z.string() }).strict(),
            execute: async (args: { result_json: string }, ctx: Context) => {
                const value = result(args.result_json);
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
