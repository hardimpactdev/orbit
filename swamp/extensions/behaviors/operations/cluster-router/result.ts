import { z } from "npm:zod@4.4.3";
const State = z.object({
    status: z.string(),
    outputs: z.object({
        migrated_node_ids: z.array(z.number().int().positive()).optional(),
        result: z.object({
            migrated_node_ids: z.array(z.number().int().positive()).optional(),
        }).passthrough().optional(),
    }).passthrough().optional(),
}).passthrough();
const Args = z.object({
    noop: z.boolean(),
    dns_count: z.number().int().nonnegative(),
    old_count: z.number().int().nonnegative(),
    steps: z.record(z.string(), State),
});
export function routerResult(raw: unknown) {
    const { noop, dns_count, old_count, steps } = Args.parse(raw);
    const succeeded = (name: string) => steps[name]?.status === "succeeded";
    const forward = succeeded("forward-0");
    const restored = succeeded("restore-0");
    const cleaned = Array.from(
        { length: old_count },
        (_, i) => succeeded(`retire-${i}`),
    ).every(Boolean);
    const ready = noop || (forward && cleaned);
    const selected = !restored && (forward || (dns_count > 0 &&
        Array.from(
            { length: dns_count },
            (_, i) => succeeded(`forward_prepare_dns-${i}`),
        ).every(Boolean)));
    const failure = Object.entries(steps).find(([, step]) =>
        step.status === "failed"
    );
    return {
        stdout: JSON.stringify({
            ready,
            selected,
            restored,
            cleanup_complete: ready,
            migrated_node_ids: [
                ...new Set(
                    Object.values(steps).flatMap((step) => [
                        ...(step.outputs?.migrated_node_ids ?? []),
                        ...(step.outputs?.result?.migrated_node_ids ?? []),
                    ]),
                ),
            ],
            ...(!ready
                ? {
                    failed_step: failure?.[0] ?? "router-preparation",
                    error_code: "cluster.router_failed",
                }
                : {}),
        }),
    };
}
const Output = z.object({ stdout: z.string() });
export const behavior = {
    type: "@nckrtl/cluster-router-result",
    version: "2026.10.31.1",
    resources: {
        result: { schema: Output, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Shape native Router outcomes for the terminal Gateway projection",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: {
                writeResource(
                    spec: string,
                    name: string,
                    value: unknown,
                ): Promise<unknown>;
            }) => {
                const result = routerResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
