import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { ChildFailed } from "./driver.ts";
import type { FamilyEffects } from "./runtime.ts";

export const CacheMountInput = z.object({
  task_vm_id: z.number().int().positive(),
  project_id: z.number().int().positive(),
  host_node_id: z.number().int().positive(),
  guest: z.object({ project: z.string(), name: z.string() }).strict(),
  artifacts_json: z.string(),
  snapshot_digest: z.string().regex(/^[a-f0-9]{64}$/),
  deadline_at: z.number().int().positive(),
  effect_key: z.string().optional(),
}).strict();

export async function preparePackageCache(
  input: z.infer<typeof CacheMountInput>,
  effects: FamilyEffects,
) {
  await effects.child(
    "cache-initialize",
    "task_vm.cache_initialize",
    input.host_node_id,
    {},
  );
  await effects.child(
    "cache-mount",
    "task_vm.cache_mount",
    input.host_node_id,
    input.guest,
  );
  await effects.child("cache-seed", "task_vm.cache_seed", input.host_node_id, {
    guest: input.guest,
    parameters_json: input.artifacts_json,
  });
  return { ready: true };
}

export const CacheFillInput = z.object({
  host_node_id: z.number().int().positive(),
  batches: z.array(
    z.object({
      artifacts: z.array(z.record(z.string(), z.unknown())),
      conflicts: z.record(z.string(), z.string()),
    }).strict(),
  ).min(1).max(128),
  deadline_at: z.number().int().positive(),
}).passthrough();

export async function fillPackageCache(
  input: z.infer<typeof CacheFillInput>,
  effects: FamilyEffects,
) {
  let failed = false;
  for (const [index, batch] of input.batches.entries()) {
    try {
      await effects.child(
        `fill-${index}`,
        "task_vm.fill_package_cache_batch",
        input.host_node_id,
        batch,
      );
    } catch (error) {
      if (!(error instanceof ChildFailed)) throw error;
      failed = true;
    }
  }
  if (failed) throw new ExecutorError("task_vm.package_cache_failed");
  return { ready: true, skipped: [] };
}
