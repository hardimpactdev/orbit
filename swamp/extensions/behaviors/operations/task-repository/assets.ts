import { z } from "npm:zod@4.4.3";
import { AnalysisInput, ScriptInput } from "./input.ts";

const Args = z.object({
    operation_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    kind: z.enum([
        "tasks.script",
        "tasks.analyze",
        "tasks.cancel",
        "tasks.maintenance_finish",
    ]),
    intent: z.record(z.string(), z.unknown()),
});
const Assets = z.object({
    directory: z.string(),
    input_json: z.string(),
    result_script: z.string(),
});

/** Public schemas and native additionalFiles metadata; no execution or HTTP. */
export const behavior = {
    type: "@nckrtl/task-repository-assets",
    version: "2026.11.01.3",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
        environment_script: {
            schema: z.object({ script_file: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        environment_script: {
            description:
                "Resolve the canonical owned-checkout environment parser for native setup",
            arguments: z.object({}).strict(),
            execute: async (_args: Record<string, never>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const value = {
                    script_file: ctx.extensionFile(
                        "scripts/project_environment.py",
                    ),
                };
                await ctx.writeResource(
                    "environment_script",
                    "environment",
                    value,
                );
                return value;
            },
        },
        read: {
            description:
                "Resolve repository actions and serialize the admitted public intent",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                extensionFile(name: string): string;
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const input =
                    ["tasks.cancel", "tasks.maintenance_finish"].includes(
                            args.kind,
                        )
                        ? args.intent
                        : args.kind === "tasks.script"
                        ? ScriptInput.parse(args.intent)
                        : AnalysisInput.parse(args.intent);
                if (
                    !["tasks.cancel", "tasks.maintenance_finish"].includes(
                        args.kind,
                    ) && input.node_id !== args.node_id
                ) {
                    throw new Error("Wrong task VM Node");
                }
                const result = args.kind === "tasks.script"
                    ? "script_result.py"
                    : "analysis_result.py";
                const assets = Assets.parse({
                    directory: ctx.extensionFile("scripts/inspect.py").replace(
                        /\/[^/]+$/,
                        "",
                    ),
                    input_json: JSON.stringify({
                        ...input,
                        operation_id: args.operation_id,
                    }),
                    result_script: ctx.extensionFile("scripts/" + result),
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
