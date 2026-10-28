import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import { projectConfigurations } from "../_lib/project_config.ts";
import {
  Job,
  Observation,
  Operation,
  Receipt,
  Request,
  requestOf,
  same,
  Selection,
  selectionOf,
  State,
} from "./contract.ts";
import { type Context, withinBudget } from "./mod.ts";

export const Failure = Selection.extend({ smoke_request_key: z.string() });
export const Block = z.object({
  request_key: z.string(),
  instance_id: z.number().int().positive(),
  failure: Failure,
  blocked: z.literal(true),
}).strict();
export const Rollback = z.object({
  failure_key: z.string(),
  expected: Selection,
  target: Selection,
  target_smoke_request_key: z.string(),
}).strict();
export const RollbackOperation = Operation.extend({
  kind: z.literal("instance.rollback"),
  target_type: z.literal("instance"),
  input: Rollback.extend({
    caller_node_id: z.number().int().positive().optional(),
  }).passthrough(),
});
export const Recovery = z.object({
  status: z.enum([
    "idle",
    "hold_required",
    "planned",
    "blocked",
    "rollback_pending",
    "rolled_back",
    "superseded",
    "assistance_required",
  ]),
  reason: z.string().nullable(),
  intent: z.object({
    key: z.string(),
    instance: z.number().int().positive(),
    failure: Failure,
    target: Selection.nullable(),
    block: Block.nullable(),
    rollback_sent: z.boolean(),
    operation_id: z.number().int().positive().nullable(),
  }).nullable(),
});
const BlockReceipt = z.object({
  request_key: z.string(),
  found: z.boolean(),
  block: Block.nullable(),
}).strict();
const RollbackReceipt = z.object({
  request_key: z.string(),
  found: z.boolean(),
  operation: RollbackOperation.nullable(),
  deployment: Selection.extend({
    status: z.enum(["running", "succeeded", "failed"]),
  }).nullable(),
}).strict();
function execution(
  receipt: z.infer<typeof Receipt>,
  request: z.infer<typeof Request>,
  key: string,
  failed: boolean,
) {
  const { operation: op, smoke: result } = receipt;
  return receipt.found && receipt.request_key === key && op && result &&
    same(requestOf(op.input), request) && same(requestOf(result), request) &&
    result.state === (failed ? "failed" : "passed") && result.run_id &&
    result.run_attempt && result.workflow_sha && result.run_url &&
    result.tests > result.skipped &&
    (failed
      ? result.failures + result.errors > 0
      : op.status === "succeeded" && result.conclusion === "success" &&
        result.failures + result.errors + result.skipped === 0);
}
export function failureMethod(
  makeHttp: ((ctx: Context) => Http) | undefined,
  effects: boolean,
  budget: number,
) {
  return {
    description:
      "Plan failure recovery; effects require both apply and the disabled release gate",
    arguments: z.object({
      apply: z.boolean().default(false),
      deploymentId: z.number().int().positive().optional(),
    }),
    execute: (
      input: { apply?: boolean; deploymentId?: number },
      ctx: Context,
    ) =>
      withinBudget(ctx, budget, async (ctx) => {
        let recovery = Recovery.parse({
          status: "idle",
          reason: null,
          intent: null,
        });
        const finish = async (
          status: z.infer<typeof Recovery>["status"],
          reason: string | null,
        ) => {
          ctx.signal?.throwIfAborted();
          recovery.status = status;
          recovery.reason = reason;
          if (recovery.intent) {
            await ctx.writeResource(
              "recovery",
              `recovery-${recovery.intent.failure.deployment_id}`,
              recovery,
            );
          }
          ctx.signal?.throwIfAborted();
          return {
            dataHandles: [
              await ctx.writeResource("recovery", "recovery", recovery),
            ],
          };
        };
        try {
          const state = State.parse(await ctx.readResource("state"));
          const deploymentId = input.deploymentId ??
            state.job?.request.deployment_id;
          const job = deploymentId
            ? Job.parse(await ctx.readResource(`job-${deploymentId}`))
            : null;
          if (!job) {
            return await finish(
              "hold_required",
              "No bound smoke intent; keep release live.",
            );
          }
          const previous = await ctx.readResource(
            `recovery-${job.request.deployment_id}`,
          );
          if (previous) recovery = Recovery.parse(previous);
          const [project] = await projectConfigurations(
            [],
            [{
              projectId: ctx.globalArgs.projectId,
              type: ctx.globalArgs.projectType,
            }],
            ctx,
            ctx.globalArgs.projectId,
          );
          const config = project.productionSmoke;
          if (!config) {
            return await finish("hold_required", "Project is not opted in.");
          }
          if (
            job.config !==
              JSON.stringify({
                projectId: project.projectId,
                repository: project.repository,
                ...config,
              })
          ) {
            return await finish(
              "hold_required",
              "Configuration changed; original intent retained.",
            );
          }
          const http = makeHttp?.(ctx) ??
            gatewayHttp(
              ctx.globalArgs.gatewayUrl,
              ctx.globalArgs.caFile,
              ctx.signal,
            );
          const root = `/api/v1/instances/${config.instance}/smoke`;
          const call = async <T>(
            method: "GET" | "POST",
            path: string,
            schema: z.ZodType<T>,
            body?: unknown,
            key?: string,
          ) => {
            ctx.signal?.throwIfAborted();
            const response = await http(method, root + path, body, key);
            if (![200, 202].includes(response.status)) {
              const error = z.object({ error: z.object({ code: z.string() }) })
                .safeParse(response.body);
              throw new Error(
                error.success
                  ? error.data.error.code
                  : `Gateway HTTP ${response.status}`,
              );
            }
            return schema.parse(
              z.object({ data: z.unknown() }).parse(response.body).data,
            );
          };
          const context = async () => {
            const observed = await call("GET", "/context", Observation);
            if (
              observed.instance_id !== config.instance ||
              observed.project_id !== project.projectId ||
              observed.repository_url !== project.repository ||
              !observed.production ||
              !observed.base_urls.includes(config.baseUrl)
            ) throw new Error("Recovery target ownership changed.");
            return observed;
          };
          await context();
          const smoke = await call(
            "GET",
            `/receipt?request_key=${encodeURIComponent(job.key)}`,
            Receipt,
          );
          if (
            smoke.operation?.target_id !== config.instance ||
            !execution(smoke, job.request, job.key, true) ||
            (job.operation_id !== null &&
              smoke.operation.id !== job.operation_id)
          ) {
            return await finish(
              smoke.smoke?.state === "passed" ? "idle" : "hold_required",
              "No verified failing smoke; keep release live.",
            );
          }
          const failure = Failure.parse({
            ...selectionOf(job.request),
            smoke_request_key: job.key,
          });
          if (recovery.intent) {
            if (
              recovery.intent.instance !== config.instance ||
              !same(recovery.intent.failure, failure)
            ) throw new Error("Recovery intent identity changed.");
          } else {
            recovery.intent = {
              key: `smoke-failure.${config.instance}.${failure.deployment_id}`,
              instance: config.instance,
              failure,
              target: job.previous_good,
              block: null,
              rollback_sent: false,
              operation_id: null,
            };
          }
          const intent = recovery.intent;
          if (!effects || !input.apply) {
            return await finish(
              "planned",
              "Live block and rollback effects are disabled.",
            );
          }
          const checkBlock = (block: z.infer<typeof Block>) => {
            if (
              block.request_key !== intent.key ||
              block.instance_id !== intent.instance ||
              !same(block.failure, intent.failure)
            ) throw new Error("Failure block identity changed.");
            intent.block = block;
          };
          if (!intent.block) {
            await finish("planned", "Failure block intent saved.");
            const receipt = await call(
              "GET",
              `/failure/receipt?request_key=${encodeURIComponent(intent.key)}`,
              BlockReceipt,
            );
            if (
              receipt.request_key !== intent.key ||
              receipt.found !== (receipt.block !== null)
            ) throw new Error("Failure receipt identity changed.");
            checkBlock(
              receipt.block ??
                await call(
                  "POST",
                  "/failure",
                  Block,
                  intent.failure,
                  intent.key,
                ),
            );
            await finish("blocked", "Failed SHA is permanently blocked.");
          }
          const target = intent.target;
          if (
            !target || target.release === intent.failure.release ||
            target.commit === intent.failure.commit ||
            target.deployment_id >= intent.failure.deployment_id
          ) {
            return await finish(
              "assistance_required",
              "No distinct prior smoke-passed rollback target; block retained.",
            );
          }
          const request = Rollback.parse({
            failure_key: intent.key,
            expected: selectionOf(intent.failure),
            target,
            target_smoke_request_key:
              `smoke.${config.instance}.${target.deployment_id}`,
          });
          const rollbackKey = intent.key + ".rollback";
          const checkOp = (op: z.infer<typeof RollbackOperation>) => {
            const { failure_key, expected, target, target_smoke_request_key } =
              op.input;
            if (
              op.target_id !== config.instance ||
              !same(
                { failure_key, expected, target, target_smoke_request_key },
                request,
              ) ||
              (intent.operation_id !== null && intent.operation_id !== op.id)
            ) throw new Error("Rollback operation identity changed.");
            intent.operation_id = op.id;
          };
          if (intent.rollback_sent) {
            const receipt = await call(
              "GET",
              `/rollback/receipt?request_key=${
                encodeURIComponent(rollbackKey)
              }`,
              RollbackReceipt,
            );
            if (
              receipt.request_key !== rollbackKey ||
              receipt.found !== (receipt.operation !== null) ||
              (!receipt.found && receipt.deployment)
            ) throw new Error("Rollback receipt identity changed.");
            if (receipt.operation) {
              checkOp(receipt.operation);
              if (["failed", "cancelled"].includes(receipt.operation.status)) {
                return await finish(
                  "assistance_required",
                  receipt.operation.error_code ??
                    "Rollback ended without success; block retained.",
                );
              }
              if (receipt.operation.status !== "succeeded") {
                return await finish(
                  "rollback_pending",
                  "Waiting for guarded rollback.",
                );
              }
              const selected = (await context()).selected;
              if (
                receipt.deployment?.status !== "succeeded" ||
                receipt.deployment.commit !== target.commit ||
                receipt.deployment.release !== target.release ||
                !same(selected, selectionOf(receipt.deployment))
              ) {
                return await finish(
                  "assistance_required",
                  "Rollback success lacks selected target proof; block retained.",
                );
              }
              return await finish(
                "rolled_back",
                "Code restored; smoke and conditional resume still required.",
              );
            }
          }
          const current = await context();
          if (!same(current.selected, request.expected)) {
            return await finish(
              "superseded",
              "Selected deployment changed; block retained, no rollback.",
            );
          }
          if (!current.retained_releases.includes(target.release)) {
            return await finish(
              "assistance_required",
              "Rollback target was pruned; block retained.",
            );
          }
          const baseline = await call(
            "GET",
            `/receipt?request_key=${
              encodeURIComponent(request.target_smoke_request_key)
            }`,
            Receipt,
          );
          if (
            baseline.operation?.target_id !== config.instance ||
            !execution(
              baseline,
              { ...job.request, ...target },
              request.target_smoke_request_key,
              false,
            )
          ) {
            return await finish(
              "assistance_required",
              "Prior target has no passing execution proof; block retained.",
            );
          }
          intent.rollback_sent = true;
          await finish("rollback_pending", "Guarded rollback intent saved.");
          checkOp(
            await call(
              "POST",
              "/rollback",
              RollbackOperation,
              request,
              rollbackKey,
            ),
          );
          return await finish(
            "rollback_pending",
            "Waiting for guarded rollback.",
          );
        } catch (error) {
          return await finish(
            recovery.intent?.block ? "assistance_required" : "hold_required",
            error instanceof Error
              ? error.message
              : "Unreadable recovery evidence.",
          );
        }
      }),
  };
}
