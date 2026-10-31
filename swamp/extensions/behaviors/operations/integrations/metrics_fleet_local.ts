import { exporter, type ExporterAction } from "./metrics_exporter_actions.ts";
import { service, type ServiceAction } from "./metrics_service_actions.ts";
import {
  convergeProductionRuntime,
  inspectProductionPool,
  type LocalPhpRuntimeRunner,
  PhpRuntimeError,
  type ProductionRuntimeIdentity,
  type ProductionRuntimeInput,
} from "../php/runtime_local.ts";
import { metricsTransport } from "./metrics_system.ts";

/** Canonical PHP programs use the same placement-aware transport as Metrics. */
const phpRunner: LocalPhpRuntimeRunner = async (command, signal) => {
  const output = await metricsTransport({
    command: "/usr/bin/sudo",
    args: command.args,
    stdin: command.stdin,
    timeoutMs: 420000,
    maxOutputBytes: 2097152,
  }, signal);
  return { code: output.success ? 0 : 1, stdout: output.stdout };
};

/** Fixed native lower actions; no agent command or Gateway effect dispatch. */
const nativeInvoke: Invoke = (call, signal) => {
  if (
    call.name === "metrics.node-exporter" || call.name === "metrics.cadvisor"
  ) {
    const { address, metrics_address, action } = call.input;
    return exporter(
      call.name === "metrics.node-exporter" ? "node_exporter" : "cadvisor",
      {
        ...(action as ExporterAction),
        address: address as string,
        metrics_address: metrics_address as string,
      },
      signal,
    );
  }
  if (call.name === "metrics.service-runtime") {
    return service(call.input as ServiceAction, signal);
  }
  if (call.name === "php.inspect-production-pool") {
    return inspectProductionPool(
      call.input as ProductionRuntimeIdentity,
      signal,
      phpRunner,
    );
  }
  if (call.name === "php.converge-production-runtime") {
    return convergeProductionRuntime(
      call.input as ProductionRuntimeInput,
      signal,
      phpRunner,
    );
  }
  throw new Error("metrics.action_invalid");
};
type Input = Record<string, unknown>;
export type State = Input;
export type Service = Input & { config: string | null };
export type NodePlan = {
  node_id: number;
  address: string;
  agent_sha256: string;
  worker: boolean;
  selected: boolean;
  caddy_managed: boolean;
  fpm: boolean;
  service: Service;
  pools: {
    instance_id: number;
    inspect: Record<string, unknown>;
    enabled: Record<string, unknown>;
    disabled: Record<string, unknown>;
  }[];
};
export type Snapshot = {
  node_exporter: State;
  cadvisor: State;
  service: Service;
  pools: boolean[];
};
export type Invoke = (
  call: { name: string; input: Input; mutates: boolean },
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
export class Refused extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}

/** Fixed local effects only. Native steps pass their own snapshot outputs. */
export function fleetActions(
  invoke: Invoke = nativeInvoke,
) {
  return async (
    phase: string,
    node: NodePlan,
    metrics: string,
    before?: Snapshot,
    signal?: AbortSignal,
  ) => {
    const call = async (
      name: string,
      input: Record<string, unknown>,
      mutates: boolean,
    ) => {
      let result: Record<string, unknown>;
      try {
        result = await invoke({ name, input, mutates }, signal);
      } catch (error) {
        if (
          error instanceof PhpRuntimeError &&
          error.code !== "php.runtime_execution_failed"
        ) {
          throw new Refused(error.code);
        }
        throw error;
      }
      if (typeof result.error_code === "string") {
        throw new Refused(result.error_code);
      }
      return result;
    };
    const exporter = (part: string, operation: string, state?: State) =>
      call(
        part === "node_exporter" ? "metrics.node-exporter" : "metrics.cadvisor",
        {
          address: node.address,
          metrics_address: metrics,
          action: { operation, ...(state ? { state } : {}) },
        },
        !["snapshot", "actual"].includes(operation),
      );
    const inspect = async (pool: NodePlan["pools"][number]) => {
      const result = await call(
        "php.inspect-production-pool",
        pool.inspect,
        false,
      );
      const expected = (pool.enabled.configuration as { pool: string }).pool
        .match(/^pm\.status_listen = (.+)$/m)?.[0];
      if (
        typeof result.pool !== "string" ||
        typeof result.fingerprint !== "string" || !expected
      ) throw new Error("metrics.pool_unverified");
      return {
        enabled: result.pool.includes("pm.status_path = /orbit-fpm-status\n") &&
          result.pool.includes(expected + "\n"),
        fingerprint: result.fingerprint,
      };
    };
    if (phase === "snapshot") {
      const pools = [];
      for (const pool of node.pools) pools.push((await inspect(pool)).enabled);
      return {
        node_exporter: await exporter("node_exporter", "snapshot"),
        cadvisor: await exporter("cadvisor", "snapshot"),
        service: await call("metrics.service-runtime", {
          operation: "snapshot",
        }, false),
        pools,
      };
    }
    if (phase === "apply") {
      for (const part of ["node_exporter", "cadvisor"]) {
        await exporter(part, node.selected ? "converge" : "remove");
      }
    } else if (phase === "service" || phase === "verify") {
      let config = node.service.config;
      for (const pool of node.pools) {
        if (phase === "service") {
          await call(
            "php.converge-production-runtime",
            node.fpm ? pool.enabled : pool.disabled,
            true,
          );
        }
        const state = await inspect(pool);
        if (state.enabled !== node.fpm) {
          throw new Refused("metrics.pool_unverified");
        }
        if (config !== null) {
          config += `# runtime ${pool.instance_id}: ${state.fingerprint}\n`;
        }
      }
      const desired = { ...node.service, config };
      if (phase === "service") {
        await call("metrics.service-runtime", {
          operation: "apply",
          state: desired,
        }, true);
      } else {
        for (const part of ["node_exporter", "cadvisor"]) {
          if (
            (await exporter(part, "actual")).actual !==
              (node.selected ? "active" : "inactive")
          ) throw new Refused("metrics.exporter_unverified");
        }
        const actual = await call("metrics.service-runtime", {
          operation: "snapshot",
        }, false);
        if (!same(actual, desired)) {
          throw new Refused("metrics.service_unverified");
        }
      }
    } else if (phase === "restore" && before) {
      for (const [index, pool] of node.pools.entries()) {
        await call(
          "php.converge-production-runtime",
          before.pools[index] ? pool.enabled : pool.disabled,
          true,
        );
        if ((await inspect(pool)).enabled !== before.pools[index]) {
          throw new Refused("metrics.rollback_failed");
        }
      }
      await call("metrics.service-runtime", {
        operation: "apply",
        state: before.service,
      }, true);
      for (const part of ["cadvisor", "node_exporter"] as const) {
        await exporter(part, "restore", before[part]);
        if (!same(await exporter(part, "snapshot"), before[part])) {
          throw new Refused("metrics.rollback_failed");
        }
      }
      if (
        !same(
          await call(
            "metrics.service-runtime",
            { operation: "snapshot" },
            false,
          ),
          before.service,
        )
      ) throw new Refused("metrics.rollback_failed");
    } else throw new Error("metrics.stage_invalid");
    return {};
  };
}
function same(a: unknown, b: unknown): boolean {
  if (a === b) return true;
  if (!a || !b || typeof a !== "object" || typeof b !== "object") return false;
  const left = Object.entries(a), right = Object.entries(b);
  return left.length === right.length &&
    left.every(([k, v]) =>
      k in b && same(v, (b as Record<string, unknown>)[k])
    );
}
