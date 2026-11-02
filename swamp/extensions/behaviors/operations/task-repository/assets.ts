import { z } from "npm:zod@4.4.3";
import { lifecycleArgv } from "./lifecycle.ts";

const Args = z.object({
    operation_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    kind: z.enum(["tasks.cancel", "tasks.maintenance_finish"]),
    intent: z.record(z.string(), z.unknown()),
});
const Intent = z.object({
    checkout_path: z.string().startsWith("/"),
    ssh_execution: z.object({
        target: z.object({ user: z.string().min(1) }).passthrough(),
    }).passthrough(),
    teardown: z.array(
        z.object({ input_json: z.string() }).passthrough(),
    ).max(32),
});
const Step = z.object({ command: z.string().min(1) }).passthrough();
const Assets = z.object({ teardown: z.array(z.array(z.string())) });

/** The admitted teardown commands as official SSH exec argv lists, one per step. */
export function teardownArgv(raw: unknown): z.infer<typeof Assets> {
    const intent = Intent.parse(raw);
    return {
        teardown: intent.teardown.map((item) =>
            lifecycleArgv(
                intent.checkout_path,
                intent.ssh_execution.target.user,
                Step.parse(JSON.parse(item.input_json)).command,
            )
        ),
    };
}

/** Public teardown intent shaped on Node 209; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/task-repository-assets",
    version: "2026.11.02.2",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Build the SSH exec argv of each admitted teardown command",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "assets",
                        "assets",
                        teardownArgv(args.intent),
                    ),
                ],
            }),
        },
    },
};
