import { z } from "npm:zod@4";
import {
  batchEvidence,
  type DependencyContext,
  githubPr,
  serviceDetection,
  unknownPr,
} from "./_lib/service_detection.ts";
import { curlHttp } from "./orbit_gateway.ts";
import {
  CredentialFailure,
  dependencyCredential,
  type Diagnostic,
} from "./_lib/dependency_credentials.ts";
import { IndexSchema } from "./_lib/dependency_results_schema.ts";
import { DependencyProjectSchema } from "./_lib/dependency_projects.ts";

/** This scanner runs only through the fixed native-host action. */
interface ScannerContext extends DependencyContext {
  runModel(
    request: import("./native_host_method.ts").NativeModelRequest,
  ): Promise<{
    ok: boolean;
    resources?: { name: string; version?: number }[];
  }>;
}

/** Long work owns this model's native lock, never the orchestration lock. */
export const behavior = {
  type: "@nckrtl/dependency-scanner",
  version: "2026.10.30.1",
  globalArguments: z.object({}),
  resources: {
    diagnostics: {
      description: "Safe credential and command failures, never raw stderr",
      schema: z.object({
        project_id: z.number().int().positive().nullable(),
        run_id: z.string(),
        failures: z.array(
          z.object({
            stage: z.enum(["settings", "token", "git", "gh", "scan"]),
            code: z.string(),
            exit_status: z.number().nullable(),
          }),
        ).max(32),
      }),
      lifetime: "7d",
      garbageCollection: 20,
    },
  },
  methods: {
    refresh: {
      description:
        "Refresh due PR evidence outside the shared Gateway model lock",
      arguments: z.object({}),
      execute: async (
        _args: Record<string, never>,
        context: ScannerContext,
      ) => {
        const runId = crypto.randomUUID(), failures: Diagnostic[] = [];
        const prepared = await context.runModel({
          definition: "orbit-gateway",
          method: "prepare_dependency_refresh",
          arguments: { runId },
        });
        if (!prepared.ok) {
          throw new Error("dependency_refresh_configuration_unavailable");
        }
        const rows = await context.queryData(
          'modelName == "orbit-gateway" && name == "dependency-refresh-input"',
          "attributes",
        ) as {
          runId: string;
          rows: { project: unknown; index: unknown }[];
          http: { gatewayUrl: string; caFile: string; timeoutSeconds: number };
        }[];
        if (rows.length !== 1 || rows[0].runId !== runId) {
          throw new Error("dependency_refresh_input_mismatch");
        }
        const signal = AbortSignal.any([
            context.signal ?? new AbortController().signal,
            AbortSignal.timeout(45000),
          ]),
          http = curlHttp(rows[0].http);
        for (const row of rows[0].rows) {
          if (signal.aborted) break;
          const project = DependencyProjectSchema.parse(row.project),
            index = IndexSchema.parse(row.index);
          let evidence;
          try {
            const credential = await dependencyCredential(
              project,
              http,
              signal,
            );
            evidence = await batchEvidence(
              project,
              index.batch_pr,
              { ...context, definition: { name: "orbit-gateway" }, signal },
              http,
              (url) => githubPr(url, credential, signal),
              (error) => {
                if (error instanceof CredentialFailure) {
                  failures.push(error.diagnostic);
                }
              },
            );
          } catch (error) {
            const code = error instanceof CredentialFailure
              ? error.message
              : "token_unavailable";
            if (error instanceof CredentialFailure) {
              failures.push(error.diagnostic);
            }
            evidence = { pr: unknownPr(index.batch_pr, code), links: [] };
          }
          const published = await context.runModel({
            definition: "orbit-gateway",
            method: "publish_dependency_refresh",
            arguments: {
              projectId: project.projectId,
              expectedPublicationId: index.publication_id,
              evidence,
            },
          });
          if (!published.ok) {
            throw new Error("dependency_refresh_publication_failed");
          }
        }
        return {
          dataHandles: [
            await context.writeResource("diagnostics", "refresh-diagnostics", {
              project_id: null,
              run_id: runId,
              failures: failures.slice(0, 32),
            }),
          ],
        };
      },
    },
    scan: {
      description:
        "Scan manifests on Services independently of the gateway tick lock",
      arguments: z.object({
        projectId: z.number().int().positive(),
        runId: z.string().min(1).max(256),
      }),
      execute: async (
        args: { projectId: number; runId: string },
        context: ScannerContext,
      ) => {
        const failures: Diagnostic[] = [];
        let diagnosticHandle: { name: string };
        try {
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
            http: {
              gatewayUrl: string;
              caFile: string;
              timeoutSeconds: number;
            };
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
            (value) => failures.push(value),
          );
        } catch (error) {
          failures.push({
            stage: "scan",
            code: "scan_failed",
            exit_status: null,
          });
          throw error;
        } finally {
          diagnosticHandle = await context.writeResource(
            "diagnostics",
            "scan-diagnostics",
            {
              project_id: args.projectId,
              run_id: args.runId,
              failures: failures.slice(0, 32),
            },
          );
        }
        return { dataHandles: [diagnosticHandle] };
      },
    },
  },
};
