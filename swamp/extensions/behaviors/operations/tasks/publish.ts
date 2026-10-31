import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
  Globals,
  type Result,
} from "../_lib/direct.ts";
import { type ScriptExecutor, workspaceProgram } from "./script.ts";
import { CheckProgram } from "./check_program.ts";
import { withRepositoryTokens } from "./check.ts";
import { withTaskCancellation } from "./cancellation.ts";

const Id = z.number().int().positive();
const Sha = z.string().regex(/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/);
export const PublishInput = z.object({
  group_id: Id,
  instance_id: Id,
  node_id: Id,
  check: Id,
  checkout_path: z.string().startsWith("/"),
  commit: Sha,
  tree: Sha,
  repository: z.string().regex(/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/),
  base: z.string().min(1),
  branch: z.string().regex(/^task-[1-9][0-9]*$/),
  pull_request: z.object({
    title: z.string().min(1).max(256),
    body: z.string().max(65536),
  }).nullable(),
});
const local: ScriptExecutor = (input, signal) =>
  workspaceProgram(CheckProgram, input, signal);
const Snapshot = z.object({ head: Sha, tree: Sha });
const Failure = z.object({ error_code: z.string().regex(/^[a-z0-9_.]+$/) });
const Pull = z.object({
  number: Id,
  state: z.literal("open"),
  head: z.object({
    ref: z.string(),
    sha: Sha,
    repo: z.object({ full_name: z.string() }),
  }),
  base: z.object({ ref: z.string() }),
});
export async function publish(
  input: z.infer<typeof PublishInput>,
  operationId: number,
  gateway: Pick<GatewayOperation, "withPrivateMaterial">,
  signal: AbortSignal,
  runtime = { invoke: local, request: fetch },
): Promise<Result> {
  const invoke = (mode: string, extra: Result = {}) =>
    runtime.invoke({
      ...input,
      execution_key: `operation-${operationId}`,
      mode,
      ...extra,
    });
  const fresh = async () => {
    const snapshot = Snapshot.parse(await invoke("snapshot"));
    return snapshot.head === input.commit && snapshot.tree === input.tree;
  };
  if (!(await fresh())) return { error_code: "tasks.head_moved" };
  return await withRepositoryTokens(
    gateway,
    input.repository,
    async (tokens) => {
      const cancelled = () => {
        if (signal.aborted) {
          throw new DirectOperationCancelled({
            effectRunning: false,
          });
        }
      };
      cancelled();
      // A bounded mutation finishes before cancellation can proceed to another step.
      const push = await invoke("push", { token: tokens.write_token });
      if (Failure.safeParse(push).success) return Failure.parse(push);
      z.object({ pushed: z.literal(true) }).parse(push);
      cancelled();
      if (!(await fresh())) return { error_code: "tasks.head_moved" };
      const result: Result = {
        branch: input.branch,
        commit: input.commit,
        pull_request_number: null,
        pull_request_url: null,
      };
      if (input.pull_request === null) return result;
      const request = async (path: string, method = "GET", body?: unknown) => {
        const response = await runtime.request(
          `https://api.github.com/repos/${input.repository}${path}`,
          {
            method,
            redirect: "error",
            signal: AbortSignal.timeout(30000),
            headers: {
              Authorization: `Bearer ${tokens.write_token}`,
              Accept: "application/vnd.github+json",
              "Content-Type": "application/json",
              "X-GitHub-Api-Version": "2022-11-28",
            },
            body: body === undefined ? undefined : JSON.stringify(body),
          },
        );
        if (!response.ok) {
          await response.body?.cancel();
          throw new Error("GitHub publication unavailable");
        }
        return await response.json();
      };
      try {
        const owner = input.repository.split("/")[0];
        const rows = z.array(Pull).parse(
          await request(
            `/pulls?state=open&head=${
              encodeURIComponent(owner + ":" + input.branch)
            }&per_page=100`,
          ),
        );
        const matches = rows.filter((pull) =>
          pull.head.ref === input.branch &&
          pull.head.repo.full_name.toLowerCase() ===
            input.repository.toLowerCase()
        );
        if (matches.length > 1) return { error_code: "tasks.publish_failed" };
        const existing = matches[0];
        if (
          existing &&
          (existing.base.ref !== input.base ||
            existing.head.sha !== input.commit)
        ) return { error_code: "tasks.head_moved" };
        cancelled();
        const changed = existing
          ? await request(
            `/pulls/${existing.number}`,
            "PATCH",
            input.pull_request,
          )
          : await request("/pulls", "POST", {
            ...input.pull_request,
            head: input.branch,
            base: input.base,
          });
        const number = z.object({ number: Id }).parse(changed).number;
        if (existing && number !== existing.number) {
          return {
            error_code: "tasks.publish_failed",
          };
        }
        return {
          ...result,
          pull_request_number: number,
          pull_request_url:
            `https://github.com/${input.repository}/pull/${number}`,
        };
      } catch (error) {
        if (error instanceof DirectOperationCancelled) throw error;
        return { error_code: "tasks.publish_failed" };
      }
    },
    runtime.request,
  );
}
const Args = DirectArgs.extend({
  node_id: Id,
  kind: z.literal("tasks.publish"),
});
export const publishMethod = {
  description: "Push the checked candidate and open or update its task PR",
  arguments: Args,
  execute: (
    args: z.infer<typeof Args>,
    ctx: { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal },
  ) => {
    const gateway = new GatewayOperation(
      gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
      args,
    );
    return directOperation(
      args,
      gateway,
      async (raw) => {
        const input = PublishInput.parse(raw);
        if (
          input.node_id !== args.node_id ||
          input.branch !== `task-${input.group_id}`
        ) throw new Error("Wrong task publication target");
        return await withTaskCancellation(
          gateway,
          (signal) => publish(input, args.operation_id, gateway, signal),
          ctx.signal,
        );
      },
      (result) => result,
      ctx.signal,
    );
  },
};
