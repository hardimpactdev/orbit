import {
  ipv4,
  LocalMetricsError,
  metricsSystem,
  runMetricsProcess,
  type Runner,
  sameState,
  type SystemOptions,
} from "./metrics_system.ts";
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
const marker = "# Managed by Orbit: service-metrics\n",
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
function listener(config: string): string {
  const address = config.match(
    /^ {2}listen_addr: ['"]?(\d+\.\d+\.\d+\.\d+:9114)['"]?\s*$/m,
  )?.[1];
  if (!address || !ipv4(address.slice(0, -5))) throw invalid();
  return address;
}
function validate(state: ServiceState) {
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
export function serviceActions(
  run: Runner = runMetricsProcess,
  options: SystemOptions = {},
) {
  const sys = metricsSystem(run, options, "metrics.service_ownership");
  return async (
    action: ServiceAction,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>> => {
    const check = (program: string, args: string[], maxOutputBytes?: number) =>
      sys.check(program, args, "metrics.service_failed", {
        signal,
        maxOutputBytes,
      });
    const systemctl = (args: string[]) => check("/usr/bin/systemctl", args);
    const flag = async (name: string) => {
      const reply = await sys.command("/usr/bin/systemctl", [
        name,
        serviceName,
      ], { signal });
      const text = reply.stdout.trim();
      if (name === "is-active") {
        if (reply.code === 0 && text === "active") return true;
        if (
          reply.code !== 0 && ["inactive", "failed", "unknown"].includes(text)
        ) {
          return false;
        }
      } else {
        if (reply.code === 0 && text === "enabled") return true;
        if (
          reply.code !== 0 && ["disabled", "masked", "not-found"].includes(text)
        ) {
          return false;
        }
      }
      throw new LocalMetricsError("metrics.service_failed");
    };
    const firewall = async () => {
      if (
        !(await check("/usr/sbin/ufw", ["status"])).startsWith("Status: active")
      ) throw invalid();
      const text = await check("/usr/sbin/ufw", ["show", "added"]);
      return { rules: serviceRules(text), text };
    };
    const inspect = async (): Promise<ServiceState> => {
      await sys.parents(directory + "/fpm.yml");
      const folder = await sys.info(directory);
      if (
        folder &&
        (folder.type !== "directory" || (folder.mode & 0o077) !== 0 ||
          await sys.read(directory + "/.orbit-owner", marker, true) !== marker)
      ) throw new LocalMetricsError("metrics.service_ownership");
      const config = await sys.read(directory + "/fpm.yml", marker, true),
        content = await sys.read(unit, marker);
      const item = await sys.info(binary);
      if (
        item &&
        (item.type !== "regular file" ||
          (await check("/usr/bin/sha256sum", ["--", sys.path(binary)])).split(
              /\s/,
            )[0] !== checksum)
      ) throw new LocalMetricsError("metrics.service_ownership");
      const state = {
        config,
        unit: content,
        rules: (await firewall()).rules,
        active: await flag("is-active"),
        enabled: await flag("is-enabled"),
        binary: item !== null,
      };
      if (content === null && (state.active || state.enabled)) {
        throw new LocalMetricsError("metrics.service_ownership");
      }
      return state;
    };
    const setRules = async (desired: string[][]) => {
      const before = await firewall();
      const leading = before.text.split("\n").filter((s) =>
        s.startsWith("ufw ")
      ).slice(0, desired.length).map((s) =>
        s.split(/\s+/).at(-1)!.replace(/^['"]|['"]$/g, "")
      );
      if (
        sameState(before.rules, desired) &&
        sameState(leading, desired.map((r) => r.at(-1)))
      ) return;
      for (const rule of before.rules.toReversed()) {
        await check("/usr/sbin/ufw", ["--force", "delete", ...rule]);
      }
      for (const rule of desired.toReversed()) {
        await check("/usr/sbin/ufw", ["insert", "1", ...rule]);
      }
      if (!sameState((await firewall()).rules, desired)) {
        throw new LocalMetricsError("metrics.service_failed");
      }
    };
    const apply = async (desired: ServiceState) => {
      const before = await inspect();
      if (desired.binary && !before.binary) {
        if (Deno.build.os !== "linux" || Deno.build.arch !== "x86_64") {
          throw invalid();
        }
        await sys.download(
          binary,
          "https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.1/fpm-exporter-linux-amd64",
          checksum,
        );
      }
      if (desired.config !== null && desired.unit !== null) {
        if (before.config !== desired.config || before.unit !== desired.unit) {
          await sys.write(directory + "/fpm.yml", desired.config, "0600");
          await sys.write(unit, desired.unit);
          await systemctl(["daemon-reload"]);
        }
        await setRules(desired.rules);
        if (before.enabled !== desired.enabled) {
          await systemctl([
            desired.enabled ? "enable" : "disable",
            serviceName,
          ]);
        }
        if (desired.active) {
          if (
            before.config !== desired.config || before.unit !== desired.unit ||
            !before.active
          ) await systemctl(["restart", serviceName]);
          const ready = await check("/usr/bin/curl", [
            "--fail",
            "--silent",
            "--noproxy",
            "*",
            "--max-time",
            "12",
            "--max-filesize",
            "8388608",
            `http://${listener(desired.config)}/metrics`,
          ], 8 * 1024 * 1024);
          if (!ready.startsWith("# HELP") || !await flag("is-active")) {
            throw new LocalMetricsError("metrics.service_failed");
          }
        } else if (before.active) await systemctl(["stop", serviceName]);
      } else {
        if (before.unit !== null) {
          await systemctl(["disable", "--now", serviceName]);
          await sys.remove(unit);
          await systemctl(["daemon-reload"]);
        }
        if (before.config !== null) await sys.remove(directory + "/fpm.yml");
        await setRules(desired.rules);
      }
      if (!desired.binary && before.binary) await sys.remove(binary);
      if (!sameState(await inspect(), desired)) {
        throw new LocalMetricsError("metrics.service_failed");
      }
    };
    try {
      if (action.operation !== "snapshot") validate(action.state!);
      const pending = await sys.read(
        directory + "/transaction.json",
        undefined,
        true,
      );
      if (pending !== null) {
        if (action.operation === "snapshot") {
          return { error_code: "metrics.service_recovery_required" };
        }
        let previous: ServiceState;
        try {
          previous = JSON.parse(pending);
          validate(previous);
        } catch {
          throw invalid();
        }
        try {
          await apply(previous);
        } catch (e) {
          if (!(e instanceof LocalMetricsError)) throw e;
          return { error_code: "metrics.service_recovery_failed" };
        }
        await sys.remove(directory + "/transaction.json");
      }
      const before = await inspect();
      if (action.operation === "snapshot") return before;
      validate(before);
      await sys.mkdir(directory, "0700");
      if (await sys.read(directory + "/.orbit-owner", marker, true) === null) {
        await sys.write(directory + "/.orbit-owner", marker, "0600");
      }
      await sys.write(
        directory + "/transaction.json",
        JSON.stringify(before),
        "0600",
      );
      try {
        await apply(action.state!);
      } catch (e) {
        if (!(e instanceof LocalMetricsError)) throw e;
        try {
          await apply(before);
        } catch (recovery) {
          if (!(recovery instanceof LocalMetricsError)) throw recovery;
          return {
            error_code: "metrics.service_recovery_failed",
            restored: false,
          };
        }
        await sys.remove(directory + "/transaction.json");
        return { error_code: e.code, restored: true };
      }
      await sys.remove(directory + "/transaction.json");
      return {};
    } catch (e) {
      if (!(e instanceof LocalMetricsError)) throw e;
      return { error_code: e.code };
    }
  };
}
export const service = serviceActions();
