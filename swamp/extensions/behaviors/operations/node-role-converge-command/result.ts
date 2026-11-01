import { z } from "npm:zod@4.4.3";
const Step = z.object({
    status: z.string(),
    outputs: z.record(z.string(), z.unknown()).optional(),
}).passthrough();
const Args = z.object({
    intent: z.object({
        node_id: z.number().int().positive(),
        assignment_id: z.number().int().positive(),
        role: z.enum([
            "database",
            "ingress",
            "websocket",
            "app-prod",
            "app-dev",
        ]),
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
    certificate_statuses: z.array(z.string()).default([]),
    private_dns_statuses: z.array(z.string()).default([]),
}).strict();
export function roleResult(raw: unknown) {
    const { intent, steps, certificate_statuses, private_dns_statuses } = Args
        .parse(raw);
    const ok = (name: string) => steps[name]?.status === "succeeded";
    const dnsCount = intent.role === "app-dev"
        ? z.object({
            publications: z.array(
                z.object({ after: z.object({ dns: z.unknown() }) })
                    .passthrough(),
            ),
        }).parse(intent).publications.filter((row) => row.after.dns != null)
            .length
        : 0;
    const appDevProven = intent.role !== "app-dev" ||
        (ok("storage") && ok("hibernation") &&
            private_dns_statuses.length === dnsCount &&
            private_dns_statuses.every((status) => status === "succeeded"));
    const role_proven = appDevProven && ok("account") &&
        ok("observer_install") &&
        ok("packages") &&
        (intent.role !== "websocket" ||
            (ok("runtime-0") && ok("publication-0") &&
                ok("firewall-0"))) &&
        (!["ingress", "app-prod", "app-dev"].includes(intent.role) ||
            ["caddy_service-0", "caddy-0", "firewall-0"]
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
            ? ok("packages")
            : artifact.name === "tmpfiles"
            ? ok("tmpfiles")
            : artifact.name === "php-runtime"
            ? ok("php_runtime")
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
        const raw = steps.cli.outputs ?? {};
        const output = typeof raw.stdout === "string"
            ? z.record(z.string(), z.unknown()).parse(JSON.parse(raw.stdout))
            : raw;
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
    const expectedCertificates = intent.role === "websocket"
        ? z.object({ leaves: z.array(z.unknown()) }).parse(intent.certificates)
            .leaves.length
        : 0;
    const failed_step = !ok("account")
        ? "account"
        : intent.role === "app-dev" && !ok("storage")
        ? "storage"
        : intent.role === "app-dev" &&
                (private_dns_statuses.length !== dnsCount ||
                    private_dns_statuses.some((status) =>
                        status !== "succeeded"
                    ))
        ? "private-dns"
        : !ok("observer_install")
        ? "agent-install"
        : !ok("packages")
        ? "prerequisites"
        : intent.role === "app-dev" && !ok("hibernation")
        ? "hibernation"
        : (intent.role === "websocket"
            ? ["runtime-0", "publication-0", "firewall-0"]
            : ["caddy_service-0", "caddy-0", "firewall-0"]).find((name) =>
                !ok(name)
            ) ?? "prerequisites";
    return {
        schema_version: 1 as const,
        node_id: intent.node_id,
        role: intent.role,
        certificates_proven: intent.role !== "websocket" ||
            (expectedCertificates > 0 &&
                certificate_statuses.length === expectedCertificates &&
                certificate_statuses.every((status) => status === "succeeded")),
        runtime_proven: intent.role !== "websocket" || ok("runtime-0"),
        publication_proven: intent.role === "websocket"
            ? ok("publication-0")
            : (!["ingress", "app-prod", "app-dev"].includes(intent.role) ||
                ok("caddy-0")) &&
                (intent.role !== "app-dev" ||
                    (private_dns_statuses.length === dnsCount &&
                        private_dns_statuses.every((status) =>
                            status === "succeeded"
                        ))),
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
    role: z.enum(["database", "ingress", "websocket", "app-prod", "app-dev"]),
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
    version: "2026.11.01.5",
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
