import { z } from "npm:zod@4.4.3";
import {
    capturedArgv,
    type PublicTail,
    readPublicTail,
} from "../../_lib/public_output.ts";
import { lifecycleArgv } from "./lifecycle.ts";

const Args = z.object({
    operation_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    kind: z.enum(["tasks.cancel", "tasks.maintenance_finish"]),
    intent: z.record(z.string(), z.unknown()),
    root: z.string().startsWith("/"),
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

/**
 * The admitted teardown commands as official SSH exec argv lists, one per
 * step. Each writes its output to `<root>/teardown-<index>` on the VM instead
 * of the SSH session.
 */
export function teardownArgv(
    raw: unknown,
    root: string,
): z.infer<typeof Assets> {
    const intent = Intent.parse(raw);
    return {
        teardown: intent.teardown.map((item, index) =>
            capturedArgv(
                root,
                `teardown-${index}`,
                lifecycleArgv(
                    intent.checkout_path,
                    intent.ssh_execution.target.user,
                    Step.parse(JSON.parse(item.input_json)).command,
                ),
            )
        ),
    };
}

const TEARDOWN_OUTPUT_BYTES = 4096;

/**
 * The redacted tail of each teardown command's downloaded output file in
 * `directory`, by step name; each file is deleted after it is read.
 */
export async function teardownOutput(
    raw: unknown,
    directory: string,
): Promise<Record<string, PublicTail>> {
    const outputs: Record<string, PublicTail> = {};
    for (const index of Intent.parse(raw).teardown.keys()) {
        const name = `teardown-${index}`;
        const tail = await readPublicTail(
            `${directory}/${name}`,
            TEARDOWN_OUTPUT_BYTES,
        );
        if (tail !== null) outputs[name] = tail;
    }
    return outputs;
}

const OutputArgs = z.object({
    intent: z.record(z.string(), z.unknown()),
    directory: z.string().startsWith("/"),
});
const Outputs = z.object({
    teardown: z.record(
        z.string(),
        z.object({ output: z.string(), truncated: z.boolean() }),
    ),
});
type Context = {
    writeResource(
        spec: string,
        name: string,
        value: unknown,
    ): Promise<unknown>;
};

/** Public teardown intent shaped on Node 209; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/task-repository-assets",
    version: "2026.11.02.3",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        output: {
            schema: Outputs,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Build the SSH exec argv of each admitted teardown command",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => ({
                dataHandles: [
                    await ctx.writeResource(
                        "assets",
                        "assets",
                        teardownArgv(args.intent, args.root),
                    ),
                ],
            }),
        },
        output: {
            description:
                "Read, redact and delete each downloaded teardown output file; return its bounded tail",
            arguments: OutputArgs,
            execute: async (
                args: z.infer<typeof OutputArgs>,
                ctx: Context,
            ) => ({
                dataHandles: [
                    await ctx.writeResource("output", "output", {
                        teardown: await teardownOutput(
                            args.intent,
                            args.directory,
                        ),
                    }),
                ],
            }),
        },
    },
};
