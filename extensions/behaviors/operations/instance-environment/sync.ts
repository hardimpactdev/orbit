import { z } from "npm:zod@4.4.3";
import {
  type EnvironmentActionName,
  type EnvironmentInvoke,
  invokeLocalEnvironment,
} from "./local.ts";
export type { EnvironmentInvoke } from "./local.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import { Input as ProcessInput } from "../processes-schedules/process-input.ts";

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
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  maximum: z.literal(1048576),
  required_capacity: z.number().int().min(1048576),
  preserve_workload_keys: z.boolean(),
  runtime: z.object({
    config: z.object({
      user: Account,
      home: Path,
      version: z.string(),
      application: Path,
    }).strict(),
    php: z.object({
      user: Account,
      home: Path,
      version: z.string(),
      marker: z.string(),
      deadline_seconds: z.literal(30),
    }).strict(),
    processes: z.array(ProcessInput).max(4096),
  }).strict().nullable(),
}).strict().refine((plan) =>
  plan.environment === "production" || plan.runtime === null
);
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
/** Production runtime refresh waits for the PHP owner's native lower action. */
function supportedPlan(raw: unknown) {
  const plan = EnvironmentPlan.parse(raw);
  if (plan.runtime !== null) {
    throw new EnvironmentRefusal("operation.kind_unavailable", "runtime");
  }
  return plan;
}
type Use = Pick<GatewayOperation, "withPrivateMaterial">["withPrivateMaterial"];

/** Callback-local bytes only. Native outputs contain key names and bounded outcomes. */
export async function synchronizeEnvironment(
  raw: unknown,
  use: Use,
  invoke: EnvironmentInvoke = invokeLocalEnvironment,
  signal?: AbortSignal,
) {
  const plan = supportedPlan(raw);
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
        const inspection = z.object({
          preflight: z.object({ state: z.string() }),
          existing: z.object({
            state: z.string(),
            content: z.string().nullable().optional(),
          }).nullable(),
        }).parse(await call("instance.inspect-environment-sync", input));
        if (inspection.preflight.state !== "ok") {
          throw new EnvironmentRefusal("env.sync_preflight_failed", stage);
        }
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
  } catch {
    if (refusal !== undefined) throw refusal;
    throw new EnvironmentRefusal("env.sync_unconfirmed", stage);
  } finally {
    accepting = false;
  }
  if (output === undefined) {
    throw new EnvironmentRefusal("env.sync_unconfirmed", stage);
  }
  return output;
}

/** Development needs no runtime refresh. Unsupported production refresh refuses before writes. */
export async function refreshEnvironmentRuntime(
  raw: unknown,
  _invoke: EnvironmentInvoke = invokeLocalEnvironment,
  _signal?: AbortSignal,
) {
  await Promise.resolve();
  const plan = supportedPlan(raw);
  const runtime = plan.runtime;
  if (plan.environment !== "production") {
    return {
      runtime_refresh: "skipped_development",
      restarted_process_ids: [],
    };
  }
  if (runtime === null) {
    return {
      runtime_refresh: "skipped_no_php_version",
      restarted_process_ids: [],
    };
  }
  throw new EnvironmentRefusal("operation.kind_unavailable", "runtime");
}
