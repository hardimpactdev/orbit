import { z } from "npm:zod@4.4.3";
const Step = z.object({
    status: z.string(),
    outputs: z.record(z.string(), z.unknown()).optional(),
}).passthrough();
const Args = z.object({
    intent: z.object({
        assignment_id: z.number().int().positive(),
        footprint: z.object({
            artifacts: z.array(
                z.object({ name: z.string(), required: z.boolean() })
                    .passthrough(),
            ),
        }).passthrough(),
        cli: z.object({
            skip_reason: z.string().nullable(),
            plan_error: z.string().nullable(),
        }).passthrough(),
    }).passthrough(),
    steps: z.record(z.string(), Step),
}).strict();
export function databaseResult(raw: unknown) {
    const { intent, steps } = Args.parse(raw);
    const ok = (name: string) => steps[name]?.status === "succeeded";
    const role_proven = ok("account") && ok("observer_install") &&
        ok("packages");
    const artifacts: { name: string; status: "applied" | "unchanged" }[] = [];
    let failed_artifact: string | null = null;
    for (const artifact of intent.footprint.artifacts) {
        if (!artifact.required) {
            artifacts.push({ name: artifact.name, status: "unchanged" });
            continue;
        }
        const proven = artifact.name === "agent"
            ? ok("observer")
            : artifact.name === "peer-dns"
            ? ok("peer_dns-0")
            : false;
        if (proven) artifacts.push({ name: artifact.name, status: "applied" });
        else failed_artifact ??= artifact.name;
    }
    const footprint = {
        footprint_proven: failed_artifact === null,
        artifacts,
        ...(failed_artifact
            ? { failed_artifact, error_code: "node.footprint_failed" }
            : {}),
    };
    let cli: Record<string, unknown>;
    if (intent.cli.skip_reason !== null) {
        cli = { outcome: "skipped", reason: intent.cli.skip_reason };
    } else if (intent.cli.plan_error !== null) {
        cli = { error_code: intent.cli.plan_error };
    } else if (!ok("cli")) cli = { error_code: "cli.install_failed" };
    else {
        const output = steps.cli.outputs ?? {};
        cli = "error_code" in output ? output : {
            completed: true,
            outcome: "cli_present",
            version: output.installed,
            configured: output.configured,
        };
    }
    const follow_up = [
        ...(failed_artifact ? ["role-footprint:node.footprint_failed"] : []),
        ...(cli.error_code ? ["role-cli:" + cli.error_code] : []),
    ].join(", ") || null;
    const failed_step = !ok("observer_install")
        ? "agent-install"
        : !ok("account")
        ? "account"
        : "prerequisites";
    return {
        assignment_id: intent.assignment_id,
        created: false,
        role_proven,
        baseline: [{ stage: "prerequisites", ok: role_proven }],
        footprint,
        cli,
        follow_up,
        ...(!role_proven
            ? { failed_step, error_code: "node_role.convergence_failed" }
            : {}),
    };
}
const Result = z.record(z.string(), z.unknown());
export const behavior = {
    type: "@nckrtl/node-role-converge-result",
    version: "2026.11.01.1",
    resources: {
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assemble: {
            description:
                "Assemble the terminal Database contribution from completed native actions",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = databaseResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
        relay: {
            description:
                "Forward the selected native result without reconstructing proof",
            arguments: z.object({ result: Result }).strict(),
            execute: async (
                args: { result: Record<string, unknown> },
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        v: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = {
                    ...args.result,
                    stdout: JSON.stringify(args.result),
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
