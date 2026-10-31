import { z } from "npm:zod@4.4.3";
import { RuntimeInput } from "../php/monitor-command/assets.ts";

const Id = z.number().int().positive();
const IPv4 = z.ipv4();
const Exporter = z.enum(["node_exporter", "cadvisor"]);
const Service = z.object({
    config: z.string().nullable(),
    unit: z.string().nullable(),
    rules: z.array(z.array(z.string())),
    active: z.boolean(),
    enabled: z.boolean(),
    binary: z.boolean(),
});
const Member = z.object({
    node_id: Id,
    address: IPv4,
    selected: z.boolean(),
    worker: z.literal(true),
    fpm: z.boolean(),
    service: Service,
    pools: z.array(
        z.object({
            instance_id: Id,
            inspect: z.object({
                user: z.string(),
                version: z.string(),
                marker: z.string(),
            }).strict(),
            enabled: RuntimeInput,
            disabled: RuntimeInput,
        }).strict(),
    ),
}).passthrough();
export const Intent = z.object({
    participant_node_ids: z.array(Id).min(1).max(256),
    metrics_fleet: z.object({
        schema_version: z.literal(1),
        assignment_id: Id,
        metrics_node_id: Id,
        metrics_address: IPv4,
        digest: z.string().regex(/^[a-f0-9]{64}$/),
        runtime_files: z.record(z.string(), z.string()),
        fleet: z.array(Member).min(1).max(256),
    }),
    caddy_publications: z.array(z.record(z.string(), z.unknown())),
});
const paths = [
    "/etc/orbit/metrics/prometheus.yml",
    "/etc/orbit/metrics/grafana/grafana.ini",
    "/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml",
    "/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml",
    "/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json",
    "/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json",
    "/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json",
];
const disabled =
    "sched,percpu,memory_numa,cpuLoad,diskIO,disk,network,tcp,advtcp,udp,app,process,hugetlb,referenced_memory,cpu_topology,resctrl,cpuset,oom_event,pressure";
export function exporterIntent(
    kind: z.infer<typeof Exporter>,
    member: z.infer<typeof Member>,
    metrics: string,
) {
    const node = kind === "node_exporter";
    const service = node ? "prometheus-node-exporter" : "orbit-cadvisor";
    const marker = node
        ? "# Managed by Orbit: metrics\n"
        : "# Managed by Orbit: metrics-cadvisor\n";
    return {
        key: `${member.node_id}-${kind}`,
        node_id: member.node_id,
        kind,
        selected: member.selected,
        json: JSON.stringify({
            kind,
            service,
            marker,
            address: member.address,
            metrics_address: metrics,
            selected: member.selected,
            path: node
                ? "/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf"
                : "/etc/systemd/system/orbit-cadvisor.service",
            port: node ? "9100" : "9102",
            comment: node
                ? "orbit:metrics-node-exporter"
                : "orbit:metrics-cadvisor",
            configuration: node
                ? `${marker}[Service]\nExecStart=\nExecStart=/usr/bin/prometheus-node-exporter --web.listen-address=${member.address}:9100 --collector.systemd --collector.systemd.unit-include=orbit-node-worker[.]service\nRestart=always\nRestartSec=2\n`
                : `${marker}[Unit]\nDescription=Orbit cAdvisor exporter\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nExecStart=/usr/local/bin/orbit-cadvisor --listen_ip=${member.address} --port=9102 --store_container_labels=false --docker_only=false --disable_metrics=${disabled}\nRestart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n`,
        }),
    };
}
/** Public data shaping only. No transport, commands, credential, or Gateway client. */
export function readIntent(value: unknown) {
    const intent = Intent.parse(value), plan = intent.metrics_fleet;
    const ids = plan.fleet.map((n) => n.node_id);
    if (
        new Set(ids).size !== ids.length ||
        new Set(intent.participant_node_ids).size !== ids.length ||
        ids.some((id) => !intent.participant_node_ids.includes(id)) ||
        !ids.includes(plan.metrics_node_id)
    ) {
        throw new Error("metrics.participant_mismatch");
    }
    if (
        Object.keys(plan.runtime_files).length !== paths.length ||
        paths.some((p) => typeof plan.runtime_files[p] !== "string")
    ) {
        throw new Error("metrics.runtime_files_invalid");
    }
    return {
        ids,
        exporters: plan.fleet.flatMap((n) =>
            Exporter.options.map((kind) =>
                exporterIntent(kind, n, plan.metrics_address)
            )
        ),
        services: plan.fleet.map((n) => ({
            node_id: n.node_id,
            json: JSON.stringify(n.service),
        })),
        service_rules: plan.fleet.flatMap((n) =>
            n.service.rules.map((rule, index) => ({
                key: `${n.node_id}-${index}`,
                node_id: n.node_id,
                json: JSON.stringify(rule),
                deny: rule[0] === "deny",
            }))
        ),
        pools: plan.fleet.flatMap((n) =>
            n.pools.map((p) => ({
                key: `${n.node_id}-${p.instance_id}`,
                node_id: n.node_id,
                instance_id: p.instance_id,
                enabled: n.fpm,
                runtime: n.fpm ? p.enabled : p.disabled,
            }))
        ),
        publications: intent.caddy_publications,
        files: paths.map((path) => ({
            key: paths.indexOf(path),
            path,
            json: JSON.stringify({ path, content: plan.runtime_files[path] }),
        })),
        containers: ["prometheus", "grafana"].map((service) => {
            const prometheus = service === "prometheus";
            const name = `orbit-metrics-${service}`;
            const labels = {
                "com.orbit.managed": "metrics",
                "com.orbit.metrics.service": service,
                "com.orbit.metrics.assignment": String(plan.assignment_id),
            };
            const mounts = prometheus
                ? ["/etc/orbit/metrics/prometheus.yml:/etc/prometheus/prometheus.yml:ro"]
                : [
                    "/etc/orbit/metrics/grafana/grafana.ini:/etc/grafana/grafana.ini:ro",
                    "/etc/orbit/metrics/grafana/provisioning:/etc/grafana/provisioning:ro",
                    "/etc/orbit/metrics/grafana/dashboards:/var/lib/grafana/dashboards:ro",
                    "/etc/orbit/metrics/grafana/admin-password:/run/orbit/grafana-admin-password:ro",
                ];
            const spec = {
                service,
                name,
                backup: name + "-orbit-rollback",
                volume: name + "-data",
                labels,
                image: prometheus
                    ? "prom/prometheus:v3.5.0"
                    : "grafana/grafana:12.1.1",
                mount_target: prometheus ? "/prometheus" : "/var/lib/grafana",
                mounts,
                environment: prometheus ? [] : [
                    "GF_SECURITY_ADMIN_USER=admin",
                    "GF_SECURITY_ADMIN_PASSWORD__FILE=/run/orbit/grafana-admin-password",
                    `GF_SERVER_HTTP_ADDR=${plan.metrics_address}`,
                    "GF_SERVER_HTTP_PORT=3000",
                ],
                health_url: prometheus
                    ? "http://127.0.0.1:9090/-/ready"
                    : `http://${plan.metrics_address}:3000/api/health`,
                command: prometheus
                    ? [
                        "--config.file=/etc/prometheus/prometheus.yml",
                        "--storage.tsdb.path=/prometheus",
                        "--storage.tsdb.retention.time=7d",
                        "--web.listen-address=127.0.0.1:9090",
                    ]
                    : [],
                configuration: prometheus
                    ? [[paths[0], plan.runtime_files[paths[0]]]]
                    : paths.slice(1, 4).map(
                        (path) => [path, plan.runtime_files[path]],
                    ),
            };
            return {
                key: service,
                node_id: plan.metrics_node_id,
                json: JSON.stringify(spec),
            };
        }),
        metrics_address: plan.metrics_address,
        assignment_id: plan.assignment_id,
        owner_node_id: plan.metrics_node_id,
    };
}
const PoolObservation = z.object({
    present: z.literal(true),
    enabled: z.boolean(),
    fingerprint: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
export function serviceFilesWithPools(raw: {
    intent: unknown;
    observations: { key: string; stdout: string }[];
}) {
    const intent = Intent.parse(raw.intent);
    const pools = readIntent(intent).pools;
    if (
        raw.observations.length !== pools.length ||
        new Set(raw.observations.map((item) => item.key)).size !==
            pools.length ||
        raw.observations.some((item) =>
            !pools.some((pool) => pool.key === item.key)
        )
    ) {
        throw new Error("metrics.pool_unverified");
    }
    const states = new Map(raw.observations.map((item) => {
        const parsed = PoolObservation.safeParse(JSON.parse(item.stdout));
        const pool = pools.find((pool) => pool.key === item.key)!;
        if (!parsed.success || parsed.data.enabled !== pool.enabled) {
            throw new Error("metrics.pool_unverified");
        }
        return [item.key, parsed.data] as const;
    }));
    const services: { node_id: number; json: string }[] = [];
    const files = intent.metrics_fleet.fleet.flatMap((node) => {
        let config = node.service.config;
        if (config !== null) {
            for (const pool of node.pools) {
                config += `# runtime ${pool.instance_id}: ${
                    states.get(`${node.node_id}-${pool.instance_id}`)!
                        .fingerprint
                }\n`;
            }
        }
        services.push({
            node_id: node.node_id,
            json: JSON.stringify({ ...node.service, config }),
        });
        return [
            {
                key: `${node.node_id}-config`,
                node_id: node.node_id,
                json: JSON.stringify({
                    path: "/etc/orbit/service-metrics/fpm.yml",
                    content: config,
                    mode: 384,
                }),
            },
            {
                key: `${node.node_id}-unit`,
                node_id: node.node_id,
                json: JSON.stringify({
                    path: "/etc/systemd/system/orbit-fpm-exporter.service",
                    content: node.service.unit,
                    mode: 420,
                }),
            },
        ];
    });
    return { files, services };
}
const Rule = z.array(z.string());
export function planServiceRules(
    raw: { intent: unknown; snapshots: { node_id: number; stdout: string }[] },
) {
    const intent = Intent.parse(raw.intent);
    const removals: { key: string; node_id: number; json: string }[] = [];
    const additions = intent.metrics_fleet.fleet.flatMap((n) =>
        n.service.rules.map((rule, index) => ({
            key: `${n.node_id}-${index}`,
            node_id: n.node_id,
            json: JSON.stringify(rule),
            deny: rule[0] === "deny",
        }))
    );
    if (
        raw.snapshots.length !== intent.metrics_fleet.fleet.length ||
        new Set(raw.snapshots.map((s) => s.node_id)).size !==
            raw.snapshots.length
    ) throw new Error("metrics.firewall_snapshot_invalid");
    for (const snapshot of raw.snapshots) {
        const member = intent.metrics_fleet.fleet.find((n) =>
            n.node_id === snapshot.node_id
        );
        if (!member) throw new Error("metrics.participant_mismatch");
        const rules = z.array(Rule).parse(JSON.parse(snapshot.stdout).rules);
        for (const [index, rule] of rules.entries()) {
            const match = rule[13]?.match(
                /^orbit:metrics-service-(fpm|caddy)-(allow|deny)$/,
            );
            if (
                rule.length !== 14 || !match || rule[0] !== match[2] ||
                rule.slice(1, 7).join(" ") !== "in on orbit proto tcp from" ||
                rule[8] !== "to" || rule[9] !== member.address ||
                rule[10] !== "port" ||
                rule[11] !== (match[1] === "fpm" ? "9114" : "9103") ||
                rule[12] !== "comment" || (rule[0] === "deny"
                    ? rule[7] !== "any"
                    : !IPv4.safeParse(rule[7]).success)
            ) {
                throw new Error("metrics.service_firewall_ownership_drift");
            }
            removals.push({
                key: `${snapshot.node_id}-${index}`,
                node_id: snapshot.node_id,
                json: JSON.stringify(rule),
            });
        }
    }
    return {
        removals,
        deny: additions.filter((r) => r.deny).reverse(),
        allow: additions.filter((r) => !r.deny).reverse(),
    };
}
export const ScriptFiles: Record<string, string> = {
    "verify-existing-credential": "scripts/verify-existing-credential.py",
    "inspect-exporter-files": "scripts/inspect-exporter-files.py",
    "inspect-exporter-service": "scripts/inspect-exporter-service.py",
    "inspect-exporter-firewall": "scripts/inspect-exporter-firewall.py",
    "inspect-fpm-exporter": "scripts/inspect-fpm-exporter.py",
    "inspect-service-firewall": "scripts/inspect-service-firewall.py",
    "inspect-runtime-container": "scripts/inspect-runtime-container.py",
    "check-runtime-recovery": "scripts/check-runtime-recovery.py",
    "pull-runtime-image": "scripts/pull-runtime-image.py",
    "validate-prometheus-config": "scripts/validate-prometheus-config.py",
    "inspect-runtime-files": "scripts/inspect-runtime-files.py",
    "inspect-exporter-binaries": "scripts/inspect-exporter-binaries.py",
    "download-cadvisor": "scripts/download-cadvisor.py",
    "verify-cadvisor-digest": "scripts/verify-cadvisor-digest.py",
    "publish-cadvisor-binary": "scripts/publish-cadvisor-binary.py",
    "stop-exporter": "scripts/stop-exporter.py",
    "disable-exporter": "scripts/disable-exporter.py",
    "publish-exporter-configuration":
        "scripts/publish-exporter-configuration.py",
    "enable-exporter": "scripts/enable-exporter.py",
    "restart-exporter": "scripts/restart-exporter.py",
    "remove-obsolete-exporter-rule": "scripts/remove-obsolete-exporter-rule.py",
    "admit-exporter-rule": "scripts/admit-exporter-rule.py",
    "verify-exporter-endpoint": "scripts/verify-exporter-endpoint.py",
    "stop-fpm-exporter": "scripts/stop-fpm-exporter.py",
    "disable-fpm-exporter": "scripts/disable-fpm-exporter.py",
    "publish-fpm-exporter-file": "scripts/publish-fpm-exporter-file.py",
    "download-fpm-exporter": "scripts/download-fpm-exporter.py",
    "verify-fpm-exporter-digest": "scripts/verify-fpm-exporter-digest.py",
    "publish-fpm-exporter-binary": "scripts/publish-fpm-exporter-binary.py",
    "enable-fpm-exporter": "scripts/enable-fpm-exporter.py",
    "restart-fpm-exporter": "scripts/restart-fpm-exporter.py",
    "remove-service-rule": "scripts/remove-service-rule.py",
    "admit-service-deny": "scripts/admit-service-deny.py",
    "admit-service-allow": "scripts/admit-service-allow.py",
    "verify-fpm-exporter": "scripts/verify-fpm-exporter.py",
    "remove-cadvisor-binary": "scripts/remove-cadvisor-binary.py",
    "remove-fpm-exporter-binary": "scripts/remove-fpm-exporter-binary.py",
    "publish-runtime-files": "scripts/publish-runtime-files.py",
    "publish-grafana-password": "scripts/publish-grafana-password.py",
    "create-runtime-volume": "scripts/create-runtime-volume.py",
    "stop-runtime-container": "scripts/stop-runtime-container.py",
    "preserve-runtime-container": "scripts/preserve-runtime-container.py",
    "create-runtime-container": "scripts/create-runtime-container.py",
    "start-runtime-container": "scripts/start-runtime-container.py",
    "verify-runtime-health": "scripts/verify-runtime-health.py",
    "verify-final-credential": "scripts/verify-final-credential.py",
    "retire-runtime-backup": "scripts/retire-runtime-backup.py",
    "result": "scripts/result.py",
};
export const behavior = {
    type: "@nckrtl/metrics-fleet-intent",
    version: "2026.10.31.1",
    resources: {
        intent: {
            schema: z.record(z.string(), z.unknown()),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description:
                "Resolve shipped one-action script paths on this exact worker",
            arguments: z.object({}),
            execute: async (
                _args: Record<string, never>,
                ctx: {
                    extensionFile: (name: string) => string;
                    writeResource: (
                        kind: string,
                        name: string,
                        value: unknown,
                    ) => Promise<unknown>;
                },
            ) => {
                const result = {
                    scripts: Object.fromEntries(
                        Object.entries(ScriptFiles).map((
                            [key, path],
                        ) => [key, ctx.extensionFile(path)]),
                    ),
                };
                await ctx.writeResource("intent", "assets", result);
                return result;
            },
        },
        serviceFiles: {
            description:
                "Bind confirmed public PHP pool fingerprints to exporter configuration",
            arguments: z.object({
                intent: Intent,
                observations: z.array(
                    z.object({ key: z.string(), stdout: z.string() }).strict(),
                ),
            }).strict(),
            execute: async (
                args: {
                    intent: unknown;
                    observations: { key: string; stdout: string }[];
                },
                ctx: {
                    writeResource: (
                        kind: string,
                        name: string,
                        value: unknown,
                    ) => Promise<unknown>;
                },
            ) => {
                const result = serviceFilesWithPools(args);
                await ctx.writeResource("intent", "service-files", result);
                return result;
            },
        },
        serviceRules: {
            description:
                "Read managed service-firewall snapshots and public desired rules",
            arguments: z.object({
                intent: Intent,
                snapshots: z.array(
                    z.object({ node_id: Id, stdout: z.string() }),
                ),
            }),
            execute: async (
                args: {
                    intent: z.infer<typeof Intent>;
                    snapshots: { node_id: number; stdout: string }[];
                },
                ctx: {
                    writeResource: (
                        kind: string,
                        name: string,
                        value: unknown,
                    ) => Promise<unknown>;
                },
            ) => {
                const result = planServiceRules(args);
                await ctx.writeResource("intent", "rules", result);
                return result;
            },
        },
        read: {
            description: "Read complete admitted public Metrics intent",
            arguments: z.object({ intent: Intent }),
            execute: async (args: { intent: z.infer<typeof Intent> }, ctx: {
                writeResource: (
                    kind: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                const result = readIntent(args.intent);
                await ctx.writeResource("intent", "intent", result);
                return result;
            },
        },
    },
};
