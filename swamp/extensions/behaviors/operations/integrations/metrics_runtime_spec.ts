/** Pure canonical Metrics container layout and specification hashing. */
export type MetricsService = "prometheus" | "grafana";
export const metricsPaths = [
  "/etc/orbit/metrics/prometheus.yml",
  "/etc/orbit/metrics/grafana/grafana.ini",
  "/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml",
  "/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml",
  "/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json",
  "/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json",
  "/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json",
  "/etc/orbit/metrics/grafana/admin-password",
];
export const name = (s: MetricsService) => `orbit-metrics-${s}`;
export const backup = (s: MetricsService) => `${name(s)}-orbit-rollback`;
export const volume = (s: MetricsService) => `${name(s)}-data`;
export const image = (s: MetricsService) =>
  s === "prometheus" ? "prom/prometheus:v3.5.0" : "grafana/grafana:12.1.1";
const managed = "com.orbit.managed";
export const hashLabel = "com.orbit.metrics.spec-hash";
export const labels = (s: MetricsService, v = false) => ({
  [managed]: "metrics",
  [`com.orbit.metrics.${v ? "volume" : "service"}`]: s,
});

export type Spec = { service: MetricsService; args: string[]; hash: string };

/** Public fixed layout shared by native parents and the retained Metrics implementation. */
export function metricsRuntimeLayout(service: MetricsService) {
  return {
    service,
    name: name(service),
    backup: backup(service),
    volume: volume(service),
    image: image(service),
    labels: labels(service),
    volume_labels: labels(service, true),
  };
}

export async function metricsRuntimeSpec(
  service: MetricsService,
  assignment: number,
  address: string,
  configuration: Record<string, string>,
): Promise<Spec> {
  const args = [
    "container",
    "run",
    "--detach",
    "--pull=never",
    "--name",
    name(service),
    "--restart",
    "unless-stopped",
    "--network",
    "host",
    "--log-driver",
    "json-file",
    "--log-opt",
    "max-size=10m",
    "--log-opt",
    "max-file=3",
  ];
  for (const [key, value] of Object.entries(labels(service)).sort()) {
    args.push("--label", `${key}=${value}`);
  }
  args.push(
    "--label",
    `com.orbit.metrics.assignment=${assignment}`,
    "--mount",
    `type=volume,source=${volume(service)},target=${
      service === "prometheus" ? "/prometheus" : "/var/lib/grafana"
    }`,
  );
  for (
    const mount of service === "prometheus"
      ? [metricsPaths[0] + ":/etc/prometheus/prometheus.yml:ro"]
      : [
        metricsPaths[1] + ":/etc/grafana/grafana.ini:ro",
        "/etc/orbit/metrics/grafana/provisioning:/etc/grafana/provisioning:ro",
        "/etc/orbit/metrics/grafana/dashboards:/var/lib/grafana/dashboards:ro",
        metricsPaths[7] + ":/run/orbit/grafana-admin-password:ro",
      ]
  ) args.push("--volume", mount);
  if (service === "grafana") {
    for (
      const env of [
        "GF_SECURITY_ADMIN_USER=admin",
        "GF_SECURITY_ADMIN_PASSWORD__FILE=/run/orbit/grafana-admin-password",
        `GF_SERVER_HTTP_ADDR=${address}`,
        "GF_SERVER_HTTP_PORT=3000",
      ]
    ) args.push("--env", env);
  }
  const health = service === "prometheus"
    ? "http://127.0.0.1:9090/-/ready"
    : `http://${address}:3000/api/health`;
  args.push(
    "--health-cmd",
    `wget --no-verbose --tries=1 --spider ${health}`,
    "--health-start-period",
    "30s",
    "--health-interval",
    "5s",
    "--health-retries",
    "12",
  );
  const command = service === "prometheus"
    ? [
      "--config.file=/etc/prometheus/prometheus.yml",
      "--storage.tsdb.path=/prometheus",
      "--storage.tsdb.retention.time=7d",
      "--web.listen-address=127.0.0.1:9090",
    ]
    : [];
  const inputs =
    (service === "prometheus"
      ? metricsPaths.slice(0, 1)
      : metricsPaths.slice(1, 4)).map((
        path,
      ) => [path, configuration[path]]);
  const digest = new Uint8Array(
    await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(
        JSON.stringify([args, image(service), command, inputs]),
      ),
    ),
  );
  const hash = Array.from(digest, (b) => b.toString(16).padStart(2, "0"))
    .join("");
  return {
    service,
    hash,
    args: [
      ...args,
      "--label",
      `${hashLabel}=${hash}`,
      "--",
      image(service),
      ...command,
    ],
  };
}
