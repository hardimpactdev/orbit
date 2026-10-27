import { z } from "npm:zod@4";
import {
  type DependencyProject,
  DependencyProjectSchema,
} from "./dependency_project_schema.ts";

/** A project extension exports data only; maintenance stays in shared behaviors. */
export function projectConfig(type: string, config: DependencyProject) {
  return {
    type,
    version: "2026.10.27.1",
    globalArguments: z.object({}),
    resources: {
      configuration: {
        description:
          "Project identity, check, default branch, paths and policy",
        schema: DependencyProjectSchema,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      configuration: {
        description: "Read this project's maintenance configuration",
        arguments: z.object({}),
        execute: async (_args: Record<string, never>, context: {
          writeResource: (
            spec: string,
            name: string,
            value: Record<string, unknown>,
          ) => Promise<{ name: string }>;
        }) => ({
          dataHandles: [
            await context.writeResource(
              "configuration",
              "configuration",
              DependencyProjectSchema.parse(config),
            ),
          ],
        }),
      },
    },
  };
}
