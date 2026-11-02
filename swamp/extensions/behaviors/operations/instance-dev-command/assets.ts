import { z } from "npm:zod@4.4.3";
import { createResult, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.02.2",
    resources: {
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        destroy_assets: {
            description:
                "Read the shipped destroy phase scripts and teardown runner once for official SSH argv, with their public inputs as JSON",
            arguments: z.object({ intent: ObjectValue, teardown: ObjectValue }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    teardown: Record<string, unknown>;
                },
                ctx: Context,
            ) => {
                const read = (path: string) =>
                    Deno.readTextFile(ctx.extensionFile(path));
                const value = {
                    intent_json: JSON.stringify(args.intent),
                    teardown_json: JSON.stringify(args.teardown),
                    verify: await read("destroy/verify.py"),
                    pushed: await read("destroy/pushed.py"),
                    remove: await read("destroy/remove.py"),
                    gone: await read("destroy/gone.py"),
                    setup: await read("create/setup.py"),
                    runner: await read("scripts/run_project_step.py"),
                };
                await ctx.writeResource("result", "destroy-assets", value);
                return value;
            },
        },
        create_assets: {
            description:
                "Read the shipped create phase scripts once for official SSH argv, with the public intent as JSON",
            arguments: z.object({ intent: ObjectValue }),
            execute: async (
                args: { intent: Record<string, unknown> },
                ctx: Context,
            ) => {
                const read = (path: string) =>
                    Deno.readTextFile(ctx.extensionFile(path));
                const value = {
                    intent_json: JSON.stringify(args.intent),
                    prepare: await read("create/prepare.py"),
                    source: await read("create/source.py"),
                    environment: await read("scripts/write_environment.py"),
                    setup: await read("create/setup.py"),
                    runner: await read("scripts/run_project_step.py"),
                };
                await ctx.writeResource("result", "create-assets", value);
                return value;
            },
        },
        create_result: {
            description:
                "Map the create phase steps to one public terminal receipt",
            arguments: z.object({
                intent: ObjectValue,
                prepare: Step.nullable(),
                source: Step.nullable(),
                environment: Step.nullable(),
                setup: Step.nullable(),
                processes: z.array(z.string()),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    prepare: z.infer<typeof Step> | null;
                    source: z.infer<typeof Step> | null;
                    environment: z.infer<typeof Step> | null;
                    setup: z.infer<typeof Step> | null;
                    processes: string[];
                },
                ctx: Context,
            ) => {
                const value = createResult(args.intent, args);
                await ctx.writeResource("result", "create", value);
                return value;
            },
        },
    },
};
