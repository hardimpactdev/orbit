import { z } from "npm:zod@4";
import {
  type DependencyProject,
  DependencyProjectSchema,
} from "./dependency_project_schema.ts";

import {
  ConfigurationV2Schema,
  readProjectConfiguration,
} from "./project_configuration.ts";

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
        schema: ConfigurationV2Schema,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      configuration: {
        description: "Read this project's maintenance configuration",
        arguments: z.object({}),
        execute: async (_args: Record<string, never>, context: {
          readResource: (
            name: string,
          ) => Promise<Record<string, unknown> | null>;
          writeResource: (
            spec: string,
            name: string,
            value: Record<string, unknown>,
          ) => Promise<{ name: string }>;
        }) => {
          const next = DependencyProjectSchema.parse(config);
          const previous = await context.readResource("configuration");
          const oldConfig = previous
            ? readProjectConfiguration(previous)
            : null;
          if (
            previous?.schema === 2 &&
            JSON.stringify(oldConfig) ===
              JSON.stringify(next)
          ) {
            return { dataHandles: [] };
          }
          return {
            dataHandles: [
              await context.writeResource(
                "configuration",
                "configuration",
                { ...next, schema: 2 },
              ),
            ],
          };
        },
      },
    },
  };
}
