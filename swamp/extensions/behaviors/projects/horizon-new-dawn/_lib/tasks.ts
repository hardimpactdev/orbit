import { z } from "npm:zod@4";
import { type Detection, DetectionSchema } from "./detect.ts";

export const TaskSchema = z.strictObject({
  key: z.string(),
  title: z.string().max(160),
  brief: z.string().max(8000),
  deliverables: z.array(z.strictObject({
    id: z.string(),
    type: z.literal("command"),
    description: z.string(),
    command: z.string(),
  })),
});
export const PlanSchema = z.strictObject({
  schema: z.literal(1),
  baseline: z.boolean(),
  synced_tag: z.string().nullable(),
  tag: z.string().nullable(),
  tasks: z.array(TaskSchema),
});
export type TaskPlan = z.infer<typeof PlanSchema>;
export interface TaskConfig {
  repository: string;
  ledger: string;
  compatibilityCommand: string;
  checkCommand: string;
}
/** One task per candidate, in detection order. Only the final task moves the ledger. */
export function taskPlan(result: Detection, config: TaskConfig): TaskPlan {
  DetectionSchema.parse(result);
  if (
    result.candidates.some((c) => c.tag !== result.tag) ||
    (result.tag === null && result.candidates.length)
  ) throw new Error("Detection candidates do not match the next tag");
  const tasks = result.candidates.map((candidate, index) => {
    const identity = candidate.kind === "release"
      ? "release"
      : `${candidate.surface}:${candidate.change}:${candidate.id}`;
    const key =
      `horizon-sync:${result.synced_tag}:${candidate.tag}:${identity}`;
    const title = candidate.kind === "release"
      ? `Verify Horizon ${candidate.tag}`
      : `Horizon ${candidate.tag}: ${candidate.change} ${candidate.surface} ${candidate.id}`;
    const brief = [
      `Repository: ${config.repository}. Upstream: laravel/horizon ${result.synced_tag} → ${candidate.tag}.`,
      `Candidate: ${JSON.stringify(candidate)}.`,
      candidate.kind === "release"
        ? "This tag has no dashboard surface change. Verify compatibility against this exact upstream tag."
        : "Implement parity for this one upstream item. Read its diff between these exact tags. Add a test that fails before the parity change and passes after it. Preserve the New Dawn interface.",
      `Update ${config.ledger} covered entries (kind, upstream, covered_by) for this item. Record an existing covered_by file; remove entries for deleted features.`,
      index === result.candidates.length - 1
        ? `This is the last of ${result.candidates.length} ordered tasks. Verify every preceding candidate is implemented and tested, then set synced_tag to ${candidate.tag}.`
        : `Keep synced_tag at ${result.synced_tag}. The final task advances it after all ${result.candidates.length} candidates pass.`,
      `Install laravel/horizon ${candidate.tag} in a temporary compatibility environment. Start an isolated local Redis for the compatibility tests. Run ${config.compatibilityCommand} against that tag, and ${config.checkCommand}. Keep incidental dependency changes out of the PR.`,
      "Leave scripts/horizon-sync in place. Publish a pull request and wait for Nick. Never merge.",
      `Swamp candidate: ${key}`,
    ].join("\n\n");
    return TaskSchema.parse({
      key,
      title: title.slice(0, 160),
      brief,
      deliverables: checks(config),
    });
  });
  return {
    schema: 1,
    baseline: false,
    synced_tag: result.synced_tag,
    tag: result.tag,
    tasks,
  };
}
function checks(config: TaskConfig) {
  return [
    {
      id: "compatibility",
      type: "command" as const,
      description:
        "Horizon compatibility tests pass against the selected upstream tag",
      command: config.compatibilityCommand,
    },
    {
      id: "project-check",
      type: "command" as const,
      description: "Project checks pass after the parity change",
      command: config.checkCommand,
    },
  ];
}
/** Only a missing ledger requests a baseline. Invalid ledgers fail detection. */
export function baselinePlan(config: TaskConfig): TaskPlan {
  const key = "horizon-sync:baseline";
  return {
    schema: 1,
    baseline: true,
    synced_tag: null,
    tag: null,
    tasks: [{
      key,
      title: "Record the Horizon New Dawn parity baseline",
      brief: [
        `In ${config.repository}, create ${config.ledger} before running upstream detection.`,
        "Choose the latest stable laravel/horizon tag whose implemented surface passes compatibility tests. Record synced_tag and covered rows with kind, upstream and covered_by for routes, public controller actions, screens and config keys actually implemented by New Dawn. Do not claim missing features.",
        `Run ${config.compatibilityCommand} against that exact tag and ${config.checkCommand}. Add ledger validation tests.`,
        "Leave scripts/horizon-sync in place. Publish a pull request and wait for Nick. Do not detect or start release tasks until the baseline is merged. Never merge.",
        `Swamp candidate: ${key}`,
      ].join("\n\n"),
      deliverables: checks(config),
    }],
  };
}
