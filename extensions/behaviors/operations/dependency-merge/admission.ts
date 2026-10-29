import { z } from "npm:zod@4.4.3";
import { type DependencyProject } from "../../_lib/dependency_project_schema.ts";
import {
  PrSchema,
  repositoryIdentity,
} from "../../_lib/dependency_results_schema.ts";
import { PackageTargetSchema } from "../../_lib/package_target.ts";
import { type Http } from "../../_lib/gateway_stage.ts";

export const Admission = z.object({
  key: z.string(),
  group_id: z.number().int().positive(),
  head_sha: z.string(),
  body: z.object({
    publish_operation_id: z.number().int().positive(),
    head_sha: z.string(),
  }),
  operation_id: z.number().int().positive().nullable(),
  status: z.enum(["pending", "admitted", "refused"]),
  reason: z.string().nullable(),
});
type Context = {
  queryData(predicate: string, select?: string): Promise<unknown[]>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
/** Called after a fresh PR observation and a fresh private-project configuration read. */
export async function admitBatchMerge(
  project: DependencyProject,
  observation: unknown,
  ctx: Context,
  http: Http,
  clock = Date.now,
) {
  if (!project.dependencyAutoMerge || !project.productionSmoke) return;
  const responseProject = await http(
    "GET",
    `/api/v1/projects/${project.projectId}`,
  );
  const current = z.object({
    data: z.object({
      id: z.literal(project.projectId),
      type: z.enum(["laravel-app", "symfony-app"]),
      repository_url: z.literal(project.repository),
      default_branch: z.literal(project.defaultBranch),
    }),
  }).safeParse(responseProject.body);
  if (responseProject.status !== 200 || !current.success) {
    throw new Error("Merge requires current app Project settings");
  }
  const pr = PrSchema.parse(observation);
  if (
    pr.status !== "open" || !pr.value ||
    clock() - Date.parse(pr.checked_at) > 60_000 ||
    Date.parse(pr.checked_at) > clock()
  ) return;
  const candidate = pr.value;
  if (candidate.repository !== repositoryIdentity(project.repository)) {
    throw new Error("Merge repository changed");
  }
  const workItem = `tg-${candidate.group_id}`;
  const targets = await ctx.queryData(
    `modelName == "orbit-gateway" && name == "package-${workItem}"`,
    "attributes",
  );
  if (
    targets.length !== 1 ||
    PackageTargetSchema.parse(targets[0]).workItem !== workItem ||
    PackageTargetSchema.parse(targets[0]).targets.length === 0
  ) throw new Error("Missing immutable batch target");
  const major = PackageTargetSchema.parse(targets[0]).targets.some((t) =>
    t.purpose === "major"
  );
  const owners = await ctx.queryData(
    `modelName == "orbit-group-${workItem}" && name == "owner"`,
    "attributes",
  );
  if (
    owners.length !== 1 ||
    z.object({ runId: z.string() }).parse(owners[0]).runId !== candidate.run_id
  ) throw new Error("Batch owner changed");
  const jobs = (await ctx.queryData(
    `modelName == "orbit-gateway" && name.startsWith("job-${workItem}.publish.c")`,
    "attributes",
  )) as Record<string, unknown>[];
  const matches = jobs.filter((job) => {
    const artifact =
      (job.artifacts as Record<string, Record<string, unknown>> | undefined)
        ?.["pull-request"];
    return job.state === "succeeded" && job.group === candidate.group_id &&
      job.stage === "publish" && artifact?.commit === candidate.head_commit &&
      artifact?.number === candidate.number && artifact?.url === candidate.url;
  });
  if (matches.length !== 1) {
    throw new Error("Ambiguous or missing successful batch publication");
  }
  const publish = z.number().int().positive().parse(matches[0].operationId);
  const key = `dependency-merge.${candidate.group_id}.${candidate.head_commit}`;
  const saved = await ctx.queryData(
    `modelName == "orbit-gateway" && name == "${key}"`,
    "attributes",
  );
  if (saved.length > 1) throw new Error("Ambiguous merge intent");
  let state = Admission.parse(
    saved[0] ?? {
      key,
      group_id: candidate.group_id,
      head_sha: candidate.head_commit,
      body: { publish_operation_id: publish, head_sha: candidate.head_commit },
      operation_id: null,
      status: "pending",
      reason: null,
    },
  );
  if (
    state.key !== key || state.group_id !== candidate.group_id ||
    state.head_sha !== candidate.head_commit ||
    state.body.publish_operation_id !== publish ||
    state.body.head_sha !== candidate.head_commit
  ) throw new Error("Merge intent changed");
  const save = () => ctx.writeResource("dependencyMergeAdmission", key, state);
  if (state.operation_id !== null) return;
  await save();
  if (major) {
    state = {
      ...state,
      status: "refused",
      reason: "dependency_merge.major_bump",
    };
    await save();
    return;
  }
  const response = await http(
    "POST",
    `/api/v1/task-groups/${candidate.group_id}/dependency-merge`,
    state.body,
    key,
  );
  const op = z.object({
    data: z.object({
      id: z.number().int().positive(),
      kind: z.literal("tasks.dependency_merge"),
      target_type: z.literal("task_group"),
      target_id: z.literal(candidate.group_id),
      input: z.object({
        head_sha: z.literal(candidate.head_commit),
        publish_operation_id: z.literal(publish),
      }),
    }),
  }).safeParse(response.body);
  if (response.status !== 202 || !op.success) {
    state = {
      ...state,
      status: "refused",
      reason: z.object({ error: z.object({ code: z.string() }) }).safeParse(
        response.body,
      ).data?.error.code ?? "dependency_merge.admission_unknown",
    };
    await save();
    return;
  }
  state = {
    ...state,
    operation_id: op.data.data.id,
    status: "admitted",
    reason: null,
  };
  await save();
}
