import { z } from "npm:zod@4.4.3";
const ObjectInput = z.record(z.string(), z.unknown());
const Id = z.number().int().positive();
const Intent = z.object({
    node_id: Id,
    identity: z.string().min(1),
    snapshot: z.string(),
    agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
    cli_version: z.string(),
    automatic: z.boolean(),
    daytime: z.boolean(),
    recipe: ObjectInput,
    php: ObjectInput.nullable(),
    incus_hold: z.union([ObjectInput, z.tuple([])]),
    footprint: z.object({
        node_id: Id,
        agent_sha256: z.string(),
        refusal: z.string().nullable(),
        artifacts: z.array(
            z.object({ name: z.string(), required: z.boolean() }).passthrough(),
        ),
        payloads: ObjectInput,
        network: ObjectInput.nullable(),
    }).passthrough(),
    cli: z.object({
        user: z.string(),
        release: ObjectInput.nullable(),
        profile: z.string(),
        skip_reason: z.string().nullable(),
        plan_error: z.string().nullable(),
    }).strict(),
    ssh: z.object({
        control_node_id: z.literal(209),
        identity_file: z.string().startsWith("/"),
        target: z.object({
            host: z.string().min(1),
            user: z.string().min(1),
            port: Id,
            host_keys: z.array(z.string().min(1)).min(1),
        }).passthrough(),
    }).passthrough(),
    build_id: Id.nullable().optional(),
}).strict();
const Action = z.enum([
    "inspect",
    "packages",
    "browser",
    "javascript",
    "php-plan",
    "php-build",
    "services",
    "health",
    "verify",
    "delivery",
    "php-runtime",
    "php-pools",
    "listener",
    "dns-health",
    "dns-validate",
    "dns-restore",
    "tmpfiles",
]);
const Read = z.object({
    action: Action,
    input: z.unknown(),
    automatic: z.boolean().default(false),
    daytime: z.boolean().default(false),
}).strict();
const Step = z.object({ status: z.string(), outputs: ObjectInput.optional() })
    .passthrough();
const ResultArgs = z.object({
    intent: Intent,
    steps: z.record(z.string(), Step),
}).strict();
type Context = {
    extensionFile(path: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export function publicResult(raw: z.infer<typeof ResultArgs>) {
    const { intent, steps } = ResultArgs.parse(raw);
    const output = (name: string) => {
        const row = steps[name]?.outputs ?? {};
        try {
            return ObjectInput.parse(
                typeof row.stdout === "string" ? JSON.parse(row.stdout) : row,
            );
        } catch {
            return {};
        }
    };
    const failure = Object.entries(steps).find(([name, step]) =>
        step.status === "failed" && !name.startsWith("residue_")
    );
    const artifacts: Record<string, unknown>[] = [];
    let failedArtifact: string | undefined;
    const names: Record<string, string[]> = {
        agent: ["observer"],
        "caddy-package": ["caddy_package-0"],
        caddy: ["caddy-0"],
        "peer-dns": ["peer_dns-0"],
        "private-dns": ["listener-0", "dns-0"],
        "route-residue": ["residue_proven"],
        "php-runtime": ["php_runtime"],
        tmpfiles: ["tmpfiles-0"],
    };
    for (const artifact of intent.footprint.artifacts) {
        if (artifact.name === "caddy") {
            const caddy = ObjectInput.parse(
                intent.footprint.payloads.caddy ?? {},
            );
            const reason = caddy.error_code === "caddy.render_failed"
                ? "caddy_render_refused"
                : output("caddy_checked-0").valid === false
                ? "caddy_validate_refused"
                : undefined;
            if (reason) {
                artifacts.push({ name: "caddy", status: "skipped", reason });
                continue;
            }
        }

        const required = names[artifact.name] ?? [];
        const proven = required.length > 0 &&
            required.every((name) =>
                name === "php_runtime"
                    ? Object.entries(steps).filter(([key]) =>
                                key.startsWith("php_runtime-")
                            ).length ===
                            (intent.footprint.payloads["php-runtime"] as {
                                configurations: unknown[];
                            }).configurations.length &&
                        Object.entries(steps).filter(([key]) =>
                            key.startsWith("php_runtime-")
                        ).every(([, step]) => step.status === "succeeded")
                    : steps[name]?.status === "succeeded"
            );
        if (!artifact.required || proven) {
            artifacts.push({
                name: artifact.name,
                status: artifact.required ? "applied" : "unchanged",
            });
        } else if (artifact.name === "route-residue") {
            artifacts.push({
                name: artifact.name,
                status: "skipped",
                reason: "route_residue_cleanup_failed",
            });
        } else failedArtifact ??= artifact.name;
    }
    const delivered = output("delivery");
    const error_code = failure
        ? String(
            output(failure[0].replace(/_window_proven$/, "_window"))
                .error_code ?? "operation.native_failed",
        )
        : failedArtifact
        ? "node.footprint_failed"
        : undefined;
    const packages = output("packages"), verified = output("verify");
    const before = output("inspect"),
        inventory = ObjectInput.parse(verified.inventory ?? {});
    const changedTools = Object.keys(inventory).filter((name) =>
        ObjectInput.parse(before[name] ?? {}).installed !== inventory[name]
    );
    const restarted = z.array(z.string()).parse(
        output("services").restart_units ?? [],
    ).filter((unit) => steps["restart-" + unit]?.status === "succeeded");
    const result = {
        ...(delivered.identity ? delivered : {}),
        ...(steps.packages?.status === "succeeded"
            ? {
                changes: {
                    changed_packages: packages.changed_packages,
                    changed_tools: changedTools,
                    restarted_services: restarted,
                    newer_than_recipe: verified.newer_than_recipe ??
                        packages.newer_than_recipe,
                },
            }
            : {}),
        footprint: {
            artifacts,
            footprint_proven: !failedArtifact,
            ...(failedArtifact
                ? {
                    failed_artifact: failedArtifact,
                    error_code: "node.footprint_failed",
                }
                : {}),
        },
        ...(error_code ? { error_code } : {}),
    };
    return { ...result, stdout: JSON.stringify(result) };
}
export const behavior = {
    type: "@nckrtl/fleet-recipe-command",
    version: "2026.11.01.1",
    resources: {
        assets: {
            schema: z.object({ script_body: z.string() }),
            lifetime: "1d",
            garbageCollection: 10,
        },
        data: { schema: ObjectInput, lifetime: "1d", garbageCollection: 10 },
        result: { schema: ObjectInput, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        window: {
            description:
                "Read the current maintenance window before a native effect group",
            arguments: z.object({
                automatic: z.boolean(),
                daytime: z.boolean(),
            }),
            execute: async (
                args: { automatic: boolean; daytime: boolean },
                ctx: Context,
            ) => {
                const hour = Number(
                    new Intl.DateTimeFormat("en-GB", {
                        timeZone: "Europe/Amsterdam",
                        hour: "2-digit",
                        hourCycle: "h23",
                    }).format(new Date()),
                );
                const allowed = !args.automatic || args.daytime ||
                    (hour >= 3 && hour < 6);
                const result = {
                    allowed,
                    ...(!allowed ? { error_code: "fleet.window_closed" } : {}),
                };
                await ctx.writeResource("data", "data", result);
                return result;
            },
        },
        validate: {
            description:
                "Validate the immutable public recipe and target before any host action",
            arguments: z.object({ intent: Intent, node_id: Id }),
            execute: async (
                args: { intent: z.infer<typeof Intent>; node_id: number },
                ctx: Context,
            ) => {
                const i = Intent.parse(args.intent);
                if (
                    i.node_id !== args.node_id ||
                    i.footprint.node_id !== args.node_id ||
                    i.agent_sha256 !== i.footprint.agent_sha256 ||
                    i.recipe.user !== i.cli.user ||
                    i.recipe.snapshot !== i.snapshot ||
                    i.cli.release?.version !== i.cli_version
                ) throw new Error("node.recipe_input_invalid");
                if (
                    i.footprint.refusal || i.cli.plan_error || i.cli.skip_reason
                ) {
                    throw new Error(
                        String(
                            i.footprint.refusal ?? i.cli.plan_error ??
                                "node.recipe_delivery_unavailable",
                        ),
                    );
                }
                const data = { ready: true };
                await ctx.writeResource("data", "data", data);
                return data;
            },
        },
        read: {
            description:
                "Bind one shipped recipe action to public admission data",
            arguments: Read,
            execute: async (raw: z.infer<typeof Read>, ctx: Context) => {
                const a = Read.parse(raw);
                const footprint = ["listener", "tmpfiles", "dns-health", "dns-validate", "dns-restore"].includes(a.action),
                    php = ["php-runtime", "php-pools"].includes(a.action);
                const file = footprint
                    ? "footprint.py"
                    : php
                    ? "php_runtime.py"
                    : "recipe.py";
                const command = ({
                    listener: "node.install-private-dns-listener",
                    "dns-health": "node.private-dns-health",
                    "dns-validate": "node.private-dns-validate",
                    "dns-restore": "node.private-dns-restore",
                    tmpfiles: "node.publish-tmpfiles",
                    "php-runtime": "php.configure-runtime",
                    "php-pools": "php.converge-shared-pools",
                } as Record<string, string>)[a.action] ?? a.action;
                let source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + file),
                );
                let input = a.input;
                if (a.action === "php-pools") {
                    const pools = z.object({
                        configurations: z.record(z.string(), z.string()),
                        home: z.string(),
                        group: z.string(),
                    }).parse(input);
                    input = {
                        configurations: Object.fromEntries(
                            Object.entries(pools.configurations).map((
                                [v, text],
                            ) => [
                                v,
                                text.replaceAll(
                                    "__orbit_converge_group__",
                                    pools.group,
                                ).replaceAll(
                                    "/__orbit_converge_home__",
                                    pools.home,
                                ),
                            ]),
                        ),
                    };
                }
                const expression = "json.loads(" +
                    JSON.stringify(JSON.stringify(input)) + ")";
                source = file === "recipe.py"
                    ? source.replace(
                        "json.loads(os.environ['ORBIT_RECIPE_INPUT']) if 'ORBIT_RECIPE_INPUT' in os.environ else json.load(sys.stdin)",
                        expression,
                    )
                    : source.replace("json.load(sys.stdin)", expression);
                const check = a.automatic && !a.daytime
                    ? 'from datetime import datetime\nfrom zoneinfo import ZoneInfo\nif not 3 <= datetime.now(ZoneInfo("Europe/Amsterdam")).hour < 6:\n    print(\'{"error_code":"fleet.window_closed"}\')\n    sys.exit(1)\n'
                    : "";
                const result = {
                    script_body: 'import sys\nsys.argv=["recipe",' +
                        JSON.stringify(command) + "]\n" + check + source,
                };
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        decode: {
            description:
                "Decode one public action receipt; a refusal fails its native step",
            arguments: z.object({ stdout: z.string() }),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const data = ObjectInput.parse(JSON.parse(args.stdout));
                if (data.error_code) throw new Error(String(data.error_code));
                await ctx.writeResource("data", "data", data);
                return data;
            },
        },
        result: {
            description: "Assemble only receipts from completed native steps",
            arguments: ResultArgs,
            execute: async (args: z.infer<typeof ResultArgs>, ctx: Context) => {
                const result = publicResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
