import { z } from "npm:zod@4.4.3";
import { callWorkerModel } from "../../_lib/worker_model.ts";
import { type ProjectConfigContext } from "../../_lib/project_config.ts";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  type Result,
} from "../_lib/direct.ts";
import { MergeConfiguration } from "./configuration.ts";
import { boundedJson } from "./contents.ts";
import { MergeGitHub } from "./policy.ts";
import { Refusal, refusalCodes } from "./refusal.ts";
import { queryWorkerData } from "../../_lib/worker_query.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^[a-f0-9]{40}$/);
const Args = DirectArgs.extend({
  kind: z.literal("tasks.dependency_merge"),
  node_id: Id,
});
const Input = z.object({
  node_id: Id,
  project_id: Id,
  group_id: Id,
  publish_operation_id: Id,
  repository: z.string().regex(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
  default_branch: z.string().min(1),
  smoke_workflow: z.string().min(1),
  pr_number: Id,
  head_sha: Sha,
}).strict();
const Success = z.object({
  merged: z.literal(true),
  head_sha: Sha,
  merge_sha: Sha,
}).strict();
const ResultSchema = z.union([
  Success,
  z.object({
    error_code: z.enum(refusalCodes),
  }).strict(),
]);
type Context = ProjectConfigContext & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};

/** GitHub effects stay inside the shared private callback; only exact-head proof leaves it. */
export async function squash(
  input: z.infer<typeof Input>,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  config: z.infer<typeof MergeConfiguration>,
  request: typeof fetch = fetch,
  signal?: AbortSignal,
): Promise<Result> {
  let result: Result | undefined;
  await gateway.withPrivateMaterial(async (material) => {
    const Token = z.string().min(1).max(2048).regex(/^[A-Za-z0-9_-]+$/);
    const names = [
      "write_token",
      "read_token",
      "checks_token",
      "actions_token",
    ] as const;
    const raw = material.token;
    const revoke = async () => {
      if (!raw || typeof raw !== "object") return;
      const tokens = new Set(
        names.map((key) => (raw as Record<string, unknown>)[key]).filter((
          v,
        ): v is string => typeof v === "string" && v.length > 0),
      );
      for (const token of tokens) {
        try {
          const response = await request(
            "https://api.github.com/installation/token",
            {
              method: "DELETE",
              redirect: "error",
              signal: AbortSignal.timeout(10000),
              headers: {
                Authorization: `Bearer ${token}`,
                Accept: "application/vnd.github+json",
                "X-GitHub-Api-Version": "2022-11-28",
              },
            },
          );
          await response.body?.cancel();
          if (response.status !== 204) throw new Error();
        } catch {
          console.warn("operation.github_token_revoke_failed");
        }
      }
    };
    try {
      const credentials = z.object({
        write_token: Token,
        read_token: Token,
        checks_token: Token,
        actions_token: Token,
      }).strict().parse(raw);
      const github = new MergeGitHub(input, credentials, request, signal);
      try {
        const pr = await github.pr();
        if (pr.merged) {
          result = Success.parse({
            merged: true,
            head_sha: input.head_sha,
            merge_sha: pr.merge_commit_sha,
          });
        } else if (
          !config.enabled || config.projectId !== input.project_id ||
          config.repository !== input.repository ||
          config.defaultBranch !== input.default_branch ||
          config.smokeWorkflow !== input.smoke_workflow
        ) {
          result = { error_code: "dependency_merge.disabled" };
        } else {
          await github.policy(pr);
          const merged = await github.call(
            `${github.path}/pulls/${input.pr_number}/merge`,
            "write_token",
            "PUT",
            { sha: input.head_sha, merge_method: "squash" },
          );
          if ([405, 409, 422].includes(merged.status)) {
            await merged.body?.cancel();
            result = { error_code: "dependency_merge.merge_refused" };
          } else {
            if (!merged.ok) {
              await merged.body?.cancel();
              throw new Error("GitHub merge outcome uncertain");
            }
            const proof = z.object({ merged: z.literal(true), sha: Sha }).parse(
              await boundedJson(merged),
            );
            result = Success.parse({
              merged: true,
              head_sha: input.head_sha,
              merge_sha: proof.sha,
            });
          }
        }
      } catch (error) {
        if (!(error instanceof Refusal)) throw error;
        result = { error_code: error.code };
      }
      const snapshot = JSON.stringify(result);
      if (
        Object.values(credentials).some((token) => snapshot.includes(token))
      ) throw new Error("Private output refused");
    } finally {
      await revoke();
    }
  });
  if (!result) throw new Error("Merge outcome unavailable");
  return result;
}

export function dependencyMerge(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile, ctx.signal),
  request: typeof fetch = fetch,
) {
  return {
    type: "@nckrtl/operation-dependency-merge",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description: "Squash one admitted dependency batch at its exact head",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              const input = Input.parse(raw);
              if (input.node_id !== args.node_id) {
                throw new Error(
                  "Wrong merge Node",
                );
              }
              const refreshed = await callWorkerModel(ctx, {
                modelType: "@nckrtl/orbit-gateway",
                name: "orbit-gateway",
                method: "dependency_merge_configuration",
                arguments: { projectId: input.project_id },
              });
              if (!refreshed.ok) {
                throw new Error(
                  "Cannot refresh private merge configuration",
                );
              }
              const rows = await queryWorkerData(
                ctx,
                `modelName == "orbit-gateway" && name == "dependency-merge-configuration-${input.project_id}"`,
                "attributes",
              );
              if (rows.length !== 1) {
                throw new Error(
                  "Cannot read private merge configuration",
                );
              }
              return await squash(
                input,
                gateway,
                MergeConfiguration.parse(rows[0]),
                request,
                ctx.signal,
              );
            },
            (result) => ResultSchema.parse(result),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = {
  ...dependencyMerge(),
  type: "@nckrtl/operation-dependency-merge",
};
