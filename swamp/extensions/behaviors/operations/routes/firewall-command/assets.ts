import { z } from "npm:zod@4.4.3";
const Args = z.object({
    firewall: z.object({
        desired: z.array(z.unknown()),
        remove: z.array(z.unknown()),
        enable: z.boolean(),
    }).strict(),
    router_lan: z.object({
        desired: z.array(z.unknown()),
        expand: z.boolean(),
        prune: z.boolean(),
    }).strict(),
});
const Assets = z.object({
    scripts_directory: z.string(),
    rules_json: z.string(),
    router_json: z.string(),
});
const Result = z.object({ ready: z.literal(true) });
export const behavior = {
    type: "@nckrtl/router-firewall-assets",
    version: "2026.10.31.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Resolve the canonical UFW and Router family transaction scripts",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    extensionFile(path: string): string;
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const output = {
                    scripts_directory: ctx.extensionFile("scripts"),
                    rules_json: JSON.stringify(args.firewall),
                    router_json: JSON.stringify(args.router_lan),
                };
                await ctx.writeResource("assets", "assets", output);
                return output;
            },
        },
        decode: {
            description:
                "Decode the two separate native Router firewall action results",
            arguments: z.object({
                rules_stdout: z.string(),
                router_stdout: z.string(),
            }),
            execute: async (
                args: { rules_stdout: string; router_stdout: string },
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                for (const stdout of [args.rules_stdout, args.router_stdout]) {
                    z.object({ matches: z.literal(true) }).strict().parse(
                        JSON.parse(stdout),
                    );
                }
                const result = { ready: true as const };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
