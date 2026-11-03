import { CliNativeResult, CliPlan, CliPlanInput, cliPlan } from "../fleet/cli_install.ts";
import { z } from "npm:zod@4.4.3";
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

export const behavior = {
    type: "@nckrtl/node-role-converge-command",
    version: "2026.11.01.2",
    resources: {
        cli_plan: { schema: CliPlan, lifetime: "1d", garbageCollection: 10 },
        cli_native: { schema: CliNativeResult, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        cli_plan: {
            description: "Select publication from the observed CLI version and self-update support",
            arguments: CliPlanInput,
            execute: async (args: z.infer<typeof CliPlanInput>, ctx: Context) => {
                const plan = cliPlan(args);
                await ctx.writeResource("cli_plan", "plan", plan);
                return plan;
            },
        },
        cli_native_result: {
            description: "Shape the public result of native CLI publication",
            arguments: z.object({ installed: z.boolean(), version: z.string(), configured: z.boolean() }).strict(),
            execute: async (args: { installed: boolean; version: string; configured: boolean }, ctx: Context) => {
                const result = CliNativeResult.parse({ installed: args.installed ? args.version : null, version: args.version, configured: args.configured });
                await ctx.writeResource("cli_native", "result", result);
                return result;
            },
        },
    },
};
