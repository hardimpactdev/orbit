import { z } from "npm:zod@4.4.3";
import {
    ServiceObservation,
    servicePlan,
    ServicePlanArgs,
    serviceSpecification,
    ServiceState,
} from "./service.ts";
import { listener } from "../integrations/metrics_service_spec.ts";
import { sameState } from "../integrations/metrics_policy.ts";
import {
    ExporterArgs,
    ExporterKind,
    ExporterObservation,
    exporterPlan,
} from "./exporters.ts";
import { exporterSpecification } from "../integrations/metrics_exporter_spec.ts";
import {
    metricsPaths,
    metricsRuntimeLayout,
    metricsRuntimeSpec,
} from "../integrations/metrics_runtime_spec.ts";

const Service = z.enum(["prometheus", "grafana"]);
const Layout = z.object({
    service: Service,
    name: z.string(),
    backup: z.string(),
    volume: z.string(),
    image: z.string(),
    labels: z.record(z.string(), z.string()),
    volume_labels: z.record(z.string(), z.string()),
}).strict();
const Specification = Layout.extend({
    hash: z.string().regex(/^[a-f0-9]{64}$/),
    args: z.array(z.string()),
    volume_args: z.array(z.string()),
}).strict();
const Args = z.object({
    assignment_id: z.number().int().positive(),
    address: z.ipv4(),
    configuration: z.record(z.string(), z.string()),
}).strict();
const CredentialReady = z.object({ ready: z.literal(true) }).strict();
const Readiness = z.object({
    ready: z.literal(true),
    runtime_completed: z.literal(true),
}).strict();
const Credential = z.object({ address: z.ipv4() }).strict();
const Result = z.object({
    prometheus: Specification,
    grafana: Specification,
}).strict();

/** Reuse the canonical fixed images, mounts, health checks and specification hashes. */
export async function roleMetricsSpecifications(raw: unknown) {
    const args = Args.parse(raw);
    const paths = Object.keys(args.configuration);
    const publicPaths = metricsPaths.slice(0, -1);
    if (
        paths.length !== publicPaths.length ||
        paths.some((path) => !publicPaths.includes(path))
    ) {
        throw new Error(
            "Metrics specification requires the complete public configuration only",
        );
    }
    const values = await Promise.all(Service.options.map(async (service) => ({
        ...metricsRuntimeLayout(service),
        volume_args: [
            "volume",
            "create",
            ...Object.entries(metricsRuntimeLayout(service).volume_labels)
                .sort().flatMap((
                    [key, value],
                ) => ["--label", key + "=" + value]),
            "--",
            metricsRuntimeLayout(service).volume,
        ],
        ...await metricsRuntimeSpec(
            service,
            args.assignment_id,
            args.address,
            args.configuration,
        ),
    })));
    return Result.parse({ prometheus: values[0], grafana: values[1] });
}

const InspectInput = z.object({
    kind: z.enum(["container", "volume"]),
    name: z.string().regex(
        /^orbit-metrics-(?:prometheus|grafana)(?:-data|-orbit-rollback)?$/,
    ),
    labels: z.record(z.string(), z.string()),
    hash: z.string().regex(/^[a-f0-9]{64}$/).nullable(),
}).strict();
const Script = z.object({ script_body: z.string() }).strict();
const Observation = z.object({
    present: z.boolean(),
    state: z.string().regex(/^[a-z_-]{1,64}$/),
    health: z.enum(["healthy", "unhealthy", "starting"]).nullable(),
    hash: z.string().regex(/^[a-f0-9]{64}$/).nullable(),
    current: z.boolean(),
}).strict();
type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/node-role-metrics-assets",
    version: "2026.10.31.1",
    resources: {
        service: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
        service_observation: {
            schema: ServiceObservation,
            lifetime: "1d",
            garbageCollection: 10,
        },
        exporter: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "1d",
            garbageCollection: 10,
        },
        exporter_observation: {
            schema: ExporterObservation,
            lifetime: "1d",
            garbageCollection: 10,
        },
        action: {
            schema: CredentialReady,
            lifetime: "1d",
            garbageCollection: 10,
        },
        readiness: { schema: Readiness, lifetime: "1d", garbageCollection: 10 },
        script: { schema: Script, lifetime: "1d", garbageCollection: 10 },
        observation: {
            schema: Observation,
            lifetime: "1d",
            garbageCollection: 10,
        },
        specifications: {
            schema: Result,
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        service_listener: {
            description:
                "Select the canonical public FPM exporter health endpoint",
            arguments: z.object({ config: z.string() }).strict(),
            execute: async (args: { config: string }, ctx: Context) => {
                const result = {
                    url: "http://" + listener(args.config) + "/metrics",
                };
                await ctx.writeResource("service", "listener", result);
                return result;
            },
        },
        service_health: {
            description: "Verify the observed FPM exporter metrics response",
            arguments: z.object({ stdout: z.string().max(8388608) }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                if (!args.stdout.startsWith("# HELP")) {
                    throw new Error("metrics.service_unready");
                }
                const result = { ready: true as const };
                await ctx.writeResource("action", "action", result);
                return result;
            },
        },

        service_plan: {
            description: "Capture the canonical FPM exporter service delta",
            arguments: ServicePlanArgs,
            execute: async (
                args: z.infer<typeof ServicePlanArgs>,
                ctx: Context,
            ) => {
                const result = servicePlan(args);
                await ctx.writeResource("service", "service", result);
                return result;
            },
        },
        service_decode: {
            description: "Decode one public FPM exporter observation",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = ServiceObservation.parse(
                    JSON.parse(args.stdout),
                );
                await ctx.writeResource(
                    "service_observation",
                    "service_observation",
                    result,
                );
                return result;
            },
        },
        service_verify: {
            description: "Verify the actual FPM exporter state",
            arguments: ServicePlanArgs.extend({ expected: ServiceState })
                .strict(),
            execute: async (
                args: z.infer<typeof ServicePlanArgs> & {
                    expected: z.infer<typeof ServiceState>;
                },
                ctx: Context,
            ) => {
                const { expected, ...input } = args;
                const actual = servicePlan(input).before;
                if (!sameState(actual, expected)) {
                    throw new Error("metrics.service_unverified");
                }
                const result = { ready: true as const };
                await ctx.writeResource("action", "action", result);
                return result;
            },
        },
        service_asset: {
            description: "Read one fixed public FPM exporter action",
            arguments: z.object({
                action: z.enum([
                    "service_observe",
                    "artifact",
                    "service_directory",
                    "service_directory_remove",
                ]),
            }).strict(),
            execute: async (args: { action: string }, ctx: Context) => {
                const input = args.action === "artifact"
                    ? serviceSpecification.artifact
                    : serviceSpecification;
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                const result = Script.parse({
                    script_body: source.replace(
                        "METRICS_INPUT = None",
                        "METRICS_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
                await ctx.writeResource("script", "script", result);
                return result;
            },
        },

        exporter_plan: {
            description:
                "Capture canonical exporter ownership and the exact firewall delta",
            arguments: ExporterArgs,
            execute: async (
                args: z.infer<typeof ExporterArgs>,
                ctx: Context,
            ) => {
                const plan = exporterPlan(args);
                await ctx.writeResource("exporter", "exporter", plan);
                return plan;
            },
        },
        exporter_decode: {
            description: "Decode one public exporter observation",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = ExporterObservation.parse(
                    JSON.parse(args.stdout),
                );
                await ctx.writeResource(
                    "exporter_observation",
                    "exporter_observation",
                    result,
                );
                return result;
            },
        },
        exporter_verify: {
            description:
                "Prove the canonical exporter configuration, service and firewall",
            arguments: ExporterArgs.extend({
                expected: z.object({
                    configuration: z.string().nullable(),
                    service_active: z.boolean(),
                    firewall_source: z.ipv4().nullable(),
                }).strict(),
            }).strict(),
            execute: async (
                args: z.infer<typeof ExporterArgs> & {
                    expected: {
                        configuration: string | null;
                        service_active: boolean;
                        firewall_source: string | null;
                    };
                },
                ctx: Context,
            ) => {
                const { expected, ...input } = args;
                const actual = exporterPlan(input).before;
                if (
                    actual.configuration !== expected.configuration ||
                    actual.service_active !== expected.service_active ||
                    actual.firewall_source !== expected.firewall_source
                ) throw new Error("metrics.exporter_unverified");
                const result = { ready: true as const };
                await ctx.writeResource("action", "action", result);
                return result;
            },
        },
        exporter_asset: {
            description: "Read one canonical public exporter action",
            arguments: z.object({
                action: z.enum(["exporter_observe", "artifact"]),
                kind: ExporterKind,
                address: z.ipv4(),
            }).strict(),
            execute: async (
                args: {
                    action: "exporter_observe" | "artifact";
                    kind: z.infer<typeof ExporterKind>;
                    address: string;
                },
                ctx: Context,
            ) => {
                const spec = exporterSpecification(args.kind, args.address);
                const input = args.action === "artifact" ? spec.artifact : {
                    path: spec.path,
                    marker: spec.marker,
                    service: spec.service,
                };
                if (!input) throw new Error("metrics.artifact_unavailable");
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/" + args.action + ".py"),
                );
                const result = Script.parse({
                    script_body: source.replace(
                        "METRICS_INPUT = None",
                        "METRICS_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(input)) + ")",
                    ),
                });
                await ctx.writeResource("script", "script", result);
                return result;
            },
        },
        owner: {
            description: "Read the canonical Metrics file ownership preflight",
            arguments: z.object({}).strict(),
            execute: async (_args: Record<string, never>, ctx: Context) => {
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/owner.py"),
                );
                const result = Script.parse({
                    script_body: source.replace(
                        "METRICS_INPUT = None",
                        "METRICS_INPUT = json.loads(" +
                            JSON.stringify(
                                JSON.stringify([
                                    ...metricsPaths,
                                    "/etc/orbit/metrics/.orbit-owner",
                                ]),
                            ) + ")",
                    ),
                });
                await ctx.writeResource("script", "script", result);
                return result;
            },
        },
        credential: {
            description:
                "Read one Grafana authentication observation with no private input",
            arguments: Credential,
            execute: async (raw: z.infer<typeof Credential>, ctx: Context) => {
                const args = Credential.parse(raw);
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/credential.py"),
                );
                const result = Script.parse({
                    script_body: source.replace(
                        "METRICS_INPUT = None",
                        "METRICS_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(args)) + ")",
                    ),
                });
                await ctx.writeResource("script", "script", result);
                return result;
            },
        },
        prove: {
            description:
                "Require actual healthy matching services and verified existing credentials",
            arguments: z.object({
                prometheus: Observation,
                grafana: Observation,
                credentials: z.object({ ready: z.literal(true) }).strict(),
            }).strict(),
            execute: async (
                args: {
                    prometheus: z.infer<typeof Observation>;
                    grafana: z.infer<typeof Observation>;
                    credentials: { ready: true };
                },
                ctx: Context,
            ) => {
                if (
                    !args.prometheus.current || !args.grafana.current ||
                    !args.credentials.ready
                ) {
                    throw new Error("metrics.runtime_unready");
                }
                const result = Readiness.parse({
                    ready: true,
                    runtime_completed: true,
                });
                await ctx.writeResource("readiness", "readiness", result);
                return result;
            },
        },
        decode_ready: {
            description: "Decode one public ready action result",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = z.object({ ready: z.literal(true) }).strict()
                    .parse(JSON.parse(args.stdout));
                await ctx.writeResource("action", "action", result);
                return result;
            },
        },
        read: {
            description:
                "Read the public inspection action for one canonical Metrics resource",
            arguments: InspectInput,
            execute: async (
                raw: z.infer<typeof InspectInput>,
                ctx: Context,
            ) => {
                const args = InspectInput.parse(raw);
                const source = await Deno.readTextFile(
                    ctx.extensionFile("scripts/inspect.py"),
                );
                const result = Script.parse({
                    script_body: source.replace(
                        "METRICS_INPUT = None",
                        "METRICS_INPUT = json.loads(" +
                            JSON.stringify(JSON.stringify(args)) + ")",
                    ),
                });
                await ctx.writeResource("script", "script", result);
                return result;
            },
        },
        decode: {
            description:
                "Decode public Metrics ownership, specification and health only",
            arguments: z.object({ stdout: z.string() }).strict(),
            execute: async (args: { stdout: string }, ctx: Context) => {
                const result = Observation.parse(JSON.parse(args.stdout));
                await ctx.writeResource("observation", "observation", result);
                return result;
            },
        },
        specifications: {
            description:
                "Read the canonical public Metrics service specifications",
            arguments: Args,
            execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                const result = await roleMetricsSpecifications(args);
                await ctx.writeResource(
                    "specifications",
                    "specifications",
                    result,
                );
                return result;
            },
        },
    },
};
