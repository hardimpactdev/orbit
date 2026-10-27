import { z } from "npm:zod@4";
import { type DependencyProject } from "./dependency_projects.ts";
import { type Http } from "./gateway_stage.ts";
import { canonical, sha256 } from "./repository_analysis.ts";
import { PackageTargetSchema } from "./package_target.ts";

const PackageSelectionSchema = z.object(PackageTargetSchema.shape).omit({
  workItem: true,
})
  .refine(
    (target) =>
      PackageTargetSchema.safeParse({ ...target, workItem: "tg-1" }).success,
    "invalid package selection",
  );

export const ScheduleReceiptSchema = z.object({
  key: z.string(),
  projectId: z.number().int().positive(),
  mode: z.enum(["routine", "detect", "package"]),
  request: z.record(z.string(), z.unknown()),
  attempted: z.boolean(),
  workItem: z.string().regex(/^tg-[1-9]\d*$/).nullable(),
  started: z.boolean(),
  completed: z.boolean(),
  target: PackageSelectionSchema.optional(),
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
      canonical(receipt.target ?? null) !== canonical(desired.target ?? null))
  ) {
    throw new Error("scheduled group input conflict");
  }
  receipt ??= desired;
  if (receipt.workItem) return receipt;
  if (receipt.attempted) {
    const response = await store.http(
      "GET",
      `/api/v1/task-groups?project_id=${receipt.projectId}`,
    );
    if (response.status !== 200 || !Array.isArray(response.body?.data)) {
      throw new Error("cannot reconcile scheduled group creation");
    }
    const matches = (response.body.data as Record<string, unknown>[]).filter((
      g,
    ) =>
      g.project_id === receipt!.projectId &&
      g.brief === receipt!.request.brief && g.orchestration === "external"
    );
    if (
      matches.length !== 1 || !Number.isSafeInteger(matches[0].id) ||
      Number(matches[0].id) <= 0
    ) {
      throw new Error(
        `scheduled group ${receipt.key} has an uncertain create; reconcile its saved request before retrying`,
      );
    }
    receipt = { ...receipt, workItem: `tg-${matches[0].id}` };
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
  target?: { path: string; manager: "composer" | "js" },
): Record<string, string>[] {
  const deliverables: Record<string, string>[] = [
    {
      id: "project-check",
      type: "command",
      description: "Pass the configured project check in the task checkout.",
      command: project.check,
      directory: ".",
    },
    {
      id: mode,
      type: "review",
      description: mode === "detect"
        ? "Record Swamp detection reports and worker execution evidence, the passed Gateway check, an unchanged checkout, and worker departure when the group ends."
        : "Record Swamp maintenance workflow and worker execution evidence, the passed Gateway handoff check, dependency PR or no-change result, and worker departure when the group ends.",
    },
  ];
  if (target) {
    const prefix = target.path === "." ? "" : `${target.path}/`;
    deliverables.push({
      id: "dependency-lock",
      type: "file",
      description:
        "Update the selected package's lockfile in its configured path.",
      path: `${prefix}${
        target.manager === "composer"
          ? "composer.lock"
          : "{bun.lock,package-lock.json,npm-shrinkwrap.json,pnpm-lock.yaml}"
      }`,
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

const AdvisorySchema = z.object({
  id: z.string().min(1),
  url: z.string().nullable(),
  title: z.string(),
  affected: z.string().nullable(),
}).passthrough();
const CandidateSchema = z.object({
  path: z.string().min(1),
  manager: z.enum(["composer", "js"]),
  package: z.string().min(1),
  reasons: z.array(z.enum(["major", "security"])).min(1),
  advisories: z.array(AdvisorySchema),
  current: z.string().optional(),
  latest: z.string().optional(),
});
const DetectionSchema = z.object({
  schema: z.literal(1),
  candidates: z.array(CandidateSchema),
});

/** Each advisory is a separate task. No fixed security version is inferred from a major candidate. */
export async function findingReceipts(
  project: DependencyProject,
  reports: unknown[],
): Promise<ScheduleReceipt[]> {
  const expected = Number(Boolean(project.worker?.composerPaths.length)) +
    Number(Boolean(project.worker?.jsPaths.length));
  if (reports.length !== expected || !expected) {
    throw new Error("detection is missing an ecosystem report");
  }
  const receipts = new Map<string, ScheduleReceipt>();
  for (const report of reports) {
    for (const candidate of DetectionSchema.parse(report).candidates) {
      const paths = candidate.manager === "composer"
        ? project.worker?.composerPaths
        : project.worker?.jsPaths;
      if (!paths?.includes(candidate.path)) {
        throw new Error("detection path is not configured for its ecosystem");
      }
      if (
        candidate.reasons.includes("security") && !candidate.advisories.length
      ) throw new Error("security detection has no advisory evidence");
      const findings = [
        ...(candidate.reasons.includes("major")
          ? [{
            purpose: "major" as const,
            id: candidate.latest ?? "unknown",
            advisory: null,
          }]
          : []),
        ...candidate.advisories.map((a) => ({
          purpose: "security" as const,
          id: a.id,
          advisory: a,
        })),
      ];
      for (const finding of findings) {
        const key = `finding-${await sha256(
          canonical([
            project.projectId,
            candidate.path,
            candidate.manager,
            candidate.package,
            finding.purpose,
            finding.id,
          ]),
        )}`;
        const title = `${
          finding.purpose === "major" ? "Upgrade" : "Fix advisory in"
        } ${candidate.package}${
          finding.purpose === "major" && candidate.latest
            ? ` to ${candidate.latest}`
            : ""
        }`.slice(0, 160);
        const brief =
          `${title} in ${candidate.path} (${candidate.manager}). One package and one PR. Preserve unrelated constraints and project policy. Use the shared Swamp maintenance extension; Gateway owns checks and publication. Security requires an exact verified fixed version and current advisory evidence before admission.\n\nDetection: ${
            JSON.stringify({
              ...candidate,
              advisories: finding.advisory ? [finding.advisory] : [],
            })
          }\nFinding: ${finding.id}\nPolicy: ${
            JSON.stringify(project.worker!.policy)
          }\nCheck: ${project.check}\nSwamp schedule: ${key}`;
        const target = finding.purpose === "major"
          ? PackageSelectionSchema.safeParse({
            path: candidate.path,
            manager: candidate.manager,
            package: candidate.package,
            version: candidate.latest,
            purpose: "major",
            advisoryUrls: [],
            advisories: [],
          })
          : null;
        receipts.set(key, {
          key,
          projectId: project.projectId,
          mode: "package",
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
            tasks: [{
              title,
              brief,
              deliverables: taskDeliverables(project, "package", candidate),
            }],
          },
          ...(target?.success ? { target: target.data } : {}),
        });
      }
    }
  }
  return [...receipts.values()];
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
