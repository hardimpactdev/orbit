import { z } from "npm:zod@4";
import { type Http } from "./gateway_stage.ts";

export const DependencyProjectSchema = z.object({
  projectId: z.number().int().positive(),
  repository: z.string().min(1),
  script: z.string().regex(/^[A-Za-z0-9_][A-Za-z0-9_.-]*(?:\/[A-Za-z0-9_.-]+)*$/).refine((v) => !v.split("/").some((part) => part === "." || part === ".."), "script must be a relative tracked path"),
  check: z.string().min(1),
  defaultBranch: z.string().min(1),
}).strict();
export type DependencyProject = z.infer<typeof DependencyProjectSchema>;
export const DependencyProjectsSchema = z.array(DependencyProjectSchema).superRefine((entries, ctx) => {
  if (new Set(entries.map((p) => p.projectId)).size !== entries.length)
    ctx.addIssue({ code: "custom", message: "dependency Project IDs must be unique" });
});

/** Read-only admission: supported operations use the Gateway's Project settings, not overrides. */
export async function dependencyProject(http: Http, group: number, projects: DependencyProject[]): Promise<DependencyProject> {
  const entries = DependencyProjectsSchema.parse(projects);
  const r = await http("GET", `/api/v1/task-groups/${group}`);
  if (r.status !== 200 || !r.body?.data) throw new Error("cannot read dependency group");
  const g = r.body.data as { project_id: number };
  const config = entries.find((p) => p.projectId === g.project_id);
  if (!config) throw new Error(`dependency Project ${g.project_id} is not configured`);
  const response = await http("GET", `/api/v1/projects/${config.projectId}`);
  const p = response.body?.data as Record<string, unknown> | undefined;
  if (response.status !== 200 || !p || p.id !== config.projectId ||
    p.repository_url !== config.repository || p.task_check !== config.check || p.default_branch !== config.defaultBranch)
    throw new Error(`dependency Project ${config.projectId} settings differ from config`);
  return config;
}
