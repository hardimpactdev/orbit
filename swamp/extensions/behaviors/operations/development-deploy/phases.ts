import projectEnvironment from "../task-repository/scripts/project_environment.py" with {
    type: "text",
};
import { z } from "npm:zod@4.4.3";

const ObjectValue = z.record(z.string(), z.unknown());
const Step = z.object({
    status: z.string(),
    outputs: z.object({ stdout: z.string(), exitCode: z.number().optional() })
        .passthrough().optional(),
}).passthrough();
type StepValue = z.infer<typeof Step>;
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

function publicOutput(step: StepValue | null) {
    if (step?.status !== "succeeded" || !step.outputs) return null;
    return ObjectValue.parse(JSON.parse(step.outputs.stdout));
}

/** Only decode public phase facts; native dependencies own sequencing and failure. */
export function phaseResult(
    source: StepValue | null,
    commands: StepValue | null,
    commandsRequired = true,
    verified: StepValue | null = null,
    runtime: Record<string, unknown> | null = null,
) {
    const prepared = publicOutput(source);
    const applied = !commandsRequired
        ? { ready: true, warnings: 0 }
        : publicOutput(commands);
    const vpReady = runtime === null || publicOutput(verified)?.ready === true;
    const failure = prepared?.ready !== true
        ? prepared
        : !vpReady
        ? publicOutput(verified)
        : applied;
    const ready = prepared?.ready === true && vpReady &&
        applied?.ready === true;
    return {
        ready,
        commit: prepared?.commit ?? null,
        vp_environment: runtime !== null && vpReady
            ? { ...runtime, status: "active" }
            : null,
        release: null,
        previous: null,
        selected_release: null,
        selection_confirmed: true,
        warnings: applied?.warnings ?? 0,
        failed_step: prepared?.ready !== true
            ? "source_preparation"
            : !vpReady
            ? "prepare_vp_environment"
            : applied?.ready !== true
            ? applied?.failed_step ?? "before_activation"
            : null,
        output_tail: failure?.output_tail ?? null,
        ...(ready ? {} : {
            error_code: failure?.error_code ?? "operation.native_failed",
        }),
    };
}

export const behavior = {
    type: "@nckrtl/deployment-phases",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: ObjectValue,
            lifetime: "workflow",
            garbageCollection: 1,
        },
        result: {
            schema: z.object({ stdout: z.string(), ready: z.boolean() }),
            lifetime: "workflow",
            garbageCollection: 1,
        },
    },
    methods: {
        assets: {
            description:
                "Read shipped phase scripts without rewriting their source",
            arguments: z.object({
                intent: ObjectValue,
                operation_id: z.number().int().positive(),
                run_id: z.string().uuid(),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    operation_id: number;
                    run_id: string;
                },
                ctx: Context,
            ) => {
                const source = await Deno.readTextFile(
                    ctx.extensionFile("phases/source_preparation.py"),
                );
                const commands = projectEnvironment + "\n" +
                    await Deno.readTextFile(
                        ctx.extensionFile("phases/before_activation.py"),
                    );
                const vp = projectEnvironment + "\n" +
                    await Deno.readTextFile(
                        ctx.extensionFile("scripts/common.py"),
                    ) + "\n" +
                    await Deno.readTextFile(ctx.extensionFile("scripts/vp.py"));
                const request = JSON.stringify(args);
                return {
                    dataHandles: [
                        await ctx.writeResource("assets", "phase-assets", {
                            source,
                            commands,
                            vp,
                            request,
                        }),
                    ],
                };
            },
        },
        result: {
            description: "Return the public source and command phase result",
            arguments: z.object({
                source: Step.nullable(),
                commands: Step.nullable(),
                commands_required: z.boolean(),
                verified: Step.nullable(),
                runtime: ObjectValue.nullable(),
            }),
            execute: async (
                args: {
                    source: StepValue | null;
                    commands: StepValue | null;
                    commands_required: boolean;
                    verified: StepValue | null;
                    runtime: Record<string, unknown> | null;
                },
                ctx: Context,
            ) => {
                const result = phaseResult(
                    args.source,
                    args.commands,
                    args.commands_required,
                    args.verified,
                    args.runtime,
                );
                return {
                    dataHandles: [
                        await ctx.writeResource("result", "result", {
                            stdout: JSON.stringify(result),
                            ready: result.ready,
                        }),
                    ],
                };
            },
        },
    },
};
