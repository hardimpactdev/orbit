import { z } from "npm:zod@4.4.3";
import { Site } from "../analytics/plausible.ts";
const Input = z.object({ base_url: z.string().url(), site: Site }).strict();
const Decision = z.object({
    action: z.enum(["create", "update", "unchanged"]),
    current_domain: Site.shape.domain,
}).strict();
const Assets = z.object({
    input_json: z.string(),
    goal_batches: z.array(z.string()),
    login_form: z.string(),
    login: z.string(),
    site_form: z.string(),
    inspect: z.string(),
    create: z.string(),
    update: z.string(),
    verify: z.string(),
    goals: z.string(),
    stats: z.string(),
    logout: z.string(),
});
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
export function assets(
    input: z.infer<typeof Input>,
    ctx: Pick<Context, "extensionFile">,
) {
    const parsed = Input.parse(input);
    return Assets.parse({
        input_json: JSON.stringify(parsed),
        goal_batches: Array.from({
            length: Math.ceil(parsed.site.goals.length / 8),
        }, (_, i) => JSON.stringify(parsed.site.goals.slice(i * 8, i * 8 + 8))),
        login_form: ctx.extensionFile("scripts/login_form.py"),
        login: ctx.extensionFile("scripts/login.py"),
        site_form: ctx.extensionFile("scripts/site_form.py"),
        inspect: ctx.extensionFile("scripts/inspect.py"),
        create: ctx.extensionFile("scripts/create.py"),
        update: ctx.extensionFile("scripts/update.py"),
        verify: ctx.extensionFile("scripts/verify.py"),
        goals: ctx.extensionFile("scripts/goals.py"),
        stats: ctx.extensionFile("scripts/stats.py"),
        logout: ctx.extensionFile("scripts/logout.py"),
    });
}
export const behavior = {
    type: "@nckrtl/analytics-site",
    version: "2026.11.01.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        decision: {
            schema: Decision,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description:
                "Resolve shipped Plausible actions and public goal batches",
            arguments: Input,
            execute: async (args: z.infer<typeof Input>, ctx: Context) => {
                const value = assets(args, ctx);
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        decide: {
            description: "Decode the public observed site decision",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const value = Decision.parse(JSON.parse(args.stdout));
                await ctx.writeResource("decision", "decision", value);
                return value;
            },
        },
    },
};
