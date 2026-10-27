import { dependencyProject, type DependencyProject } from "./dependency_projects.ts";
import { z } from "npm:zod@4";
import { type Http } from "./gateway_stage.ts";
import { canonical } from "./repository_analysis.ts";

export const PackageTargetSchema = z.object({
  workItem: z.string().regex(/^tg-[1-9]\d*$/),
  path: z.string().min(1).optional(),
  manager: z.enum(["composer", "js"]),
  package: z.string().regex(/^(?:@[a-z0-9][a-z0-9_.-]*\/)?[a-z0-9][a-z0-9_.-]*(?:\/[a-z0-9][a-z0-9_.-]*)?$/),
  version: z.string().regex(/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/),
  purpose: z.enum(["major", "security"]),
  advisoryUrls: z.array(z.string().url().startsWith("https://")).default([]),
  advisories: z.array(z.string().regex(/^[A-Za-z0-9][A-Za-z0-9_.:/-]*$/)).default([]),
}).strict().superRefine((v, ctx) => {
  if ((v.purpose === "security") !== (v.advisories.length > 0)) ctx.addIssue({ code: "custom", message: "security requires advisory IDs; major accepts none" });
  if (v.advisoryUrls.length !== v.advisories.length) ctx.addIssue({ code: "custom", message: "each advisory requires its source URL from detection" });
  if (v.manager === "composer" && !/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/.test(v.package)) ctx.addIssue({ code: "custom", message: "invalid Composer package" });
  if (v.manager === "js" && v.package.includes("/") && !v.package.startsWith("@")) ctx.addIssue({ code: "custom", message: "invalid JavaScript package" });
});
export type PackageTarget = z.infer<typeof PackageTargetSchema>;
export function packageDescription(t: PackageTarget): string {
  return `${t.manager} ${t.package} → ${t.version} (${t.purpose})${t.advisories.length ? `; advisories: ${t.advisories.map((id, i) => `${id}: ${t.advisoryUrls[i]}`).join(", ")}` : ""}`;
}
const unsafe = (g: Record<string, unknown>) => g.assistance_requested === true || g.assistance_kind != null || g.assistance_question != null || g.assistance_reason != null;
async function group(http: Http, item: string) {
  const r = await http("GET", `/api/v1/task-groups/${item.slice(3)}`);
  if (r.status !== 200 || !r.body?.data) throw new Error(`cannot read package group ${item}`);
  return r.body.data as Record<string, unknown>;
}

/** Called under the existing orbit-gateway behavior lock. No Gateway writes or group creation. */
export async function admitPackage(target: PackageTarget, deps: {
  http: Http;
  projects: DependencyProject[];
  targets: PackageTarget[];
  runState: (workItem: string) => Promise<{ stageId: string; status: string } | null>;
  save: (target: PackageTarget) => Promise<void>;
  start: (workItem: string) => Promise<void>;
}): Promise<void> {
  target = PackageTargetSchema.parse(target);
  const project = await dependencyProject(deps.http, Number(target.workItem.slice(3)), deps.projects);
  const existing = deps.targets.find((x) => x.workItem === target.workItem);
  if (existing && canonical(existing) !== canonical(target)) throw new Error("package target is immutable; use a new group");
  if (existing && await deps.runState(target.workItem)) return;
  for (const previous of deps.targets.filter((x) => x.workItem !== target.workItem)) {
    const g = await group(deps.http, previous.workItem);
    if (g.project_id !== project.projectId) continue;
    const state = await deps.runState(previous.workItem);
    const completed = g.status === "completed" && state?.stageId === "completed" &&
      (!(g.pr_url || g.watched_pr_url) || g.watched_pr_state === "merged");
    const cancelled = g.status === "cancelled" && state?.stageId === "failed" &&
      Array.isArray(g.tasks) && (g.tasks as Record<string, unknown>[]).every((t) => ["completed", "failed", "cancelled"].includes(String(t.status)) && !unsafe(t));
    if (unsafe(g) || g.vm_power !== "destroyed" || state?.status !== "terminal" || (!completed && !cancelled))
      throw new Error(`previous package ${previous.workItem} must merge and complete, or be cancelled, cleaned up and acknowledged, before another is admitted`);
  }
  const g = await group(deps.http, target.workItem);
  const tasks = g.tasks as Record<string, unknown>[];
  if (g.project_id !== project.projectId || g.orchestration !== "external" || g.status !== "backlog" || g.taskable_id != null || g.vm_power != null || unsafe(g) ||
    !Array.isArray(tasks) || tasks.length !== 1 || tasks[0].status !== "todo" || unsafe(tasks[0])) throw new Error("package admission requires one unused external configured project backlog group with one child");
  // Persist before start. A lost response resumes the same immutable selection/run.
  await deps.save(target);
  await deps.start(target.workItem);
}
