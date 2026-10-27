import { z } from "npm:zod@4";
import { type DependencyProject } from "./dependency_projects.ts";
import { type Http } from "./gateway_stage.ts";
import { canonical, sha256 } from "./repository_analysis.ts";
import {
  BatchPlanSchema,
  TargetsSchema,
  type UpdateTarget,
} from "./maintenance/batch.ts";

export const ScheduleReceiptSchema = z.object({
  key: z.string(),
  projectId: z.number().int().positive(),
  mode: z.enum(["routine", "detect", "package"]),
  request: z.record(z.string(), z.unknown()),
  attempted: z.boolean(),
  workItem: z.string().regex(/^tg-[1-9]\d*$/).nullable(),
  started: z.boolean(),
  completed: z.boolean(),
  targets: TargetsSchema.optional(),
}).strict();
export type ScheduleReceipt = z.infer<typeof ScheduleReceiptSchema>;
export interface ScheduleStore {
  http: Http;
  read: (key: string) => Promise<ScheduleReceipt | null>;
  save: (receipt: ScheduleReceipt) => Promise<void>;
}

/** Weekly slots use Monday UTC; scheduled and manual invocation share a receipt. */
export function scheduleKey(
  project: number,
  mode: "routine" | "detect",
  now = new Date(),
): string {
  const day = new Date(now);
  if (mode === "routine") {
    day.setUTCDate(day.getUTCDate() - (day.getUTCDay() + 6) % 7);
  }
  return `${project}-${mode}-${day.toISOString().slice(0, 10)}`;
}

/** Exact saved-request reconciliation is shared by create retries and reservation expiry. */
export async function findScheduledGroup(
  receipt: ScheduleReceipt,
  http: Http,
): Promise<string | null> {
  const response = await http(
    "GET",
    `/api/v1/task-groups?project_id=${receipt.projectId}`,
  );
  if (response.status !== 200 || !Array.isArray(response.body?.data)) {
    throw new Error(
      "cannot reconcile scheduled group creation: Orbit evidence is unreadable; slot retained",
    );
  }
  const matches = (response.body.data as Record<string, unknown>[]).filter(
    (g) =>
      g.project_id === receipt.projectId && g.brief === receipt.request.brief &&
      g.orchestration === "external",
  );
  if (!matches.length) return null;
  if (
    matches.length !== 1 || !Number.isSafeInteger(matches[0].id) ||
    Number(matches[0].id) <= 0
  ) {
    throw new Error(
      `scheduled group ${receipt.key} has an uncertain create: matching Orbit groups are ambiguous; slot retained`,
    );
  }
  return `tg-${matches[0].id}`;
}

/** Group creation is not keyed at the Gateway: never resend an uncertain POST. */
export async function ensureProjectGroup(
  desired: ScheduleReceipt,
  store: ScheduleStore,
): Promise<ScheduleReceipt> {
  let receipt = await store.read(desired.key);
  if (
    receipt &&
    (receipt.projectId !== desired.projectId || receipt.mode !== desired.mode ||
      canonical(receipt.request) !== canonical(desired.request) ||
      canonical(receipt.targets ?? null) !== canonical(desired.targets ?? null))
  ) {
    throw new Error("scheduled group input conflict");
  }
  receipt ??= desired;
  if (receipt.workItem) return receipt;
  if (receipt.attempted) {
    const workItem = await findScheduledGroup(receipt, store.http);
    if (!workItem) {
      throw new Error(
        `scheduled group ${receipt.key} has an uncertain create; reconcile its saved request before retrying`,
      );
    }
    receipt = { ...receipt, workItem };
    await store.save(receipt);
    return receipt;
  }
  receipt = { ...receipt, attempted: true };
  await store.save(receipt);
  const response = await store.http(
    "POST",
    "/api/v1/task-groups",
    receipt.request,
  );
  const group = response.body?.data as Record<string, unknown> | undefined;
  if (
    response.status !== 201 || !Number.isSafeInteger(group?.id) ||
    Number(group?.id) <= 0 || group?.project_id !== receipt.projectId
  ) {
    throw new Error(
      `scheduled group ${receipt.key} create was not confirmed; reconcile before retrying`,
    );
  }
  receipt = { ...receipt, workItem: `tg-${group!.id}` };
  await store.save(receipt);
  return receipt;
}

/** Typed Orbit handoff requirements; routine no-change and read-only scans need no diff. */
function taskDeliverables(
  project: DependencyProject,
  mode: "routine" | "detect" | "package",
  targets?: UpdateTarget[],
): Record<string, string>[] {
  const deliverables: Record<string, string>[] = [
    ...(mode === "detect" ? [] : [{
      id: "project-check",
      type: "command",
      description: "Pass the configured project check in the task checkout.",
      command: project.check,
      directory: ".",
    }]),
    {
      id: mode,
      type: "review",
      description: mode === "detect"
        ? "Record successful Swamp detection reports and worker execution evidence, matching before/after checkout snapshots, and worker departure when the group ends."
        : "Record Swamp maintenance workflow and worker execution evidence, the passed Gateway handoff check, dependency PR or no-change result, and worker departure when the group ends.",
    },
  ];
  if (targets?.length) {
    const locks = [
      ...new Set(
        targets.flatMap((target) =>
          (target.manager === "composer" ? ["composer.lock"] : [
            "bun.lock",
            "package-lock.json",
            "npm-shrinkwrap.json",
            "pnpm-lock.yaml",
          ]).map((name) =>
            `${target.path === "." ? "" : target.path + "/"}${name}`
          )
        ),
      ),
    ];
    deliverables.push({
      id: "dependency-lock",
      type: "file",
      description: "Record the selected project batch lockfile changes.",
      path: locks.length === 1 ? locks[0] : `{${locks.join(",")}}`,
      change: "modified",
    });
  }
  return deliverables;
}

export function scheduleReceipt(
  project: DependencyProject,
  mode: "routine" | "detect",
  now = new Date(),
): ScheduleReceipt {
  const key = scheduleKey(project.projectId, mode, now);
  const title = mode === "routine"
    ? "Update dependencies within current constraints"
    : "Detect dependency majors and advisories";
  const brief =
    `${title}. Use this project's private Swamp extension configuration and shared maintenance extensions. Preserve version constraints and release-age policy.\n\nSwamp schedule: ${key}`;
  return {
    key,
    projectId: project.projectId,
    mode,
    attempted: false,
    workItem: null,
    started: false,
    completed: false,
    request: {
      project_id: project.projectId,
      title,
      brief,
      status: "backlog",
      orchestration: "external",
      tasks: [{ title, brief, deliverables: taskDeliverables(project, mode) }],
    },
  };
}

export const FindingsSchema = z.strictObject({
  schema: z.literal(1),
  key: z.string(),
  projectId: z.number().int().positive(),
  source: z.string(),
  reports: z.array(z.unknown()),
  plans: z.array(BatchPlanSchema),
  targets: z.array(TargetsSchema.element),
  blocked: z.array(BatchPlanSchema.shape.blocked.element),
});
export type Findings = z.infer<typeof FindingsSchema>;
/** Detection stores findings. Only an admitted project batch becomes an Orbit group. */
export function projectFindings(
  project: DependencyProject,
  source: string,
  reports: unknown[],
  plans: unknown[],
): Findings {
  const expected = Number(Boolean(project.worker.composerPaths.length)) +
    Number(Boolean(project.worker.jsPaths.length));
  if (reports.length !== expected || plans.length !== expected) {
    throw new Error(
      "detection is missing an ecosystem report or eligible-target plan",
    );
  }
  const parsed = plans.map((p) => BatchPlanSchema.parse(p));
  const targets = parsed.flatMap((p) => p.targets),
    blocked = parsed.flatMap((p) => p.blocked);
  for (const t of [...targets, ...blocked]) {
    if (
      !project.worker[t.manager === "composer" ? "composerPaths" : "jsPaths"]
        .includes(t.path)
    ) throw new Error("detection path is not configured for its ecosystem");
  }
  if (targets.length) TargetsSchema.parse(targets);
  return FindingsSchema.parse({
    schema: 1,
    key: `${project.projectId}-${source}`,
    projectId: project.projectId,
    source,
    reports,
    plans: parsed,
    targets,
    blocked,
  });
}
export async function batchReceipt(
  project: DependencyProject,
  findings: Findings,
): Promise<ScheduleReceipt> {
  TargetsSchema.parse(findings.targets);
  const key = `batch-${await sha256(
    canonical([findings.projectId, findings.source, findings.targets]),
  )}`;
  const title = "Update all eligible dependency majors and advisory fixes";
  // The complete immutable selections stay in Swamp; keep Orbit's child brief under 8000 bytes.
  const brief =
    `${title}. Apply the entire admitted Swamp batch in one VM and one PR. Preserve unrelated constraints and release-age policy. Gateway owns checks, repair and publication.\n\nBatch: ${findings.key}\nTargets: ${findings.targets.length}\nBlocked findings retained: ${findings.blocked.length}\nCheck: ${project.check}\nSwamp schedule: ${key}`;
  return {
    key,
    projectId: project.projectId,
    mode: "package",
    attempted: false,
    workItem: null,
    started: false,
    completed: false,
    targets: findings.targets,
    request: {
      project_id: project.projectId,
      title,
      brief,
      status: "backlog",
      orchestration: "external",
      tasks: [{
        title,
        brief,
        deliverables: taskDeliverables(project, "package", findings.targets),
      }],
    },
  };
}

/** Orbit cancellation is final even when the factory never got past provision. */
export function cleanedCancelledGroup(group: Record<string, unknown>): boolean {
  const noAssistance = (row: Record<string, unknown>) =>
    !row.assistance_requested && !row.assistance_kind &&
    !row.assistance_reason && !row.assistance_question;
  return group.status === "cancelled" &&
    (group.vm_power === "destroyed" ||
      (group.vm_power === null && group.taskable_id === null)) &&
    noAssistance(group) && Array.isArray(group.tasks) &&
    group.tasks.every((task: Record<string, unknown>) =>
      ["completed", "cancelled", "failed"].includes(String(task.status)) &&
      noAssistance(task)
    );
}

export const SchedulerIssueSchema = z.object({
  key: z.string(),
  projectId: z.number().int().positive(),
  phase: z.enum(["pending", "active", "finding", "admission"]),
  reason: z.string(),
  status: z.enum(["open", "resolved"]),
}).strict();
export const SchedulerSkipSchema = z.object({
  key: z.string(),
  projectId: z.number().int().positive(),
  mode: z.enum(["routine", "detect"]),
  blocker: z.string(),
  reason: z.string(),
}).strict();

interface SchedulerContext {
  definition: { name: string };
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
  writeResource: (
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ) => Promise<{ name: string }>;
}

/** A bad row remains visible in Swamp data while other rows keep progressing. */
export async function isolateScheduleRow(
  context: SchedulerContext,
  receipt: Pick<ScheduleReceipt, "key" | "projectId">,
  phase: z.infer<typeof SchedulerIssueSchema>["phase"],
  action: () => Promise<void>,
): Promise<boolean> {
  let reason: string | null = null;
  try {
    await action();
  } catch (error) {
    reason = error instanceof Error ? error.message : String(error);
  }
  const name = `scheduler-issue-${phase}-${receipt.key}`;
  const previous = (await context.queryData(
    `modelName == ${JSON.stringify(context.definition.name)} && name == ${
      JSON.stringify(name)
    }`,
    "attributes",
  ))[0];
  if (reason !== null || previous) {
    const next = SchedulerIssueSchema.parse({
      key: receipt.key,
      projectId: receipt.projectId,
      phase,
      reason: reason ?? (previous as { reason: string }).reason,
      status: reason === null ? "resolved" : "open",
    });
    if (canonical(previous ?? null) !== canonical(next)) {
      await context.writeResource("schedulerIssue", name, next);
    }
  }
  return reason === null;
}
