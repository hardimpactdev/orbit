import { z } from "npm:zod@4.4.3";
import { Input } from "./doctor_contract.ts";
import { Result } from "./doctor-read/doctor.ts";

const Context = z.object({
    channel: z.enum(["fleet", "horizon"]),
    revision: z.number().int().positive(),
    expectations: z.string().regex(/^[a-f0-9]{64}$/),
    captured_at: z.iso.datetime({ offset: true }),
    node_id: z.number().int().positive(),
    intent: Input,
});
type ContextValue = z.infer<typeof Context>;
type Writer = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};

/** Immutable captures: dispatch order never selects an older revision over a newer one. */
export function selectContext(
    candidates: unknown[],
    now = Date.now(),
): ContextValue {
    const contexts = candidates.map((candidate) => Context.parse(candidate));
    contexts.sort((a, b) => b.revision - a.revision);
    const current = contexts[0];
    if (
        !current || now - Date.parse(current.captured_at) > 3600000 ||
        Date.parse(current.captured_at) > now + 60000
    ) {
        throw new Error("observation.context_unavailable");
    }
    return current;
}

export function scheduledResult(context: unknown, reading: unknown) {
    const current = Context.parse(context);
    const result = z.object({ stdout: z.string(), exitCode: z.literal(0) })
        .parse(reading);
    return {
        channel: current.channel,
        revision: current.revision,
        expectations: current.expectations,
        expected_log_cursor: current.intent.logs?.cursor ?? null,
        observation: Result.parse(JSON.parse(result.stdout)),
    };
}

export const behavior = {
    type: "@nckrtl/observation-schedule",
    version: "2026.11.01.1",
    resources: {
        context: { schema: Context, lifetime: "1d", garbageCollection: 2 },
        selected: { schema: Context, lifetime: "1d", garbageCollection: 2 },
        result: {
            schema: z.object({
                stdout: z.string(),
                exitCode: z.number().int(),
            }),
            lifetime: "1d",
            garbageCollection: 2,
        },
    },
    methods: {
        capture: {
            description: "Store one immutable public observation context",
            arguments: Context,
            execute: async (args: ContextValue, ctx: Writer) => {
                await ctx.writeResource("context", "context", args);
                return args;
            },
        },
        select: {
            description:
                "Select the latest fresh public context for one bounded tick",
            arguments: z.object({ candidates: z.array(z.unknown()) }),
            execute: async (args: { candidates: unknown[] }, ctx: Writer) => {
                const value = selectContext(args.candidates);
                await ctx.writeResource("selected", "selected", value);
                return value;
            },
        },
        result: {
            description:
                "Expose the typed terminal observation and its exact context revision",
            arguments: z.object({ context: Context, reading: z.unknown() }),
            execute: async (
                args: { context: ContextValue; reading: unknown },
                ctx: Writer,
            ) => {
                const value = {
                    stdout: JSON.stringify(
                        scheduledResult(args.context, args.reading),
                    ) + "\n",
                    exitCode: 0,
                };
                await ctx.writeResource("result", "result", value);
                return value;
            },
        },
    },
};
