import { z } from "npm:zod@4.4.3";
import { Setup } from "./tool_setup_input.ts";
import { toolSetupPlan } from "./tool_setup_plan.ts";
export { Setup } from "./tool_setup_input.ts";
const Plan = z.record(z.string(), z.unknown());
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** Pure public bindings. No source files or Node execution are carried in a resource. */
export const behavior = {
    type: "@nckrtl/node-tool-setup-assets",
    version: "2026.11.02.1",
    resources: {
        plan: { schema: Plan, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        plan: {
            description:
                "Describe native resources and argv for the pinned manager",
            arguments: z.object({ setup: Setup, home: z.string() }),
            async execute(
                args: { setup: z.infer<typeof Setup>; home: string },
                ctx: Context,
            ) {
                const value = await toolSetupPlan(args.setup, args.home);
                await ctx.writeResource("plan", "plan", value);
                return value;
            },
        },
    },
};
