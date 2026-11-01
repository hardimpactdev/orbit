import { z } from "npm:zod@4.4.3";
const Step = z.object({
    status: z.string(),
    outputs: z.record(z.string(), z.unknown()).optional(),
}).passthrough();
const Args = z.object({
    intent: z.object({
        node_id: z.number().int().positive(),
        assignment_id: z.number().int().positive(),
        role: z.enum(["database", "ingress", "websocket"]),
        created: z.boolean(),
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
    prefix: z.string().default(""),
}).strict();
export function roleResult(raw: unknown) {
    const { intent, steps: allSteps, prefix } = Args.parse(raw);
    const steps = Object.fromEntries(
        Object.entries(allSteps).filter(([k]) =>
            !prefix || k.startsWith(prefix)
        ).map(([k, v]) => [prefix ? k.slice(prefix.length) : k, v]),
    );
    const ok = (name: string) => steps[name]?.status === "succeeded";
    const role_proven = ok("account") && ok("observer_install") &&
        ok("packages") &&
        (intent.role !== "websocket" ||
            (ok("runtime-0") && ok("publication-0") && ok("caddy_package-0") &&
                ok("firewall-0"))) &&
        (intent.role !== "ingress" ||
            ["caddy_package-0", "caddy_service-0", "caddy-0", "firewall-0"]
                .every(ok));
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
            : artifact.name === "caddy"
            ? ok(intent.role === "websocket" ? "publication-0" : "caddy-0")
            : artifact.name === "caddy-package"
            ? ok("caddy_package-0")
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
    const failed_step = !ok("account")
        ? "account"
        : !ok("observer_install")
        ? "agent-install"
        : intent.role === "ingress" && !ok("caddy_package-0")
        ? "caddy-package"
        : !ok("packages")
        ? "prerequisites"
        : ["caddy_service-0", "caddy-0", "firewall-0"].find((name) =>
            !ok(name)
        ) ?? "prerequisites";
    return {
        schema_version: 1 as const,
        node_id: intent.node_id,
        role: intent.role,
        certificates_proven: intent.role !== "websocket" ||
            Object.entries(steps).some(([k, v]) =>
                k.startsWith("certificates-") && v.status === "succeeded"
            ),
        runtime_proven: intent.role !== "websocket" || ok("runtime-0"),
        publication_proven: intent.role === "websocket"
            ? ok("publication-0")
            : intent.role !== "ingress" || ok("caddy-0"),
        assignment_id: intent.assignment_id,
        created: intent.created,
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
export const RoleConvergeContribution = z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    role: z.enum(["database", "ingress", "websocket"]),
    certificates_proven: z.boolean(),
    assignment_id: z.number().int().positive(),
    created: z.boolean(),
    role_proven: z.boolean(),
    runtime_proven: z.boolean(),
    publication_proven: z.boolean(),
    baseline: z.array(z.object({ stage: z.string(), ok: z.boolean() })),
    footprint: z.object({
        footprint_proven: z.boolean(),
        artifacts: z.array(
            z.object({
                name: z.string(),
                status: z.enum(["applied", "unchanged"]),
            }),
        ),
        failed_artifact: z.string().optional(),
        error_code: z.string().optional(),
    }),
    cli: z.record(z.string(), z.unknown()),
    follow_up: z.string().nullable(),
    failed_step: z.string().optional(),
    error_code: z.string().optional(),
}).strict();
export type RoleConvergeReceipt = z.infer<typeof RoleConvergeContribution>;
const Result = z.record(z.string(), z.unknown());
export const behavior = {
    type: "@nckrtl/node-role-converge-result",
    version: "2026.11.01.3",
    resources: {
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        assemble: {
            description:
                "Assemble the terminal role contribution from completed native actions",
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
                const result = RoleConvergeContribution.parse(roleResult(args));
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
