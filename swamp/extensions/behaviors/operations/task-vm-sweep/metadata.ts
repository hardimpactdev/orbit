import { z } from "npm:zod@4.4.3";

const Names = z.array(z.string().regex(/^[A-Za-z0-9][A-Za-z0-9._-]{0,253}$/))
    .max(4096);
const Receipt = z.object({
    ready: z.boolean(),
    observed_names: Names.optional(),
    deleted_names: Names.optional(),
    error_code: z.literal("task_vm.host_command_failed").optional(),
    failed_step: z.enum(["incus", "cloud"]).optional(),
}).superRefine((r, ctx) => {
    if (r.ready && (!r.observed_names || !r.deleted_names)) {
        ctx.addIssue({
            code: "custom",
            message: "A successful sweep requires final inventory",
        });
    }
});
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Asset = z.object({
    script_body: z.string(),
    input_json: z.string(),
});
const Result = z.object({ stdout: z.string(), ready: z.boolean() });
export const behavior = {
    type: "@nckrtl/task-vm-sweep-input",
    version: "2026.11.01.2",
    resources: {
        assets: { lifetime: "infinite", garbageCollection: 10, schema: Asset },
        terminal: {
            lifetime: "infinite",
            garbageCollection: 10,
            schema: Result,
        },
    },
    methods: {
        assets: {
            description:
                "Resolve unchanged phase scripts once and serialize only public intent",
            arguments: z.object({ intent: z.record(z.string(), z.unknown()) }),
            async execute(
                args: { intent: Record<string, unknown> },
                ctx: Context,
            ) {
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "assets", {
                            script_body: await Deno.readTextFile(
                                ctx.extensionFile("scripts/incus.py"),
                            ),
                            input_json: JSON.stringify(args.intent),
                        }),
                    ],
                };
            },
        },
        result: {
            description:
                "Return terminal stdout directly without a shell printf step",
            arguments: z.object({
                provider: z.enum(["incus", "upcloud"]),
                steps: z.record(z.string(), z.unknown()),
            }),
            async execute(
                args: { provider: string; steps: Record<string, unknown> },
                ctx: Context,
            ) {
                const name = args.provider === "incus" ? "incus" : "cloud";
                const child = z.object({
                    status: z.string(),
                    outputs: z.unknown().optional(),
                }).optional().parse(args.steps[name]);
                const output = child?.status === "succeeded"
                    ? z.object({
                        sweep: z.object({
                            stdout: z.string(),
                            exitCode: z.number().optional(),
                        }),
                    }).parse(child.outputs).sweep
                    : undefined;
                const receipt = output && output.stdout.trim() &&
                        (output.exitCode === undefined ||
                            [0, 1].includes(output.exitCode))
                    ? Receipt.parse(JSON.parse(output.stdout))
                    : {
                        ready: false,
                        error_code: "task_vm.host_command_failed",
                        failed_step: name,
                    };
                if (!receipt.ready) {
                    Object.assign(receipt, { failed_step: name });
                }
                return {
                    dataHandles: [
                        await ctx.writeResource("terminal", "terminal", {
                            ready: receipt.ready,
                            stdout: JSON.stringify(receipt),
                        }),
                    ],
                };
            },
        },
    },
};
