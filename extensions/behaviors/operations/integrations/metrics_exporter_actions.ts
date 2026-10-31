import {
  ipv4,
  LocalMetricsError,
  metricsSystem,
  runMetricsProcess,
  type Runner,
  sameState,
  type SystemOptions,
} from "./metrics_system.ts";
export type ExporterKind = "node_exporter" | "cadvisor";
export type ExporterState = {
  configuration: string | null;
  service_active: boolean;
  firewall_source: string | null;
};
export type ExporterAction = {
  operation: "snapshot" | "actual" | "converge" | "remove" | "restore";
  address: string;
  metrics_address?: string;
  state?: ExporterState;
};
const cadvisorHash =
  "6f258bed28113218c88729634c47ead8d928b81b1504b75fd911b98d29003a87";
const cadvisorBinary = "/usr/local/bin/orbit-cadvisor";
const disabled =
  "sched,percpu,memory_numa,cpuLoad,diskIO,disk,network,tcp,advtcp,udp,app,process,hugetlb,referenced_memory,cpu_topology,resctrl,cpuset,oom_event,pressure";
const spec = (kind: ExporterKind) =>
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
export function exporterActions(
  run: Runner = runMetricsProcess,
  options: SystemOptions = {},
) {
  return async (
    kind: ExporterKind,
    action: ExporterAction,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>> => {
    const s = spec(kind),
      sys = metricsSystem(
        run,
        options,
        s.prefix + "configuration_ownership_drift",
      );
    const error = (suffix: string) => new LocalMetricsError(s.prefix + suffix);
    const checked = (
      program: string,
      args: string[],
      suffix: string,
      timeoutMs?: number,
    ) => sys.check(program, args, s.prefix + suffix, { signal, timeoutMs });
    const configuration = () => sys.read(s.path, s.marker);
    const active = async () => {
      const r = await sys.command("/usr/bin/systemctl", [
        "is-active",
        s.service,
      ], { signal });
      if (r.code === 0 && r.stdout.trim() === "active") return true;
      if (
        [1, 3, 4].includes(r.code) &&
        ["inactive", "failed", "unknown"].includes(r.stdout.trim())
      ) return false;
      if ([124, 137].includes(r.code)) {
        throw new Error("metrics.outcome_unknown");
      }
      throw error("service_inspection_failed");
    };
    const firewall = async () =>
      exporterRule(
        await checked(
          "/usr/sbin/ufw",
          ["status", "numbered"],
          "firewall_inspection_failed",
        ),
        kind,
        action.address,
      );
    const snapshot = async (): Promise<ExporterState> => ({
      configuration: await configuration(),
      firewall_source: await firewall(),
      service_active: await active(),
    });
    const setActive = async (desired: boolean) => {
      await checked("/usr/bin/systemctl", [
        desired ? "enable" : "disable",
        "--now",
        s.service,
      ], desired ? "service_failed" : "service_remove_failed");
      if (desired) {
        await checked(
          "/usr/bin/systemctl",
          ["restart", s.service],
          "service_failed",
        );
      }
    };
    const publish = async (value: string | null) => {
      if (value === null) await sys.remove(s.path);
      else {
        await sys.mkdir(s.path.slice(0, s.path.lastIndexOf("/")));
        await sys.write(s.path, value);
      }
      await checked(
        "/usr/bin/systemctl",
        ["daemon-reload"],
        "configuration_failed",
      );
      if (value === null && kind === "cadvisor") {
        await sys.command("/usr/bin/systemctl", ["reset-failed", s.service]);
      }
    };
    const ruleArgs = (
      source: string,
    ) => [
      "allow",
      "in",
      "on",
      "orbit",
      "proto",
      "tcp",
      `from`,
      source,
      "to",
      action.address,
      "port",
      s.port,
      "comment",
      s.comment,
    ];
    const setFirewall = async (desired: string | null) => {
      const before = await firewall();
      if (before === desired) return;
      if (before) {
        await checked("/usr/sbin/ufw", [
          "--force",
          "delete",
          ...ruleArgs(before),
        ], "firewall_remove_failed");
      }
      if (desired) {
        await checked("/usr/sbin/ufw", ruleArgs(desired), "firewall_failed");
      }
    };
    const install = async () => {
      if (kind === "node_exporter") {
        const installed = await sys.command("/usr/bin/dpkg-query", [
          "--show",
          "--showformat=${Status}",
          "--",
          s.service,
        ], { signal });
        if (
          installed.code === 0 &&
          installed.stdout.trim() === "install ok installed"
        ) return;
        await checked(
          "/usr/bin/apt-get",
          [
            "-o",
            "DPkg::Lock::Timeout=60",
            "install",
            "--yes",
            "--no-install-recommends",
            "--",
            s.service,
          ],
          "install_failed",
          300000,
        );
      } else {
        if (Deno.build.os !== "linux" || Deno.build.arch !== "x86_64") {
          throw error("platform_unsupported");
        }
        const present = await sys.info(cadvisorBinary);
        if (present) {
          if (present.type !== "regular file") {
            throw error("binary_inspection_failed");
          }
          const hash = (await checked("/usr/bin/sha256sum", [
            "--",
            sys.path(cadvisorBinary),
          ], "binary_inspection_failed")).split(/\s/)[0];
          if (hash === cadvisorHash) return;
        }
        await sys.download(
          cadvisorBinary,
          "https://github.com/google/cadvisor/releases/download/v0.60.5/cadvisor-v0.60.5-linux-amd64",
          cadvisorHash,
        );
      }
    };
    const restore = async (state: ExporterState) => {
      await configuration();
      await firewall();
      if (
        kind === "cadvisor" && state.configuration !== null &&
        !await sys.info(cadvisorBinary)
      ) await install();
      if (!state.service_active && await active()) await setActive(false);
      if (await configuration() !== state.configuration) {
        await publish(state.configuration);
      }
      if (state.service_active) await setActive(true);
      await setFirewall(state.firewall_source);
      if (!sameState(await snapshot(), state)) {
        throw error("rollback_verify_failed");
      }
    };
    try {
      if (
        !ipv4(action.address) ||
        (action.metrics_address !== undefined &&
          !ipv4(action.metrics_address)) ||
        (["actual", "converge"].includes(action.operation) &&
          !ipv4(action.metrics_address))
      ) throw error("address_invalid");
      if (
        action.operation === "restore" &&
        (!action.state ||
          (action.state.configuration !== null &&
            (typeof action.state.configuration !== "string" ||
              !action.state.configuration.startsWith(s.marker) ||
              action.state.configuration.includes("\0"))) ||
          typeof action.state.service_active !== "boolean" ||
          (action.state.firewall_source !== null &&
            !ipv4(action.state.firewall_source)))
      ) throw error("input_invalid");
      if (action.operation === "actual") {
        try {
          const state = await snapshot();
          return {
            actual:
              state.configuration === null && state.firewall_source === null
                ? "inactive"
                : state.configuration === null ||
                    state.firewall_source !== action.metrics_address
                ? "drift"
                : state.service_active
                ? "active"
                : "inactive",
          };
        } catch (e) {
          if (!(e instanceof LocalMetricsError)) throw e;
          return {
            actual: /ownership|inactive/.test(e.code) ? "drift" : "unknown",
          };
        }
      }
      const before = await snapshot();
      if (action.operation === "snapshot") return before;
      try {
        if (action.operation === "converge") {
          await install();
          const text = kind === "node_exporter"
            ? `${s.marker}[Service]\nExecStart=\nExecStart=/usr/bin/prometheus-node-exporter --web.listen-address=${action.address}:9100 --collector.systemd --collector.systemd.unit-include=orbit-node-worker[.]service\nRestart=always\nRestartSec=2\n`
            : `${s.marker}[Unit]\nDescription=Orbit cAdvisor exporter\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nExecStart=${cadvisorBinary} --listen_ip=${action.address} --port=9102 --store_container_labels=false --docker_only=false --disable_metrics=${disabled}\nRestart=always\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n`;
          await publish(text);
          await setActive(true);
          await setFirewall(action.metrics_address!);
          if (
            !sameState(await snapshot(), {
              configuration: text,
              service_active: true,
              firewall_source: action.metrics_address,
            })
          ) throw error("configuration_verify_failed");
        } else if (action.operation === "remove") {
          if (before.configuration !== null) {
            await setActive(false);
            await publish(null);
          }
          await setFirewall(null);
          const after = await snapshot();
          if (
            after.configuration !== null || after.firewall_source !== null ||
            (before.configuration !== null && after.service_active)
          ) throw error("configuration_remove_verify_failed");
          if (kind === "cadvisor") await sys.remove(cadvisorBinary);
        } else if (action.operation === "restore") await restore(action.state!);
        else throw error("input_invalid");
        return { changed: true };
      } catch (e) {
        if (!(e instanceof LocalMetricsError)) throw e;
        try {
          await restore(before);
        } catch (recovery) {
          if (!(recovery instanceof LocalMetricsError)) throw recovery;
          return { error_code: s.prefix + "rollback_failed", restored: false };
        }
        return { error_code: e.code, restored: true };
      }
    } catch (e) {
      if (!(e instanceof LocalMetricsError)) throw e;
      return { error_code: e.code };
    }
  };
}
export const exporter = exporterActions();
