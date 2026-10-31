import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import {
  ProcessInstallation,
  ProcessInstallationMaterial,
} from "../processes-schedules/process-installation.ts";
import {
  installWebSocketRuntime,
  removeWebSocketRuntime,
  WebSocketRuntime,
} from "./role_relocate_direct_local.ts";

import { metricsRuntimeActions } from "../integrations/metrics_runtime_actions.ts";
import { runMetricsProcess } from "../integrations/metrics_system.ts";
import { processSystem } from "../processes-schedules/process-system.ts";
import type { SystemCommand } from "../processes-schedules/system.ts";
import { initializePlausible } from "../analytics/initialization.ts";
import { configureClickhouse } from "../analytics/clickhouse.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Identity = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: Digest,
});
const Base = Identity.extend({
  assignment_id: z.number().int().positive(),
  material: z.object({
    "$secret": z.literal("node.role_runtime"),
    id: z.number().int().positive(),
  }).strict(),
});
const Fleet = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: Digest,
  address: z.ipv4(),
  name: z.string().min(1),
  selected: z.boolean(),
  caddy: z.boolean(),
  fpm: z.boolean(),
  hosts: z.array(z.string()),
  instance_ids: z.array(z.number().int().positive()),
}).strict();
export const RoleRuntime = z.discriminatedUnion("role", [
  Base.extend({
    role: z.literal("metrics"),
    address: z.ipv4(),
    targets: z.array(
      z.object({ name: z.string().min(1), address: z.ipv4() }).strict(),
    ),
    fleet: z.array(Fleet),
  }).strict(),
  Base.extend({
    role: z.literal("analytics"),
    installation: ProcessInstallation,
    health_url: z.string().url(),
    clickhouse: Identity.extend({ process_id: z.number().int().positive() })
      .strict(),
  }).strict(),
  WebSocketRuntime.extend({
    role: z.literal("websocket"),
    assignment_id: z.number().int().positive(),
    material: Base.shape.material,
  }).strict(),
]);
export const RoleRuntimeRemoval = z.discriminatedUnion("role", [
  Identity.extend({
    role: z.literal("metrics"),
    address: z.ipv4(),
    purge_data: z.boolean(),
  }).strict(),
  Identity.extend({
    role: z.literal("analytics"),
    process_id: z.number().int().positive(),
    name: z.string().regex(/^orbit-process-[0-9]+-plausible$/),
    purge_data: z.boolean(),
  }).strict(),
  Identity.extend({
    role: z.literal("websocket"),
    install_path: z.string().startsWith("/"),
    purge_data: z.boolean(),
  }).strict(),
]);
type Outcome = { completed: true; changed?: boolean } | { error_code: string };
const Configuration = z.record(
  z.string().regex(/^\/etc\/orbit\/metrics\/[A-Za-z0-9_./-]+$/),
  z.string(),
);
const RuntimeMaterial = z.object({
  configuration: Configuration,
  password: z.string().min(1),
}).strict();

export type MetricsRuntimeActions = Pick<
  ReturnType<typeof metricsRuntimeActions>,
  "runtime" | "credential"
>;

function metricsRuntime(signal?: AbortSignal): MetricsRuntimeActions {
  return metricsRuntimeActions({
    run: (program, args, options) => {
      signal?.throwIfAborted();
      return runMetricsProcess(program, args, { ...options, signal });
    },
    fetch: (url, options) => {
      signal?.throwIfAborted();
      const combined = signal && options?.signal
        ? AbortSignal.any([signal, options.signal])
        : signal ?? options?.signal;
      return fetch(url, { ...options, signal: combined });
    },
  });
}

export type NativeRuntimeActions = {
  process: ReturnType<typeof processSystem>;
  initialize: typeof initializePlausible;
};

function systemCommand(transport: SystemTransport): SystemCommand {
  return async (command, args, stdin, signal, timeoutMs = 300000) => {
    const result = await transport({ command, args, stdin, timeoutMs }, signal);
    return { code: result.success ? 0 : 1, stdout: result.stdout };
  };
}
function nativeRuntime(transport: SystemTransport): NativeRuntimeActions {
  return {
    process: processSystem(systemCommand(transport)),
    initialize: initializePlausible,
  };
}

/** Called by its own exact-Node placed step before the Analytics server stage. */
export async function configureRoleClickhouse(
  raw: unknown,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<Outcome> {
  Identity.extend({ process_id: z.number().int().positive() }).strict().parse(
    raw,
  );
  signal?.throwIfAborted();
  const result = await configureClickhouse(systemCommand(transport), signal);
  return "error_code" in result
    ? { error_code: Code.parse(result.error_code) }
    : { completed: true, changed: result.changed };
}

/** The root owns publication and participants; each invocation affects only its placed Node. */
export async function installRoleRuntime(
  raw: unknown,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
  metrics: MetricsRuntimeActions = metricsRuntime(signal),
  native: NativeRuntimeActions = nativeRuntime(transport),
): Promise<Outcome> {
  const input = RoleRuntime.parse(raw);
  if (input.material.id !== input.node_id) {
    throw new Error("Runtime material belongs to another Node");
  }
  signal?.throwIfAborted();
  if (
    input.role === "analytics" && (
      input.installation.agent_sha256 !== input.agent_sha256 ||
      input.installation.runtime !== "docker" ||
      input.installation.desired !== "running" ||
      input.installation.on_demand ||
      input.installation.name !==
        `orbit-process-${input.installation.process_id}-plausible`
    )
  ) throw new Error("Analytics installation differs from its admitted Node");
  const label = {
    websocket: "WebSocket",
    analytics: "Analytics",
    metrics: "Metrics",
  }[input.role];
  let outcome: Outcome | undefined;
  try {
    await gateway.withPrivateMaterial(async (resolved) => {
      const rawMaterial = z.record(z.string(), z.unknown()).parse(
        resolved.runtime_material,
      );
      if (input.role === "websocket") {
        if (signal?.aborted) {
          outcome = { error_code: "operation.cancelled" };
          return;
        }
        outcome = await installWebSocketRuntime(
          {
            node_id: input.node_id,
            agent_sha256: input.agent_sha256,
            user: input.user,
            install_path: input.install_path,
            repository: input.repository,
            ref: input.ref,
            port: input.port,
          },
          rawMaterial,
          signal,
          transport,
        );
        return;
      }
      if (input.role === "analytics") {
        // The public checks above require a Docker installation before this callback.
        const installation = input.installation as Extract<
          z.infer<typeof ProcessInstallation>,
          { runtime: "docker" }
        >;
        const material = ProcessInstallationMaterial.parse(
          rawMaterial.installation,
        );
        if (
          material.runtime !== "docker" ||
          material.spec !== installation.spec ||
          JSON.stringify(material.definition.command) !==
            JSON.stringify(["/entrypoint.sh", "run"])
        ) {
          throw new Error(
            "Analytics private installation differs from admission",
          );
        }
        signal?.throwIfAborted();
        if (installation.retired !== null) {
          const removed = await native.process("process.remove", {
            process_id: installation.process_id,
            ...installation.retired,
            vite: null,
          }, signal);
          if (removed.error_code !== undefined) {
            outcome = { error_code: Code.parse(removed.error_code) };
            return;
          }
        }
        const { environment, ...definition } = material.definition;
        const desired = {
          ...definition,
          process_id: installation.process_id,
          container: installation.name,
          desired: "running" as const,
          spec: installation.spec,
        };
        const effect = await native.process(
          "process.converge-container",
          {
            ...desired,
            environment,
            health: {
              url: input.health_url,
              attempts: 90,
              interval_seconds: 2,
            },
          },
          signal,
          async () =>
            await native.initialize(
              desired,
              environment,
              native.process,
              signal,
              systemCommand(transport),
            ),
        );
        if (effect.error_code !== undefined) {
          outcome = { error_code: Code.parse(effect.error_code) };
          return;
        }
        const status = await native.process("process.status", {
          process_id: installation.process_id,
          runtime: "docker",
          name: installation.name,
        }, signal);
        if (status.error_code !== undefined) {
          outcome = { error_code: Code.parse(status.error_code) };
          return;
        }
        if (status.state !== "running") {
          outcome = { error_code: "analytics.runtime_not_ready" };
          return;
        }
        outcome = { completed: true };
        return;
      }
      const material = RuntimeMaterial.parse(rawMaterial);
      signal?.throwIfAborted();
      const converged = await metrics.runtime({
        address: input.address,
        action: {
          operation: "converge",
          assignment: input.assignment_id,
          configuration: material.configuration,
        },
      });
      if (converged.error_code !== undefined) {
        outcome = { error_code: Code.parse(converged.error_code) };
        return;
      }
      const changed =
        z.object({ changed: z.boolean() }).parse(converged).changed;
      signal?.throwIfAborted();
      const verified = await metrics.credential({
        address: input.address,
        action: { operation: "verify", password: material.password },
      });
      if (verified.error_code !== undefined) {
        outcome = { error_code: Code.parse(verified.error_code) };
        return;
      }
      if (verified.verified !== true) {
        outcome = { error_code: "metrics.credentials_unverified" };
        return;
      }
      outcome = { completed: true, changed };
    });
  } catch {
    throw new Error(`Private ${label} installation did not complete`);
  }
  if (outcome === undefined) {
    throw new Error(`${label} runtime outcome is uncertain`);
  }
  return outcome;
}

/** Fixed teardown commands use public identity only. Gateway projection forgets owned records. */
export async function removeRoleRuntime(
  raw: unknown,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
  metrics: MetricsRuntimeActions = metricsRuntime(signal),
  native: NativeRuntimeActions = nativeRuntime(transport),
): Promise<Outcome> {
  const input = RoleRuntimeRemoval.parse(raw);
  signal?.throwIfAborted();
  if (input.role === "websocket") {
    return await removeWebSocketRuntime(
      {
        node_id: input.node_id,
        agent_sha256: input.agent_sha256,
        install_path: input.install_path,
        purge: input.purge_data,
      },
      signal,
      transport,
    );
  }
  if (
    input.role === "analytics" &&
    input.name !== `orbit-process-${input.process_id}-plausible`
  ) throw new Error("Analytics Process identity differs");
  if (input.role === "analytics") {
    const result = await native.process("process.remove", {
      process_id: input.process_id,
      runtime: "docker",
      name: input.name,
      vite: null,
    }, signal);
    return result.error_code === undefined
      ? { completed: true }
      : { error_code: Code.parse(result.error_code) };
  }
  const result = await metrics.runtime({
    address: input.address,
    action: { operation: "remove", purge_data: input.purge_data },
  });
  if (result.error_code !== undefined) {
    return { error_code: Code.parse(result.error_code) };
  }
  return {
    completed: true,
    changed: z.object({ changed: z.boolean() }).parse(result).changed,
  };
}
