import { z } from "npm:zod@4.4.3";

const Args = z.object({
    operation_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    kind: z.enum(["tasks.cancel", "tasks.maintenance_finish"]),
    intent: z.record(z.string(), z.unknown()),
});
const Assets = z.object({
    directory: z.string(),
    input_json: z.string(),
});

/** Public teardown intent and native additionalFiles metadata; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/task-repository-assets",
    version: "2026.11.02.1",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Resolve the repository scripts directory and serialize the admitted public intent",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const assets = Assets.parse({
                    directory: ctx.extensionFile(
                        "scripts/repository_common.py",
                    ).replace(/\/[^/]+$/, ""),
                    input_json: JSON.stringify({
                        ...args.intent,
                        operation_id: args.operation_id,
                    }),
                });
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", assets),
                    ],
                };
            },
        },
    },
};
