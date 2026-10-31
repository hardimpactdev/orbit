import { ipv4, LocalMetricsError, sameState } from "./metrics_policy.ts";
export type ServiceState = {
  config: string | null;
  unit: string | null;
  rules: string[][];
  active: boolean;
  enabled: boolean;
  binary: boolean;
};
export type ServiceAction = {
  operation: "snapshot" | "apply" | "restore";
  state?: ServiceState;
};
export const marker = "# Managed by Orbit: service-metrics\n",
  serviceName = "orbit-fpm-exporter";
const directory = "/etc/orbit/service-metrics",
  unit = "/etc/systemd/system/orbit-fpm-exporter.service",
  binary = "/usr/local/bin/orbit-fpm-exporter";
const checksum =
  "570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98";
const invalid = () => new LocalMetricsError("metrics.service_invalid");
export function serviceRule(args: string[]): string[] {
  if (
    args.slice(1, 4).join(" ") !== "in on orbit" ||
    !["allow", "deny"].includes(args[0])
  ) throw invalid();
  const fields: Record<string, string> = {};
  for (let n = 4; n < args.length; n += 2) {
    if (args[n + 1] === undefined || fields[args[n]] !== undefined) {
      throw invalid();
    }
    fields[args[n]] = args[n + 1];
  }
  if (args[0] === "deny" && fields.from === undefined) fields.from = "any";
  const kind = fields.comment?.match(
    /^orbit:metrics-service-(fpm|caddy)-(allow|deny)$/,
  );
  if (
    !kind || kind[2] !== args[0] ||
    Object.keys(fields).sort().join(",") !== "comment,from,port,proto,to" ||
    fields.proto !== "tcp" ||
    fields.port !== (kind[1] === "fpm" ? "9114" : "9103") || !ipv4(fields.to) ||
    (args[0] === "allow" ? !ipv4(fields.from) : fields.from !== "any")
  ) throw invalid();
  return [
    args[0],
    "in",
    "on",
    "orbit",
    "proto",
    "tcp",
    `from`,
    fields.from,
    "to",
    fields.to,
    "port",
    fields.port,
    "comment",
    fields.comment,
  ];
}
export function serviceRules(text: string): string[][] {
  const seen = new Set<string>();
  return text.split("\n").filter((line) =>
    line.includes("orbit:metrics-service-")
  ).map((line) => {
    const words = line.trim().split(/\s+/).map((word) => {
      if (/^['"]/.test(word)) {
        if (word.at(-1) !== word[0]) throw invalid();
        return word.slice(1, -1);
      }
      return word;
    });
    if (words.shift() !== "ufw") throw invalid();
    if (words[0] === "insert") words.splice(0, 2);
    const result = serviceRule(words), key = result.at(-1)!;
    if (seen.has(key)) throw invalid();
    seen.add(key);
    return result;
  });
}
export function listener(config: string): string {
  const address = config.match(
    /^ {2}listen_addr: ['"]?(\d+\.\d+\.\d+\.\d+:9114)['"]?\s*$/m,
  )?.[1];
  if (!address || !ipv4(address.slice(0, -5))) throw invalid();
  return address;
}
export function validateServiceState(state: ServiceState) {
  if (
    !state || (state.config === null) !== (state.unit === null) ||
    (state.config !== null && !state.binary) ||
    (state.config === null && (state.active || state.enabled)) ||
    [state.active, state.enabled, state.binary].some((v) =>
      typeof v !== "boolean"
    )
  ) throw invalid();
  if (state.config !== null) {
    if (
      !state.config.startsWith(marker) || !state.unit!.startsWith(marker) ||
      state.config.includes("\0") || state.unit!.includes("\0")
    ) throw invalid();
    listener(state.config);
  }
  const canonical = serviceRules(
    state.rules.map((row) => "ufw " + serviceRule(row).join(" ")).join("\n"),
  );
  if (!sameState(canonical, state.rules)) throw invalid();
}

export function metricsServiceSpecification() {
  return {
    marker,
    service: serviceName,
    directory,
    config_path: directory + "/fpm.yml",
    unit_path: unit,
    owner_path: directory + "/.orbit-owner",
    artifact: {
      path: binary,
      url:
        "https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.1/fpm-exporter-linux-amd64",
      sha256: checksum,
    },
  };
}
