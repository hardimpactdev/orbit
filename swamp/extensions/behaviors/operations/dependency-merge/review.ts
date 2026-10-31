import { type Job } from "../../_lib/gateway_stage.ts";
import { PackageTargetSchema } from "../../_lib/package_target.ts";

/** Keep batch Instances live until merge, including major batches awaiting hand-testing. */
export async function batchReviewWait(job: Job, deps: {
  targets(): Promise<unknown[]>;
  park(): Promise<Job>;
}): Promise<Job> {
  const rows = await deps.targets();
  if (rows.length > 1) {
    throw new Error("Ambiguous batch target; keep Instance active");
  }
  if (rows.length === 1) {
    const target = PackageTargetSchema.parse(rows[0]);
    if (target.workItem !== job.workItem || !target.targets.length) {
      throw new Error("Batch target changed; keep Instance active");
    }
    return job;
  }
  return await deps.park();
}
