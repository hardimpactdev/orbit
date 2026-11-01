import { z } from "npm:zod@4.4.3";
const Target = z.object({
    process_id: z.string().uuid(),
    node_id: z.number().int().positive(),
    ssh: z.object({
        identity_file: z.string(),
        target: z.object({
            host: z.string(),
            user: z.string(),
            port: z.number(),
            host_keys: z.array(z.string()),
        }).passthrough(),
    }).passthrough(),
    port: z.number().int().min(1).max(65535),
    credential_field: z.string().regex(/^runtime_[a-f0-9_]+$/),
}).strict();
export const Intent = z.object({
    start_tag: z.string().uuid(),
    registry_operation_id: z.string().uuid(),
    epoch: z.string().min(1).max(128),
    revision: z.number().int().nonnegative(),
    change: z.enum(["sync", "create", "destroy", "rotate"]),
    app_id: z.string().nullable(),
    credential_generation: z.number().int().positive().nullable(),
    runtimes: z.array(Target),
    missing_node_ids: z.array(z.number().int().positive()),
}).strict();
const Assets = z.object({
    script_body: z.string(),
    requests: z.record(z.string(), z.string()),
}).strict();
const Terminal = z.object({ stdout: z.string(), accepted: z.boolean() })
    .strict();
const Step = z.object({
    status: z.string(),
    outputs: z.record(z.string(), z.unknown()).optional(),
}).passthrough();
const Steps = z.record(z.string(), Step);
const Observation = z.object({
    process_id: z.string().uuid(),
    boot_id: z.string().uuid(),
    epoch: z.string(),
    revision: z.number().int(),
}).strict();
const ErrorCode = z.enum([
    "websocket.registry_pending",
    "websocket.registry_superseded",
    "websocket.registry_mismatch",
    "websocket.registry_unauthorized",
    "websocket.registry_invalid",
    "websocket.registry_unavailable",
]);
export function terminal(raw: unknown, steps: z.infer<typeof Steps>) {
    const intent = Intent.parse(raw);
    const fail = (error_code: string) => ({
        stdout: JSON.stringify({ error_code }),
        accepted: false,
    });
    if (!intent.runtimes.length || intent.missing_node_ids.length) {
        return fail("websocket.registry_pending");
    }
    const effects = Object.entries(steps).filter(([name]) =>
        name === "apply" || name.startsWith("apply-")
    );
    if (effects.length !== intent.runtimes.length) {
        return fail("websocket.registry_unavailable");
    }
    const expected = new Set(intent.runtimes.map((row) => row.process_id)),
        seen = new Set<string>();
    for (const [, step] of effects) {
        if (
            step.status !== "succeeded" ||
            typeof step.outputs?.stdout !== "string"
        ) return fail("websocket.registry_unavailable");
        if ([124, 137].includes(Number(step.outputs.exitCode))) {
            return fail("websocket.registry_pending");
        }
        let result;
        try {
            result = JSON.parse(step.outputs.stdout);
        } catch {
            return fail("websocket.registry_unavailable");
        }
        const refusal = z.object({ error_code: ErrorCode }).strict().safeParse(
            result,
        );
        if (refusal.success) return fail(refusal.data.error_code);
        const observed = Observation.safeParse(result);
        if (
            !observed.success || !expected.has(observed.data.process_id) ||
            seen.has(observed.data.process_id) ||
            observed.data.epoch !== intent.epoch ||
            observed.data.revision < intent.revision
        ) return fail("websocket.registry_mismatch");
        seen.add(observed.data.process_id);
    }
    return {
        stdout: JSON.stringify({
            outcome: "succeeded",
            data: { backend: "applied" },
        }),
        accepted: true,
    };
}
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/websocket-native",
    version: "2026.11.01.1",
    resources: {
        assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
        terminal: { schema: Terminal, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assets: {
            description:
                "Resolve one shipped Reverb action and public requests",
            arguments: z.object({ intent: Intent }).strict(),
            execute: async (
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) => {
                const intent = Intent.parse(args.intent);
                const result = {
                    script_body: await Deno.readTextFile(
                        ctx.extensionFile("scripts/apply.py"),
                    ),
                    requests: Object.fromEntries(
                        intent.runtimes.map((
                            row,
                        ) => [
                            row.process_id,
                            JSON.stringify({
                                port: row.port,
                                request: {
                                    process_id: row.process_id,
                                    epoch: intent.epoch,
                                    revision: intent.revision,
                                    kind: intent.change,
                                    app_id: intent.app_id,
                                    credential_generation:
                                        intent.credential_generation,
                                },
                            }),
                        ]),
                    ),
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        result: {
            description: "Aggregate only exact admitted runtime confirmations",
            arguments: z.object({ intent: Intent, steps: Steps }).strict(),
            execute: async (
                args: {
                    intent: z.infer<typeof Intent>;
                    steps: z.infer<typeof Steps>;
                },
                ctx: Context,
            ) => {
                const result = terminal(args.intent, args.steps);
                await ctx.writeResource("terminal", "terminal", result);
                return result;
            },
        },
    },
};
