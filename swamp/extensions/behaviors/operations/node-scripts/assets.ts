import { z } from "npm:zod@4.4.3";

const ObjectInput = z.record(z.string(), z.unknown());
const Args = z.object({
    script: z.enum(["agent_key"]),
    input: ObjectInput,
}).strict();
const Assets = z.object({ body: z.string(), input: z.string() }).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
/** Reads one shipped Node script and the public intent it takes as JSON; official SSH runs it. */
export const behavior = {
    type: "@nckrtl/node-scripts",
    version: "2026.11.02.6",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Read one shipped Node script and its public input",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                const { script, input } = Args.parse(args);
                const value = Assets.parse({
                    body: await Deno.readTextFile(
                        ctx.extensionFile(`scripts/${script}.py`),
                    ),
                    input: JSON.stringify(input),
                });
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
    },
};
