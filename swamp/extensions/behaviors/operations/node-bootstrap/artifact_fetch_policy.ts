import { z } from "npm:zod@4.4.3";

const Result = z.object({ exitCode: z.number().int().nullable() });
const Input = z.object({ result: Result.nullable() });
// curl: proxy/DNS, connect, partial transfer, HTTP, timeout, TLS, empty reply, send/receive.
const TransportFailures = new Set([5, 6, 7, 18, 22, 28, 35, 52, 55, 56]);
/** Official SSH supplies the process exit code; local I/O and size errors never permit fallback. */
export function classifyFetchFailure(result: z.infer<typeof Result> | null) {
    return {
        fallback: result?.exitCode != null &&
            TransportFailures.has(result.exitCode),
    };
}
type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/artifact-fetch-policy",
    version: "2026.11.04.2",
    resources: {
        decision: {
            schema: z.object({ fallback: z.boolean() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        classify: {
            description:
                "Classify curl's structured exit code for transport-only fallback",
            arguments: Input,
            async execute(args: z.infer<typeof Input>, context: Context) {
                const decision = classifyFetchFailure(args.result);
                await context.writeResource("decision", "decision", decision);
                return decision;
            },
        },
    },
};
