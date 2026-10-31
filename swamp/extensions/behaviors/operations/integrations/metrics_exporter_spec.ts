import { ipv4, LocalMetricsError } from "./metrics_policy.ts";
export type ExporterKind = "node_exporter" | "cadvisor";
export const cadvisorHash =
  "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87";
export const cadvisorBinary = "/usr/local/bin/orbit-cadvisor";
const disabled =
  "sched,percpu,memory_numa,cpuLoad,diskIO,disk,network,tcp,advtcp,udp,app,process,hugetlb,referenced_memory,cpu_topology,resctrl,cpuset,oom_event,pressure";
export const spec = (kind: ExporterKind) =>
  kind === "node_exporter"
    ? {
      service: "prometheus-node-exporter",
      marker: "# Managed by Orbit: metrics\n",
      port: "9100",
      comment: "orbit:metrics-node-exporter",
      path: "/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf",
      prefix: "metrics.exporter_",
    }
    : {
      service: "orbit-cadvisor",
      marker: "# Managed by Orbit: metrics-cadvisor\n",
      port: "9102",
      comment: "orbit:metrics-cadvisor",
      path: "/etc/systemd/system/orbit-cadvisor.service",
      prefix: "metrics.cadvisor_",
    };
/** Public canonical exporter policy shared by native parents and retained callers. */
export function exporterSpecification(kind: ExporterKind, address: string) {
  const s = spec(kind);
  return {
    ...s,
    configuration: exporterConfiguration(kind, address),
    artifact: kind === "cadvisor"
      ? {
        path: cadvisorBinary,
        url:
          "https://github.com/google/cadvisor/releases/download/v0.60.5/cadvisor-v0.60.5-linux-amd64",
        sha256: cadvisorHash,
      }
      : null,
  };
}
export function exporterConfiguration(kind: ExporterKind, address: string) {
  const s = spec(kind);
  return kind === "node_exporter"
    ? `${s.marker}[Service]\nExecStart=\nExecStart=/usr/bin/prometheus-node-exporter --web.listen-address=${address}:9100 --collector.systemd --collector.systemd.unit-include=orbit-node-worker[.]service\nRestart=always\nRestartSec=2\n`
    : `${s.marker}[Unit]\nDescription=Orbit cAdvisor exporter\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nExecStart=${cadvisorBinary} --listen_ip=${address} --port=9102 --store_container_labels=false --docker_only=false --disable_metrics=${disabled}\nRestart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n`;
}
export function exporterRule(
  text: string,
  kind: ExporterKind,
  address: string,
): string | null {
  const s = spec(kind);
  const fail = () => {
    throw new LocalMetricsError(s.prefix + "firewall_ownership_drift");
  };
  if (!text.split("\n").some((line) => /^Status: active$/i.test(line.trim()))) {
    throw new LocalMetricsError(s.prefix + "firewall_inactive");
  }
  let source: string | null = null;
  for (const line of text.split("\n")) {
    const hash = line.lastIndexOf("#");
    if (hash < 0 || line.slice(hash + 1).trim() !== s.comment) continue;
    const match = line.slice(0, hash).trim().match(
      /^\[\s*([1-9]\d*)\]\s+(.*)$/,
    );
    if (!match || source !== null) return fail();
    let words = match[2].trim().split(/\s+/);
    if (words.length !== 7) return fail();
    if (words[2] === "on" && words[3] === "orbit") {
      words = [...words.slice(0, 2), ...words.slice(4), ...words.slice(2, 4)];
    }
    const host = (value: string) => value.replace(/\/32$/, "");
    if (
      host(words[0]) !== address || words[1] !== s.port + "/tcp" ||
      words.slice(2, 4).join(" ") !== "ALLOW IN" ||
      words.slice(5).join(" ") !== "on orbit" || !ipv4(host(words[4]))
    ) return fail();
    source = host(words[4]);
  }
  return source;
}
