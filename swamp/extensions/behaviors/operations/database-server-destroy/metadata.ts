import { z } from "npm:zod@4.4.3";

const Slots = [
    "canonical",
    "candidate",
    "rollback-running",
    "rollback-stopped",
    "failed",
];
const Input = z.object({
    process_id: z.number().int().positive().nullable(),
    container: z.string().nullable(),
}).passthrough();
const Member = z.object({
    slot: z.string(),
    control: z.string(),
    stop: z.boolean().optional(),
    present: z.boolean().optional(),
});
const Steps = z.record(
    z.string(),
    z.object({
        status: z.string(),
        outputs: z.record(z.string(), z.unknown()).optional(),
    }).passthrough(),
);
type StepMap = z.infer<typeof Steps>;
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Outcome = z.object({ stdout: z.string(), exitCode: z.number().int() });
const Observation = z.object({
    present: z.boolean(),
    state: z.string(),
    spec: z.string().regex(/^[a-f0-9]{64}$/).optional(),
}).strict();
const code = (steps: StepMap) => {
    for (const step of Object.values(steps)) {
        const outputs = step.outputs ?? {};
        const result = (outputs.result && typeof outputs.result === "object"
            ? outputs.result
            : outputs) as Record<string, unknown>;
        if (
            step.status === "skipped" ||
            step.status === "succeeded" && (result.exitCode ?? 0) === 0
        ) {
            continue;
        }
        try {
            const value = JSON.parse(
                typeof result.stdout === "string" ? result.stdout : "",
            );
            if (
                typeof value.error_code === "string" &&
                /^process\.[a-z0-9_]{1,80}$/.test(value.error_code)
            ) {
                return value.error_code;
            }
        } catch {
            /* Only fixed public refusal codes leave native history. */
        }
    }
    return "database.server_destroy_outcome_unknown";
};
export const behavior = {
    type: "@nckrtl/database-server-destroy-metadata",
    version: "2026.10.31.1",
    resources: {
        controls: {
            schema: z.object({ controls: z.array(Member) }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        plan: {
            schema: z.object({
                controls: z.array(Member),
                exitCode: z.number().int(),
                stdout: z.string(),
            }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            schema: Outcome,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        controls: {
            description:
                "Serialize public admitted Process members for native fan-out",
            arguments: z.object({ intent: Input }).strict(),
            execute: async (
                args: { intent: z.infer<typeof Input> },
                ctx: Context,
            ) => {
                const { process_id, container } = args.intent;
                if (
                    process_id === null ? container !== null : !new RegExp(
                        `^orbit-process-${process_id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$`,
                    ).test(container ?? "")
                ) throw new Error("database.server_intent_invalid");
                const value = {
                    controls: process_id === null ? [] : Slots.map((slot) => ({
                        slot,
                        control: JSON.stringify({
                            process_id,
                            runtime: "docker",
                            name: container,
                            vite: null,
                            on_demand: false,
                            slot,
                        }),
                    })),
                };
                await ctx.writeResource("controls", "controls", value);
                return value;
            },
        },
        plan: {
            description:
                "Decode all ownership observations before any native mutation",
            arguments: z.object({ controls: z.array(Member), steps: Steps })
                .strict(),
            execute: async (
                args: { controls: z.infer<typeof Member>[]; steps: StepMap },
                ctx: Context,
            ) => {
                const controls: z.infer<typeof Member>[] = [];
                let valid = true;
                for (const member of args.controls) {
                    const step = args.steps["inspect-" + member.slot];
                    try {
                        if (
                            step?.status !== "succeeded" ||
                            step.outputs?.exitCode !== 0 ||
                            typeof step.outputs.stdout !== "string"
                        ) throw new Error("missing observation");
                        const observed = Observation.parse(
                            JSON.parse(step.outputs.stdout),
                        );
                        controls.push({
                            ...member,
                            present: observed.present,
                            stop: observed.present &&
                                !["created", "exited", "dead"].includes(
                                    observed.state,
                                ),
                        });
                    } catch {
                        valid = false;
                    }
                }
                const value = {
                    controls: valid ? controls : [],
                    exitCode: valid ? 0 : 1,
                    stdout: JSON.stringify(
                        valid
                            ? { ready: true }
                            : { error_code: code(args.steps) },
                    ) + "\n",
                };
                await ctx.writeResource("plan", "plan", value);
                return value;
            },
        },
        result: {
            description: "Return one bounded public native removal outcome",
            arguments: z.object({
                completed: z.boolean(),
                steps: Steps,
                expected: z.array(z.string()).optional(),
            }).strict(),
            execute: async (
                args: {
                    completed: boolean;
                    steps: StepMap;
                    expected?: string[];
                },
                ctx: Context,
            ) => {
                const failed = Object.values(args.steps).some((step) => {
                    const outputs = step.outputs ?? {};
                    const result =
                        (outputs.result && typeof outputs.result === "object"
                            ? outputs.result
                            : outputs) as Record<string, unknown>;
                    return step.status !== "skipped" &&
                        (step.status !== "succeeded" ||
                            (result.exitCode ?? 0) !== 0);
                });
                const delivered = (args.expected ?? []).every((name) => {
                    const step = args.steps[name];
                    const result = step?.outputs?.result as
                        | Record<string, unknown>
                        | undefined;
                    return step?.status === "succeeded" &&
                        result?.exitCode === 0;
                });
                const success = args.completed && !failed && delivered;
                const value = {
                    exitCode: success ? 0 : 1,
                    stdout: JSON.stringify(
                        success
                            ? { backend: "absent" }
                            : { error_code: code(args.steps) },
                    ) + "\n",
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
