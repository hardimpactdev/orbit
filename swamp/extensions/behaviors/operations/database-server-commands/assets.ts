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
                steps: z.array(
                    z.object({
                        status: z.string(),
                        exit_code: z.number().optional(),
                        stdout: z.string().optional(),
                    }).strict(),
                ),
            }).strict(),
            execute: async (
                args: {
                    completed: boolean;
                    steps: {
                        status: string;
                        exit_code?: number;
                        stdout?: string;
                    }[];
                },
                ctx: Context,
            ) => {
                let result: Record<string, unknown> = args.completed
                    ? { backend: "active" }
                    : { error_code: "database.server_create_outcome_unknown" };
                for (const step of args.steps) {
                    if (
                        step.status === "skipped" ||
                        step.status === "succeeded" &&
                            (step.exit_code ?? 0) === 0
                    ) continue;
                    let code = "database.server_create_outcome_unknown";
                    try {
                        const raw = JSON.parse(step.stdout ?? "");
                        if (
                            typeof raw.error_code === "string" &&
                            /^(?:database|process)\.[a-z0-9_]{1,80}$/.test(
                                raw.error_code,
                            )
                        ) code = raw.error_code;
                    } catch {
                        /* A missing public result is an unknown outcome. */
                    }
                    result = { error_code: code };
                    break;
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
