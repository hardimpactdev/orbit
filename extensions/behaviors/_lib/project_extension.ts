import { z } from "npm:zod@4";
import { DependencyProjectSchema } from "./dependency_project_schema.ts";

import {
  ConfigurationV3Schema,
  readProjectConfiguration,
} from "./project_configuration.ts";

import {
  emptyExceptions,
  ExceptionsSchema,
  mergeExceptions,
} from "./maintenance/exceptions.ts";

type ConfigurationContext = {
  readResource: (name: string) => Promise<Record<string, unknown> | null>;
  writeResource: (
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ) => Promise<{ name: string }>;
};

/** A project extension exports data only; maintenance stays in shared behaviors. */
export function projectConfig(
  type: string,
  config: z.input<typeof DependencyProjectSchema>,
) {
  return {
    type,
    version: "2026.10.28.1",
    globalArguments: z.object({}),
    resources: {
      exceptions: {
        description: "Project release-age exception history",
        schema: ExceptionsSchema,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      configuration: {
        description:
          "Project identity, check, default branch, paths and policy",
        schema: ConfigurationV3Schema,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      record_exceptions: {
        description:
          "Merge worker exception receipts into this project's configuration",
        arguments: ExceptionsSchema,
        execute: async (
          input: z.input<typeof ExceptionsSchema>,
          context: ConfigurationContext,
        ) => {
          const incoming = ExceptionsSchema.parse(input);
          const project = DependencyProjectSchema.parse(config);
          const stored = await context.readResource("exceptions");
          const history = mergeExceptions(
            project.exceptions,
            stored ?? emptyExceptions(),
          );
          const merged = mergeExceptions(history, incoming);
          for (
            const row of merged.exceptions.slice(history.exceptions.length)
          ) {
            const paths = row.manager === "composer"
              ? project.worker.composerPaths
              : project.worker.jsPaths;
            if (!paths.includes(row.path)) {
              throw new Error(
                "exception path is not configured for its ecosystem",
              );
            }
          }
          if (
            stored &&
            JSON.stringify(merged) ===
              JSON.stringify(ExceptionsSchema.parse(stored))
          ) return { dataHandles: [] };
          return {
            dataHandles: [
              await context.writeResource("exceptions", "exceptions", merged),
            ],
          };
        },
      },
      configuration: {
        description: "Read this project's maintenance configuration",
        arguments: z.object({}),
        execute: async (
          _args: Record<string, never>,
          context: ConfigurationContext,
        ) => {
          const configured = DependencyProjectSchema.parse(config);
          const recorded = await context.readResource("exceptions");
          const next = {
            ...configured,
            exceptions: mergeExceptions(
              configured.exceptions,
              recorded ?? emptyExceptions(),
            ),
          };
          const previous = await context.readResource("configuration");
          const oldConfig = previous
            ? readProjectConfiguration(previous)
            : null;
          if (
            previous?.schema === 3 &&
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
                { ...next, schema: 3 },
              ),
            ],
          };
        },
      },
    },
  };
}
