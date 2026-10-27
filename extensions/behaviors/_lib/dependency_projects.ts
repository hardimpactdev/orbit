import {
  type DependencyProject,
  DependencyProjectsSchema,
} from "./dependency_project_schema.ts";
import { type Http } from "./gateway_stage.ts";
export {
  type DependencyProject,
  DependencyProjectSchema,
  DependencyProjectsSchema,
} from "./dependency_project_schema.ts";

/** Read-only admission: supported operations use the Gateway's Project settings, not overrides. */
export async function dependencyProject(
  http: Http,
  group: number,
  projects: DependencyProject[],
): Promise<DependencyProject> {
  const entries = DependencyProjectsSchema.parse(projects);
  const r = await http("GET", `/api/v1/task-groups/${group}`);
  if (r.status !== 200 || !r.body?.data) {
    throw new Error("cannot read dependency group");
  }
  const g = r.body.data as { project_id: number };
  const config = entries.find((p) => p.projectId === g.project_id);
  if (!config) {
    throw new Error(`dependency Project ${g.project_id} is not configured`);
  }
  await assertProjectSettings(config, http);
  return config;
}

/** The Gateway owns these settings; config asserts them, never overrides them. */
export async function assertProjectSettings(
  config: Pick<
    DependencyProject,
    "projectId" | "repository" | "check" | "defaultBranch"
  >,
  http: Http,
): Promise<void> {
  const response = await http("GET", `/api/v1/projects/${config.projectId}`);
  const p = response.body?.data as Record<string, unknown> | undefined;
  if (
    response.status !== 200 || !p || p.id !== config.projectId ||
    p.repository_url !== config.repository || p.task_check !== config.check ||
    p.default_branch !== config.defaultBranch
  ) {
    throw new Error(
      `dependency Project ${config.projectId} settings differ from config`,
    );
  }
}
