import { z } from "npm:zod@4.4.3";
import {
  type EnvironmentActionName,
  type EnvironmentInvoke,
  invokeLocalEnvironment,
} from "./local.ts";
export type { EnvironmentInvoke } from "./local.ts";
import {
  type GatewayOperation,
  DirectOperationRefused,
} from "../_lib/direct.ts";
import { instanceActions } from "../processes-schedules/instance.ts";
import { StartInput } from "../processes-schedules/start.ts";
import { refreshProductionCache } from "../php/runtime_local.ts";
import { ConfigCacheInput, refreshConfigCache } from "./runtime_cache.ts";

const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
const Key = z.string().regex(/^[A-Za-z_][A-Za-z0-9_.]*$/).max(255);
const Path = z.string().startsWith("/").max(4096);
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
export const EnvironmentPlan = z.object({
  instance_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  environment: z.enum(["development", "production"]),
  base: Path,
  user: Account,
  maximum: z.literal(1048576),
  required_capacity: z.number().int().min(1048576),
  preserve_workload_keys: z.boolean(),
  runtime: z.object({
    config: ConfigCacheInput.nullable(),
    php: z.object({
      user: Account,
      home: Path,
      version: z.string().regex(/^[0-9]+\.[0-9]+$/),
      marker: z.string(),
      deadline_seconds: z.literal(30),
    }).strict().nullable(),
    processes: z.array(
      z.object({
        id: z.number().int().positive(),
        input: StartInput,
      }).strict(),
    ).max(4096),
  }).strict().nullable(),
}).strict().superRefine((plan, ctx) => {
  const runtime = plan.runtime;
  if (runtime === null) return;
  if (
    plan.environment !== "production" ||
    (runtime.config === null) !== (runtime.php === null) ||
    (runtime.config !== null && (runtime.config.user !== plan.user ||
      runtime.config.home !== plan.base || runtime.php!.user !== plan.user ||
      runtime.php!.home !== plan.base ||
      runtime.config.version !== runtime.php!.version)) ||
    new Set(runtime.processes.map((member) => member.id)).size !==
      runtime.processes.length ||
    runtime.processes.some((member) =>
      member.id !== member.input.process_id ||
      member.input.vite !== null || member.input.vite_plan !== undefined
    )
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Environment runtime placement differs",
    });
  }
});

const Material = z.object({
  content: z.string(),
  keys: z.array(Key).max(16384),
  owned_keys: z.array(Key).max(16384),
  testing: z.object({
    content: z.string(),
    managed_keys: z.array(Key).max(16384),
    test_database: z.string().max(255),
  }).strict().nullable(),
}).strict();
export class EnvironmentRefusal extends Error {
  constructor(public code: string, public stage: string) {
    super("Instance environment action refused");
  }
}
type Use = Pick<GatewayOperation, "withPrivateMaterial">["withPrivateMaterial"];

/** Callback-local bytes only. Native outputs contain key names and bounded outcomes. */
export async function synchronizeEnvironment(
  raw: unknown,
  use: Use,
  invoke: EnvironmentInvoke = invokeLocalEnvironment,
  signal?: AbortSignal,
) {
  const plan = EnvironmentPlan.parse(raw);
  let accepting = true, attempted = false;
  let output: Record<string, unknown> | undefined;
  let stage = "material";
  let refusal: EnvironmentRefusal | undefined;
  try {
    await use(async (rawMaterial) => {
      if (!accepting || attempted) {
        throw new Error("Private callback already consumed");
      }
      attempted = true;
      try {
        signal?.throwIfAborted();
        const material = Material.parse(rawMaterial.environment_material);
        if (
          new TextEncoder().encode(material.content).length > plan.maximum ||
          (material.testing !== null &&
            new TextEncoder().encode(material.testing.content).length >
              plan.maximum)
        ) {
          throw new EnvironmentRefusal("env.configuration_invalid", stage);
        }
        const input = {
          base: plan.base,
          user: plan.user,
          maximum: plan.maximum,
          require_home: plan.environment === "production",
          required_capacity: plan.required_capacity,
        };
        const call = async (
          name: EnvironmentActionName,
          data: Record<string, unknown>,
        ) => {
          if (!accepting) throw new Error("Private callback already closed");
          signal?.throwIfAborted();
          const value = await invoke({
            name,
            input: data,
          }, signal);
          if (value.error_code !== undefined) {
            throw new EnvironmentRefusal(Code.parse(value.error_code), stage);
          }
          return value;
        };
        stage = "preflight";
        const inspected = await call("instance.inspect-environment-sync", input);
        // The program answers a refused check with `state: refused`; inspection never writes.
        if (inspected.state === "refused") {
          throw new EnvironmentRefusal("env.sync_preflight_failed", stage);
        }
        const inspection = z.object({
          preflight: z.object({ state: z.literal("ok") }),
          existing: z.object({
            state: z.string(),
            content: z.string().nullable().optional(),
          }).nullable(),
        }).parse(inspected);
        if (inspection.existing?.state === "ok") {
          const encoded = inspection.existing.content;
          if (typeof encoded !== "string") {
            throw new EnvironmentRefusal("env.import_preflight_failed", stage);
          }
          const bytes = Uint8Array.from(atob(encoded), (c) => c.charCodeAt(0));
          if (bytes.length > plan.maximum) {
            throw new EnvironmentRefusal("env.import_preflight_failed", stage);
          }
          if (plan.preserve_workload_keys) {
            const contents = new TextDecoder("utf-8", { fatal: true }).decode(
              bytes,
            );
            const keys = [
              ...contents.matchAll(
                /^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_.]*)[ \t]*=/gm,
              ),
            ].map((match) => match[1]);
            if (
              keys.some((key) =>
                !material.keys.includes(key) &&
                !material.owned_keys.includes(key)
              )
            ) {
              throw new EnvironmentRefusal("env.sync_would_drop_keys", stage);
            }
          }
        } else if (inspection.existing?.state !== "missing") {
          throw new EnvironmentRefusal("env.import_preflight_failed", stage);
        }
        const confirmed = (value: Record<string, unknown>, testing = false) => {
          if (
            !["changed", "unchanged", ...(testing ? ["tracked"] : [])].includes(
              String(value.state),
            )
          ) {
            throw new EnvironmentRefusal(
              value.state === "tracking_unknown"
                ? "env.testing_tracking_unknown"
                : "env.sync_unconfirmed",
              stage,
            );
          }
          return value.state === "changed";
        };
        stage = "environment";
        const environmentChanged = confirmed(
          await call("instance.write-environment", {
            ...input,
            mode: "write",
            file: ".env",
            managed_keys: [],
            content: material.content,
          }),
        );
        let changed = environmentChanged, testingStatus: string | null = null;
        stage = "testing";
        if (material.testing !== null) {
          const testing = await call("instance.write-environment", {
            ...input,
            mode: "merge",
            file: ".env.testing",
            managed_keys: material.testing.managed_keys,
            content: material.testing.content,
          });
          changed = confirmed(testing, true) || changed;
          testingStatus = testing.state === "tracked"
            ? "skipped_tracked"
            : testing.state === "changed"
            ? "written"
            : "unchanged";
        }
        if (!accepting) throw new Error("Private callback already closed");
        output = {
          instance_id: plan.instance_id,
          changed,
          environment_changed: environmentChanged,
          keys: material.keys,
          key_count: material.keys.length,
          testing_status: testingStatus,
          test_database: material.testing?.test_database ?? null,
        };
      } catch (error) {
        if (error instanceof EnvironmentRefusal) refusal = error;
        throw new Error("Environment private callback failed");
      }
    });
  } catch (error) {
    if (refusal !== undefined) throw refusal;
    // The Gateway refused the material, for example env.reference_unavailable; nothing was written.
    if (error instanceof DirectOperationRefused) {
      throw new EnvironmentRefusal(error.code, stage);
    }
    throw new EnvironmentRefusal("env.sync_unconfirmed", stage);
  } finally {
    accepting = false;
  }
  if (output === undefined) {
    throw new EnvironmentRefusal("env.sync_unconfirmed", stage);
  }
  return output;
}

export type RuntimeActions = {
  config: typeof refreshConfigCache;
  php: typeof refreshProductionCache;
  processes: ReturnType<typeof instanceActions>;
};
const runtimeActions: RuntimeActions = {
  config: refreshConfigCache,
  php: refreshProductionCache,
  processes: instanceActions(),
};

/** Reuse the PHP and Process owners inside the environment root; no child admission. */
export async function refreshEnvironmentRuntime(
  raw: unknown,
  actions: RuntimeActions = runtimeActions,
  signal?: AbortSignal,
) {
  const plan = EnvironmentPlan.parse(raw);
  if (plan.environment !== "production") {
    return {
      runtime_refresh: "skipped_development",
      restarted_process_ids: [],
    };
  }
  const runtime = plan.runtime;
  if (runtime === null) {
    return {
      runtime_refresh: "skipped_no_php_version",
      restarted_process_ids: [],
    };
  }
  let runtimeRefresh = "skipped_no_php_version";
  if (runtime.config !== null && runtime.php !== null) {
    let config;
    try {
      config = await actions.config(runtime.config, signal);
    } catch {
      throw new EnvironmentRefusal(
        "env.config_cache_refresh_failed",
        "config_cache",
      );
    }
    if (config.outcome === "no_current_release") {
      return {
        runtime_refresh: "skipped_no_current_release",
        restarted_process_ids: [],
      };
    }
    try {
      await actions.php(runtime.php, signal);
    } catch {
      throw new EnvironmentRefusal(
        "env.php_runtime_refresh_failed",
        "php_runtime",
      );
    }
    runtimeRefresh = config.outcome === "config_cached"
      ? "config_cache_and_opcache_refreshed"
      : "opcache_refreshed_config_uncached";
  }
  const restarted: number[] = [];
  try {
    const prepared = await actions.processes.prepare(
      runtime.processes,
      signal,
    );
    if (!prepared.proceed) throw new Error();
    for (const member of runtime.processes) {
      const result = await actions.processes.apply(
        member,
        "skipped",
        signal,
      );
      if ("error_code" in result) throw new Error();
      restarted.push(member.id);
    }
  } catch {
    throw new EnvironmentRefusal(
      "env.runtime_refresh_failed",
      "process_restart",
    );
  }
  return { runtime_refresh: runtimeRefresh, restarted_process_ids: restarted };
}
