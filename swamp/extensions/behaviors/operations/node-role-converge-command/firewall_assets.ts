import { z } from "npm:zod@4.4.3";
import { FirewallRules } from "../routes/firewall-schema.ts";
import { FirewallProgram } from "../routes/firewall-program.ts";

const Assets = z.object({ script_body: z.string() }).strict();
const Ready = z.object({ ready: z.literal(true) }).strict();
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Public metadata only; the unchanged canonical UFW transaction runs in official SSH. */
export const behavior = {
    type: "@nckrtl/node-role-firewall-assets",
    version: "2026.11.01.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        ready: { schema: Ready, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Bind canonical public firewall tuples to their existing action",
            arguments: z.object({ rules: FirewallRules }).strict(),
            execute: async (args: { rules: z.infer<typeof FirewallRules> }, ctx: Context) => {
                const payload = { mode: "rules", ...FirewallRules.parse(args.rules) };
                const result = Assets.parse({
                    script_body: FirewallProgram.replace(
                        "json.load(sys.stdin)",
                        "json.loads(" + JSON.stringify(JSON.stringify(payload)) + ")",
                    ),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        decode: {
            description: "Require canonical firewall verification before continuing",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                z.object({ matches: z.literal(true) }).strict().parse(JSON.parse(args.stdout));
                const result = Ready.parse({ ready: true });
                await ctx.writeResource("ready", "ready", result);
                return result;
            },
        },
    },
};
