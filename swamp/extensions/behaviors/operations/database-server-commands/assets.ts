import { z } from "npm:zod@4.4.3";

const State = z.object({
    present: z.boolean(),
    state: z.string(),
    spec: z.string().regex(/^[a-f0-9]{64}$/).optional(),
}).strict();
const Plan = State.extend({
    replace: z.boolean(),
    rollback_slot: z.enum(["rollback-running", "rollback-stopped"]),
});
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** File metadata and bounded public observations only; native steps own every action. */
export const behavior = {
    type: "@nckrtl/database-server-command-assets",
    version: "2026.10.31.1",
    resources: {
        assets: {
            schema: z.object({
                directory: z.string(),
                intent_json: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        plan: { schema: Plan, lifetime: "infinite", garbageCollection: 10 },
        result: {
            schema: z.object({
                stdout: z.string(),
                exitCode: z.number().int(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description: "Resolve Database server command files",
            arguments: z.object({ intent: z.record(z.string(), z.unknown()) })
                .strict(),
            execute: async (
                args: { intent: Record<string, unknown> },
                ctx: Context,
            ) => {
                const value = {
                    directory: ctx.extensionFile("scripts/ready.py").replace(
                        /\/[^/]+$/,
                        "",
                    ),
                    intent_json: JSON.stringify(args.intent),
                };
                await ctx.writeResource("assets", "assets", value);
                return value;
            },
        },
        result: {
            description:
                "Shape bounded public native step outcomes for the shared terminal projector",
            arguments: z.object({
                completed: z.boolean(),
                backend: z.enum(["active", "absent"]).optional(),
                failure_code: z.enum([
                    "database.server_create_outcome_unknown",
                    "database.user_create_outcome_unknown",
                    "database.destroy_outcome_unknown",
                ]).optional(),
                steps: z.record(
                    z.string(),
                    z.object({
                        status: z.string(),
                        outputs: z.record(z.string(), z.unknown()).optional(),
                    }).passthrough(),
                ),
            }).strict(),
            execute: async (
                args: {
                    completed: boolean;
                    backend?: "active" | "absent";
                    failure_code?:
                        | "database.server_create_outcome_unknown"
                        | "database.user_create_outcome_unknown"
                        | "database.destroy_outcome_unknown";
                    steps: Record<
                        string,
                        { status: string; outputs?: Record<string, unknown> }
                    >;
                },
                ctx: Context,
            ) => {
                const failure = args.failure_code ??
                    "database.server_create_outcome_unknown";
                let result: Record<string, unknown> = args.completed
                    ? { backend: args.backend ?? "active" }
                    : { error_code: failure };
                for (const step of Object.values(args.steps)) {
                    const outputs = step.outputs ?? {};
                    // Native child-workflow outputs are keyed by their result step.
                    const output =
                        (outputs.result && typeof outputs.result === "object"
                            ? outputs.result
                            : outputs) as Record<string, unknown>;
                    if (
                        step.status === "skipped" ||
                        step.status === "succeeded" &&
                            (output.exitCode ?? 0) === 0
                    ) continue;
                    result = {
                        error_code: failure,
                    };
                    try {
                        const raw = JSON.parse(
                            typeof output.stdout === "string"
                                ? output.stdout
                                : "",
                        );
                        if (
                            typeof raw.error_code === "string" &&
                            /^(?:database|process)\.[a-z0-9_]{1,80}$/.test(
                                raw.error_code,
                            )
                        ) {
                            result = { error_code: raw.error_code };
                            break;
                        }
                    } catch {
                        /* A missing public result is an unknown outcome. */
                    }
                }
                const value = {
                    stdout: JSON.stringify(result) + "\n",
                    exitCode: "error_code" in result ? 1 : 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
        plan: {
            description:
                "Select native container replacement branches from one public observation",
            arguments: z.object({
                stdout: z.string().max(4096),
                spec: z.string().regex(/^[a-f0-9]{64}$/),
            }).strict(),
            execute: async (
                args: { stdout: string; spec: string },
                ctx: Context,
            ) => {
                const state = State.parse(JSON.parse(args.stdout));
                const value = Plan.parse({
                    ...state,
                    replace: state.present && state.spec !== args.spec,
                    rollback_slot: state.state === "running"
                        ? "rollback-running"
                        : "rollback-stopped",
                });
                await ctx.writeResource("plan", "plan", value);
                return value;
            },
        },
    },
};
