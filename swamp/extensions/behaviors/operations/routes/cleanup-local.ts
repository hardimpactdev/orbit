import { z } from "npm:zod@4.4.3";
import { routeWorkloadLocal } from "./route-workload-local.ts";

const Writes = z.array(
  z.object({
    instance_id: z.number().int().positive(),
    name: z.enum([
      "instance.write-environment",
      "instance.write-directory-url",
      "instance.write-development-url",
    ]),
    input: z.record(z.string(), z.unknown()),
  }).strict(),
);

type WorkloadActions = Pick<
  ReturnType<typeof routeWorkloadLocal>,
  "prepareRuntime" | "synchronizeEnvironment"
>;

/** Same-root cleanup steps. Admission, fresh reads and private custody stay with the parent. */
export function cleanupActions(
  workloads: WorkloadActions = routeWorkloadLocal(),
) {
  return {
    async prepareRuntime(raw: unknown, signal?: AbortSignal) {
      const remaining = z.array(z.record(z.string(), z.unknown())).parse(raw);
      for (const workload of remaining) {
        signal?.throwIfAborted();
        if (workload.production === true) {
          await workloads.prepareRuntime(workload, signal);
        }
      }
      return { ready: true };
    },
    // Call only inside the parent's protected material callback. No private output is retained.
    async synchronizeEnvironment(raw: unknown, instanceIds: number[]) {
      const writes = Writes.parse(raw);
      const permitted = new Set(instanceIds);
      // Validate the entire set before the first write.
      if (writes.some((write) => !permitted.has(write.instance_id))) {
        throw new Error("Route environment owner does not match");
      }
      for (const write of writes) {
        await workloads.synchronizeEnvironment([write]);
      }
      return { ready: true };
    },
  };
}
