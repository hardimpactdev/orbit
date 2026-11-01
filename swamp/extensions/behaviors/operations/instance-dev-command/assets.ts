import { z } from "npm:zod@4.4.3";
import { inspectDestroySources } from "../instance-lifecycle/destroy_inspection.ts";
import { destroyResult, requireActions } from "./required.ts";
import { createResult, Step } from "./result.ts";
const ObjectValue = z.record(z.string(), z.unknown());
const Observation = z.object({ stdout: z.string() }).passthrough();
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/instance-dev-command",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({
                script_body: z.string(),
                script_file: z.string(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        result: { schema: ObjectValue, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        read: {
            description: "Bind one admitted action to its shipped script",
            arguments: z.object({
                action: z.enum([
                    "inspect_source",
                    "template",
                    "write_environment",
                    "finalize_source",
                    "verify_source",
                    "run_project_step",
                ]),
                input: ObjectValue,
            }),
            execute: async (
                args: { action: string; input: Record<string, unknown> },
                ctx: Context,
            ) => {
                let source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                if (
                    !["write_environment", "run_project_step"].includes(
                        args.action,
                    )
                ) {
                    source = source.replace(
                        "json.load(sys.stdin)",
                        "json.loads(" +
                            JSON.stringify(JSON.stringify(args.input)) + ")",
                    );
                }
                const value = {
                    script_body: source,
                    script_file: ctx.extensionFile(
                        "scripts/" + args.action + ".py",
                    ),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        inspect: {
            description:
                "Apply canonical source ownership policy to native SSH observations",
            arguments: z.object({
                input: ObjectValue,
                steps: z.record(z.string(), Step),
            }),
            execute: async (
                args: {
                    input: Record<string, unknown>;
                    steps: Record<string, z.infer<typeof Step>>;
                },
                ctx: Context,
            ) => {
                const observations = Object.entries(args.steps).filter((
                    [key],
                ) => /^destroy-observe-[0-9]+$/.test(key)).map(([, step]) => {
                    if (step.status !== "succeeded") {
                        throw new Error("instance.source_identity_invalid");
                    }
                    return JSON.parse(String(step.outputs?.stdout));
                });
                for (const row of observations) {
                    const position =
                        (args.input.members as Record<string, unknown>[])
                            .findIndex((member) =>
                                member.instance_id === row.instance_id
                            );
                    const origin = args.steps["destroy-origin-" + position];
                    const member =
                        (args.input.members as Record<string, unknown>[])[
                            position
                        ];
                    if (
                        origin?.status !== "succeeded" &&
                        !(origin?.status === "skipped" &&
                            row.origin_required === false)
                    ) throw new Error("instance.source_identity_invalid");
                    const address =
                        String(origin.outputs?.stdout ?? "").trim() ||
                        (member.failed_creation
                            ? String(args.input.repository)
                            : "");
                    row.source.origin = btoa(
                        String.fromCharCode(
                            ...new TextEncoder().encode(address),
                        ),
                    );
                }
                const value = await inspectDestroySources(
                    args.input,
                    async (call) => {
                        const member =
                            (args.input.members as Record<string, unknown>[])
                                .find((member) =>
                                    member.checkout_path === call.input.checkout
                                );
                        const row =
                            observations.find((item) =>
                                item.instance_id === member?.instance_id
                            ) ?? observations.find((item) =>
                                item.account.user === call.input.user
                            );
                        if (!row) {
                            throw new Error("instance.source_identity_invalid");
                        }
                        return call.name === "account.lookup"
                            ? row.account
                            : row.source;
                    },
                );
                await ctx.writeResource("result", "inspection", value);
                return value;
            },
        },
        require_actions: {
            description:
                "Require every admitted action to have actually succeeded",
            arguments: z.object({
                names: z.array(z.string()),
                counts: z.array(z.number().int().nonnegative()),
                steps: z.record(z.string(), Step),
            }),
            execute: async (
                args: {
                    names: string[];
                    counts: number[];
                    steps: Record<string, z.infer<typeof Step>>;
                },
                ctx: Context,
            ) => {
                requireActions(args.names, args.counts, args.steps);
                const value = { ready: true };
                await ctx.writeResource("result", "ready", value);
                return value;
            },
        },
        destroy_result: {
            description:
                "Publish the terminal removal receipt with retained public source recovery",
            arguments: z.object({
                intent: ObjectValue,
                steps: z.record(z.string(), Step),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    steps: Record<string, z.infer<typeof Step>>;
                },
                ctx: Context,
            ) => {
                const value = destroyResult(args.intent, args.steps);
                await ctx.writeResource("result", "destroy", value);
                return value;
            },
        },
        create_result: {
            description:
                "Publish bounded source/environment/runtime facts from completed native actions",
            arguments: z.object({
                intent: ObjectValue,
                steps: z.record(z.string(), Step),
            }),
            execute: async (
                args: {
                    intent: Record<string, unknown>;
                    steps: Record<string, z.infer<typeof Step>>;
                },
                ctx: Context,
            ) => {
                const value = createResult(args.intent, args.steps);
                await ctx.writeResource("result", "create", value);
                return value;
            },
        },
        decode: {
            description: "Decode one bounded public SSH result",
            arguments: z.object({ observation: Observation }),
            execute: async (
                args: { observation: { stdout: string } },
                ctx: Context,
            ) => {
                const value = ObjectValue.parse(
                    JSON.parse(args.observation.stdout),
                );
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
