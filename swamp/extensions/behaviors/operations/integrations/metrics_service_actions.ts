import {
  listener,
  metricsServiceSpecification,
  type ServiceAction,
  serviceRule,
  serviceRules,
  type ServiceState,
  validateServiceState,
} from "./metrics_service_spec.ts";
export { serviceRule, serviceRules } from "./metrics_service_spec.ts";
export type { ServiceAction, ServiceState } from "./metrics_service_spec.ts";
const {
  marker,
  service: serviceName,
  directory,
  unit_path: unit,
  artifact: { path: binary, sha256: checksum },
} = metricsServiceSpecification();
const invalid = () => new LocalMetricsError("metrics.service_invalid");
import {
  ipv4,
  LocalMetricsError,
  metricsSystem,
  runMetricsProcess,
  type Runner,
  sameState,
  type SystemOptions,
} from "./metrics_system.ts";
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
      if (action.operation !== "snapshot") validateServiceState(action.state!);
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
          validateServiceState(previous);
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
      validateServiceState(before);
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
