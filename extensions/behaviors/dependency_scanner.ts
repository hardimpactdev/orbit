import { z } from "npm:zod@4";
import {
  type DependencyContext,
  serviceDetection,
} from "./_lib/service_detection.ts";
import { curlHttp } from "./orbit_gateway.ts";
import { DependencyProjectSchema } from "./_lib/dependency_projects.ts";

/** Long work owns this model's native lock, never the orchestration lock. */
export const behavior = {
  type: "@nckrtl/dependency-scanner",
  version: "2026.10.28.3",
  globalArguments: z.object({}),
  resources: {},
  methods: {
    scan: {
      description:
        "Scan manifests on Services independently of the gateway tick lock",
      arguments: z.object({
        projectId: z.number().int().positive(),
        runId: z.string().min(1).max(256),
      }),
      execute: async (
        args: { projectId: number; runId: string },
        context: DependencyContext,
      ) => {
        const prepared = await context.runModel({
          definition: "orbit-gateway",
          method: "prepare_dependency_scan",
          arguments: args,
        });
        if (!prepared.ok) {
          throw new Error("dependency_scan_configuration_unavailable");
        }
        const rows = await context.queryData(
          `modelName == "orbit-gateway" && name == "dependency-scan-input-${args.projectId}"`,
          "attributes",
        ) as {
          runId: string;
          project: unknown;
          http: { gatewayUrl: string; caFile: string; timeoutSeconds: number };
        }[];
        if (rows.length !== 1 || rows[0].runId !== args.runId) {
          throw new Error("dependency_scan_input_mismatch");
        }
        const project = DependencyProjectSchema.parse(rows[0].project);
        const proxy: DependencyContext = {
          ...context,
          definition: { name: "orbit-gateway" },
          writeResource: async (spec, name, value, overrides) => {
            const result = await context.runModel({
              definition: "orbit-gateway",
              method: "publish_dependency",
              arguments: {
                spec,
                name,
                value,
                overrides,
                runId: args.runId,
                projectId: args.projectId,
              },
            });
            if (!result.ok || !result.resources?.length) {
              throw new Error(
                "dependency_publication_failed",
              );
            }
            return result.resources[result.resources.length - 1];
          },
        };
        await serviceDetection(
          project,
          args.runId,
          proxy,
          curlHttp(rows[0].http),
        );
        return { dataHandles: [] };
      },
    },
  },
};
