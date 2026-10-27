import {
  type DependencyProject,
  dependencyProject,
} from "./dependency_projects.ts";
import { z } from "npm:zod@4";
import { type Http } from "./gateway_stage.ts";
import { canonical } from "./repository_analysis.ts";
import { TargetsSchema } from "./maintenance/batch.ts";
export const PackageTargetSchema = z.strictObject({
  workItem: z.string().regex(/^tg-[1-9]\d*$/),
  targets: TargetsSchema,
});
export type PackageTarget = z.infer<typeof PackageTargetSchema>;
export function packageDescription(t: PackageTarget) {
  return t.targets.map((t) =>
    `${t.path}: ${t.manager} ${t.package} → ${t.version} (${t.purpose})${
      t.advisories.length
        ? `; advisories: ${
          t.advisories.map((id, i) => `${id}: ${t.advisoryUrls[i]}`).join(", ")
        }`
        : ""
    }`
  ).join("; ");
}
const unsafe = (g: Record<string, unknown>) =>
  g.assistance_requested === true || g.assistance_kind != null ||
  g.assistance_question != null || g.assistance_reason != null;
async function group(http: Http, item: string) {
  const r = await http("GET", `/api/v1/task-groups/${item.slice(3)}`);
  if (r.status !== 200 || !r.body?.data) {
    throw new Error(`cannot read package group ${item}`);
  }
  return r.body.data as Record<string, unknown>;
}
export async function admitPackage(
  target: PackageTarget,
  deps: {
    http: Http;
    projects: DependencyProject[];
    targets: PackageTarget[];
    runState: (
      item: string,
    ) => Promise<{ stageId: string; status: string } | null>;
    save: (t: PackageTarget) => Promise<void>;
    start: (item: string) => Promise<void>;
  },
) {
  target = PackageTargetSchema.parse(target);
  const project = await dependencyProject(
    deps.http,
    Number(target.workItem.slice(3)),
    deps.projects,
  );
  const existing = deps.targets.find((x) => x.workItem === target.workItem);
  if (existing && canonical(existing) !== canonical(target)) {
    throw new Error("package target is immutable; use a new group");
  }
  if (existing && await deps.runState(target.workItem)) return;
  for (const t of target.targets) {
    if (
      !project.worker[t.manager === "composer" ? "composerPaths" : "jsPaths"]
        .includes(t.path)
    ) throw new Error("batch path is not configured");
  }
  await assertPreviousPackages(project.projectId, target.workItem, deps);
  const g = await group(deps.http, target.workItem),
    tasks = g.tasks as Record<string, unknown>[];
  if (
    g.project_id !== project.projectId || g.orchestration !== "external" ||
    g.status !== "backlog" || g.taskable_id != null || g.vm_power != null ||
    unsafe(g) || !Array.isArray(tasks) || tasks.length !== 1 ||
    tasks[0].status !== "todo" || unsafe(tasks[0])
  ) {
    throw new Error(
      "package admission requires one unused external configured project backlog group with one child",
    );
  }
  await deps.save(target);
  await deps.start(target.workItem);
}

export async function assertPreviousPackages(
  projectId: number,
  workItem: string,
  deps: {
    http: Http;
    targets: PackageTarget[];
    runState: (
      item: string,
    ) => Promise<{ stageId: string; status: string } | null>;
  },
) {
  for (
    const previous of deps.targets.filter((x) => x.workItem !== workItem)
  ) {
    const g = await group(deps.http, previous.workItem);
    if (g.project_id !== projectId) continue;
    const state = await deps.runState(previous.workItem);
    const completed = g.status === "completed" &&
      state?.stageId === "completed" &&
      (!(g.pr_url || g.watched_pr_url) || g.watched_pr_state === "merged");
    const cancelled = g.status === "cancelled" && state?.stageId === "failed" &&
      Array.isArray(g.tasks) &&
      (g.tasks as Record<string, unknown>[]).every((t) =>
        ["completed", "failed", "cancelled"].includes(String(t.status)) &&
        !unsafe(t)
      );
    if (
      unsafe(g) || g.vm_power !== "destroyed" || state?.status !== "terminal" ||
      (!completed && !cancelled)
    ) {
      throw new Error(
        `previous package ${previous.workItem} must merge and complete, or be cancelled, cleaned up and acknowledged, before another is admitted`,
      );
    }
  }
}
