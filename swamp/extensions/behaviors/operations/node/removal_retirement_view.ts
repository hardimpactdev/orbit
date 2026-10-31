import { z } from "npm:zod@4.4.3";

const View = z.object({
    ready: z.literal(true),
    worker_present: z.boolean(),
    observer_present: z.boolean(),
    artifacts_present: z.boolean(),
}).strict();

/** Decode public metadata for native guards; no commands, secrets or Gateway client. */
export const behavior = {
    type: "@nckrtl/node-removal-retirement-view",
    version: "2026.10.31.1",
    resources: {
        view: {
            description:
                "Public admitted host metadata for native retirement guards",
            schema: View,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        decode: {
            description:
                "Decode only the fixed safe metadata from the identity action",
            arguments: z.object({ stdout: z.string().max(4096) }).strict(),
            execute: async (
                args: { stdout: string },
                ctx: {
                    writeResource: (
                        spec: string,
                        name: string,
                        value: unknown,
                    ) => Promise<unknown>;
                },
            ) => {
                const view = View.parse(JSON.parse(args.stdout));
                await ctx.writeResource("view", "view", view);
                return view;
            },
        },
    },
};
