import { z } from "npm:zod@4.4.3";

const Counts = z.record(z.string().min(1), z.number().int().nonnegative());
const Observation = z.object({
    architecture: z.enum(["x86_64", "arm64"]),
    host_type: z.string().min(1),
    public_key: z.string().min(1),
    host_key_fingerprint: z.string().min(1),
});
const RouteResult = z.object({
    ready: z.literal(true),
    restored: z.boolean(),
    routes: z.array(z.object({
        route_id: z.number().int().positive(),
        domain: z.string(),
        node_ids: z.array(z.number().int().positive()),
        certificate_scopes: z.array(
            z.object({
                node_id: z.number().int().positive(),
                scope: z.string(),
            }),
        ),
        ready: z.literal(true),
    })),
    contributions: Counts,
}).strict();
const Args = z.object({
    node_id: z.number().int().positive(),
    name: z.string().min(1),
    mode: z.enum(["linux", "macos", "tld_only"]),
    forward: Counts,
    restore: Counts,
    outcomes: z.record(
        z.string(),
        z.object({ status: z.string() }).passthrough(),
    ),
    observation: Observation.nullable(),
    wireguard_public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/).nullable(),
    routes_required: z.boolean(),
    route_result: RouteResult.nullable(),
}).strict();

/** The native parent names each required contribution, including expanded children. */
function completed(
    required: z.infer<typeof Counts>,
    outcomes: z.infer<typeof Args>["outcomes"],
): boolean {
    const contributions = Object.entries(required);
    if (!contributions.some(([, count]) => count > 0)) return false;
    return contributions.every(([prefix, count]) => {
        const steps = Object.entries(outcomes).filter(([name]) =>
            name === prefix || name.startsWith(prefix + "-")
        );
        return steps.length === count &&
            steps.every(([, step]) => step.status === "succeeded");
    });
}

export function enrollmentResult(raw: unknown) {
    const args = Args.parse(raw);
    const routeReady = (restored: boolean) =>
        !args.routes_required || args.route_result?.restored === restored;
    const enrolled = completed(args.forward, args.outcomes) &&
        routeReady(false) &&
        (args.mode === "tld_only" || args.observation !== null) &&
        (args.mode !== "linux" || args.wireguard_public_key !== null);
    const restored = !enrolled && completed(args.restore, args.outcomes) &&
        routeReady(true);
    return {
        stdout: JSON.stringify({
            schema_version: 1,
            id: args.node_id,
            name: args.name,
            enrolled,
            restored,
            ...(args.observation ? { observation: args.observation } : {}),
            ...(enrolled && args.wireguard_public_key
                ? { wireguard_public_key: args.wireguard_public_key }
                : {}),
            ...((enrolled || restored) && args.route_result
                ? { routes: args.route_result }
                : {}),
            ...(!enrolled && !restored
                ? { error_code: "node.enrollment_incomplete" }
                : {}),
        }),
    };
}
export const behavior = {
    type: "@nckrtl/node-enrollment-result",
    version: "2026.10.31.1",
    resources: {
        terminal: {
            schema: z.object({ stdout: z.string() }).strict(),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        read: {
            description:
                "Record enrollment or restoration completed by the native parent",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const result = enrollmentResult(args);
                await ctx.writeResource("terminal", "terminal", result);
                return result;
            },
        },
    },
};
