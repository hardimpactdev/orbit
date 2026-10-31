import { z } from "npm:zod@4.4.3";
import { repositoryIdentity } from "../../_lib/dependency_results_schema.ts";
import { type DependencyProject } from "../../_lib/dependency_project_schema.ts";
import {
  type ProjectConfigContext,
  projectConfigurations,
  type ProjectExtension,
} from "../../_lib/project_config.ts";

export const MergeConfiguration = z.object({
  projectId: z.number().int().positive(),
  enabled: z.boolean(),
  repository: z.string().nullable(),
  defaultBranch: z.string().nullable(),
  smokeWorkflow: z.string().nullable(),
}).strict();
export const mergeConfigurationMethod = {
  description: "Refresh the private merge switch immediately before execution",
  arguments: z.object({ projectId: z.number().int().positive() }),
  execute: async (
    args: { projectId: number },
    ctx: ProjectConfigContext & {
      globalArgs: {
        dependencyProjects: DependencyProject[];
        projectExtensions: ProjectExtension[];
      };
      writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ): Promise<{ name: string }>;
    },
  ) => {
    const [project] = await projectConfigurations(
      ctx.globalArgs.dependencyProjects,
      ctx.globalArgs.projectExtensions,
      ctx,
      args.projectId,
    );
    const value = MergeConfiguration.parse({
      projectId: args.projectId,
      enabled: project?.dependencyAutoMerge === true &&
        !!project.productionSmoke,
      repository: project ? repositoryIdentity(project.repository) : null,
      defaultBranch: project?.defaultBranch ?? null,
      smokeWorkflow: project?.productionSmoke?.workflow ?? null,
    });
    return {
      dataHandles: [
        await ctx.writeResource(
          "dependencyMergeConfiguration",
          `dependency-merge-configuration-${args.projectId}`,
          value,
        ),
      ],
    };
  },
};
