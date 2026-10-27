import { z } from "npm:zod@4";
import {
  type DependencyProject,
  DependencyProjectsSchema,
} from "./dependency_project_schema.ts";

import { readProjectConfiguration } from "./project_configuration.ts";

export const ProjectExtensionsSchema = z.array(
  z.object({
    projectId: z.number().int().positive(),
    type: z.string().regex(/^@nckrtl\/project-[a-z0-9-]+$/),
  }).strict(),
).superRefine((entries, ctx) => {
  if (
    new Set(entries.map((p) => p.projectId)).size !== entries.length ||
    new Set(entries.map((p) => p.type)).size !== entries.length
  ) {
    ctx.addIssue({
      code: "custom",
      message: "project extensions must be unique",
    });
  }
});
export type ProjectExtension = z.infer<typeof ProjectExtensionsSchema>[number];

export interface ProjectConfigContext {
  runModel: (
    args: {
      modelType: string;
      name: string;
      method: string;
      arguments?: Record<string, unknown>;
    },
  ) => Promise<{ ok: boolean }>;
  queryData: (predicate: string, select?: string) => Promise<unknown[]>;
}

/** Refresh through Swamp before admission. A failed refresh cannot use stale data. */
export async function projectConfigurations(
  entries: DependencyProject[],
  extensions: ProjectExtension[],
  context: ProjectConfigContext,
  projectId?: number,
): Promise<DependencyProject[]> {
  const registered = ProjectExtensionsSchema.parse(extensions);
  const ids = [...entries, ...registered].map((p) => p.projectId);
  if (new Set(ids).size !== ids.length) {
    throw new Error("dependency Project IDs must be unique");
  }
  const configs = entries.filter((p) =>
    projectId === undefined || p.projectId === projectId
  );
  for (
    const { type, projectId: expectedId } of registered.filter((p) =>
      projectId === undefined || p.projectId === projectId
    )
  ) {
    const name = type.slice("@nckrtl/".length);
    const result = await context.runModel({
      modelType: type,
      name,
      method: "configuration",
      arguments: {},
    });
    if (!result.ok) throw new Error(`cannot read project extension ${type}`);
    const rows = await context.queryData(
      `modelName == ${JSON.stringify(name)} && name == "configuration"`,
      "attributes",
    );
    const config = readProjectConfiguration(rows[0]);
    if (config.projectId !== expectedId) {
      throw new Error(`project extension ${type} returned another Project ID`);
    }
    configs.push(config);
  }
  return DependencyProjectsSchema.parse(configs);
}
