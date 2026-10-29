import { z } from "npm:zod@4.4.3";
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
