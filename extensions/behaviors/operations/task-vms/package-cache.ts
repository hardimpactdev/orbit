import { z } from "npm:zod@4.4.3";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { ChildFailed } from "./driver.ts";
import type { FamilyEffects } from "./runtime.ts";

export const CacheFillInput = z.object({
  host_node_id: z.number().int().positive(),
  batches: z.array(
    z.object({
      artifacts: z.array(z.record(z.string(), z.unknown())),
      // Eloquent array casts encode an empty PHP map as []; keep its wire value.
      conflicts: z.union([
        z.record(z.string(), z.string()),
        z.array(z.never()).length(0),
      ]),
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
