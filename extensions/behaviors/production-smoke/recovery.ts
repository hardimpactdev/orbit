import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import { projectConfigurations } from "../_lib/project_config.ts";
import {
  Job,
  Observation,
  Operation,
  Receipt,
  requestOf,
  same,
  Selection,
  selectionOf,
  State,
} from "./contract.ts";
import { Recovery, RollbackReceipt } from "./failure.ts";
import { type Context, productionSmoke, withinBudget } from "./mod.ts";

export const ControlRequest = z.object({
  action: z.enum(["hold", "alert", "release", "redeploy"]),
  hold_key: z.string(),
  expected: Selection,
  smoke_request_key: z.string().nullable(),
  rollback_request_key: z.string().nullable(),
}).strict();
const Control = z.object({
  request_key: z.string(),
  instance_id: z.number().int().positive(),
  action: z.string(),
  hold_key: z.string(),
  held: z.boolean(),
  resumed: z.boolean(),
  reason: z.string().nullable(),
}).strict();
const ControlReceipt = z.object({
  request_key: z.string(),
  found: z.boolean(),
  control: Control.nullable(),
}).strict();
const Redeploy = Operation.omit({ kind: true, target_type: true, input: true })
  .extend({
    kind: z.literal("instance.deploy"),
    target_type: z.literal("instance"),
    input: ControlRequest.extend({
      commit: z.string(),
      smoke_hold_key: z.string(),
    }).passthrough(),
  });
const RedeployReceipt = RollbackReceipt.extend({
  operation: Redeploy.nullable(),
});
export const Lifecycle = z.object({
  status: z.enum([
    "disabled",
    "held",
    "waiting",
    "recovering",
    "restored",
    "passed",
    "assistance_required",
  ]),
  reason: z.string().nullable(),
  hold: ControlRequest.nullable(),
  rollback_key: z.string().nullable(),
  redeploy: ControlRequest.nullable(),
  restored: z.boolean(),
  generation: z.number().int().nonnegative(),
  failures: z.number().int().nonnegative(),
  due: z.number(),
  released: z.boolean(),
  released_for: Selection.nullable().default(null),
  initialized: z.boolean().default(false),
  pre_hold_key: z.string().nullable().default(null),
  config: z.string().nullable().default(null),
  release: ControlRequest.nullable().default(null),
});
export function lifecycleMethod(
  makeHttp: ((ctx: Context) => Http) | undefined,
  clock: () => number,
  budget: number,
  effects: boolean,
) {
  return {
    description: "Advance one durable recovery tick; live effects remain gated",
    arguments: z.object({ apply: z.boolean().default(false) }),
    execute: (input: { apply?: boolean }, context: Context) =>
      withinBudget(context, budget, async (ctx) => {
        let life = Lifecycle.parse(
          await ctx.readResource("lifecycle") ??
            {
              status: "disabled",
              reason: null,
              hold: null,
              rollback_key: null,
              redeploy: null,
              restored: false,
              generation: 0,
              failures: 0,
              due: 0,
              released: false,
            },
        );
        const save = async (
          status: z.infer<typeof Lifecycle>["status"],
          reason: string | null = null,
        ) => {
          ctx.signal?.throwIfAborted();
          life.status = status;
          life.reason = reason;
          return {
            dataHandles: [
              await ctx.writeResource("lifecycle", "lifecycle", life),
            ],
          };
        };
        if (!effects || !input.apply) {
          return await save("disabled", "Live recovery effects are disabled.");
        }
        let notify: (() => Promise<unknown>) | undefined;
        try {
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
            return await save(
              "assistance_required",
              "Project is not opted in; any hold remains.",
            );
          }
          const fingerprint = JSON.stringify({
            projectId: project.projectId,
            repository: project.repository,
            ...config,
          });
          if (life.hold && !life.released && life.config !== fingerprint) {
            throw new Error("Configuration changed; original hold retained.");
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
            const reply = await http(method, root + path, body, key);
            if (![200, 202].includes(reply.status)) {
              throw new Error(JSON.stringify(reply.body));
            }
            return schema.parse(
              z.object({ data: z.unknown() }).parse(reply.body).data,
            );
          };
          const observed = await call("GET", "/context", Observation);
          if (
            observed.instance_id !== config.instance ||
            observed.project_id !== project.projectId ||
            observed.repository_url !== project.repository ||
            !observed.production ||
            !observed.base_urls.includes(config.baseUrl) || !observed.selected
          ) throw new Error("Recovery ownership or selected release changed.");
          const selected = observed.selected;
          const control = async (
            body: z.infer<typeof ControlRequest>,
            key: string,
          ) => {
            const receipt = await call(
              "GET",
              `/control/receipt?request_key=${encodeURIComponent(key)}`,
              ControlReceipt,
            );
            if (
              receipt.request_key !== key ||
              receipt.found !== (receipt.control !== null)
            ) throw new Error("Control receipt identity changed.");
            const result = receipt.control &&
                receipt.control.reason !== "release.alert_delivery_failed"
              ? receipt.control
              : await call("POST", "/control", Control, body, key);
            if (
              result.request_key !== key ||
              result.instance_id !== config.instance ||
              result.hold_key !== body.hold_key ||
              result.action !== body.action ||
              result.held === (body.action === "release")
            ) throw new Error("Control identity changed.");
            if (result.reason === "release.alert_delivery_failed") {
              throw new Error(result.reason);
            }
            return result;
          };
          if (life.release && !life.released) {
            const key = life.release.hold_key +
              `.release.${life.release.expected.deployment_id}`;
            const receipt = await call(
              "GET",
              `/control/receipt?request_key=${encodeURIComponent(key)}`,
              ControlReceipt,
            );
            if (
              receipt.request_key !== key ||
              receipt.found !== (receipt.control !== null)
            ) throw new Error("Release receipt changed.");
            if (receipt.control) {
              if (
                receipt.control.request_key !== key ||
                receipt.control.instance_id !== config.instance ||
                receipt.control.hold_key !== life.release.hold_key ||
                receipt.control.action !== "release" || receipt.control.held
              ) throw new Error("Release identity changed.");
              life.released = true;
              life.released_for = life.release.expected;
              await save("passed");
            } else if (!same(selected, life.release.expected)) {
              life.release = null;
              await save("held");
            }
          }
          if (life.released && same(life.released_for, selected)) {
            return await save("passed");
          }
          if (!life.hold || life.released) {
            life = Lifecycle.parse({
              status: "held",
              reason: null,
              hold: {
                action: "hold",
                hold_key:
                  `smoke-hold.${config.instance}.${selected.deployment_id}`,
                expected: selected,
                smoke_request_key: null,
                rollback_request_key: null,
              },
              rollback_key: null,
              redeploy: null,
              restored: false,
              generation: 0,
              failures: 0,
              due: 0,
              released: false,
              config: fingerprint,
            });
            await save("held");
          }
          const hold = life.hold!;
          await control(hold, hold.hold_key + ".hold");
          const alert = () =>
            control(
              { ...hold, action: "alert" },
              hold.hold_key + `.alert.${life.generation}`,
            );
          notify = alert;
          const methods =
            productionSmoke(makeHttp, clock, budget, effects).methods;
          if (life.rollback_key && !life.restored && !life.redeploy) {
            await methods.failure.execute({
              apply: true,
              deploymentId: hold.expected.deployment_id,
            }, ctx);
            const recovery = Recovery.parse(await ctx.readResource("recovery"));
            if (recovery.status !== "rolled_back") {
              if (
                recovery.status === "assistance_required" ||
                recovery.status === "hold_required" ||
                recovery.status === "superseded"
              ) {
                await alert();
                return await save("assistance_required", recovery.reason);
              }
              return await save("recovering", recovery.reason);
            }
            const receipt = await call(
              "GET",
              `/rollback/receipt?request_key=${
                encodeURIComponent(life.rollback_key)
              }`,
              RollbackReceipt,
            );
            if (
              !receipt.found || receipt.operation?.status !== "succeeded" ||
              !receipt.deployment ||
              !same(selected, selectionOf(receipt.deployment)) ||
              receipt.operation.target_id !== config.instance ||
              !same(receipt.operation.input.expected, hold.expected)
            ) throw new Error("Restored rollback proof changed.");
            if (receipt.operation.input.recovery_redeploy_required === true) {
              life.redeploy ??= {
                ...hold,
                action: "redeploy",
                expected: selected,
                rollback_request_key: life.rollback_key,
              };
              await save("recovering");
            } else life.restored = true;
          }
          if (life.redeploy && !life.restored) {
            const key = hold.hold_key + ".redeploy";
            const receipt = await call(
              "GET",
              `/redeploy/receipt?request_key=${encodeURIComponent(key)}`,
              RedeployReceipt,
            );
            if (
              receipt.request_key !== key ||
              receipt.found !== (receipt.operation !== null)
            ) throw new Error("Redeploy receipt changed.");
            const op = receipt.operation ??
              await call("POST", "/redeploy", Redeploy, life.redeploy, key);
            if (
              op.target_id !== config.instance ||
              !same(ControlRequest.strip().parse(op.input), life.redeploy) ||
              op.input.commit !== life.redeploy.expected.commit
            ) throw new Error("Redeploy identity changed.");
            if (["failed", "cancelled"].includes(op.status)) {
              await alert();
              return await save("assistance_required", op.error_code);
            }
            if (op.status !== "succeeded") return await save("recovering");
            if (
              receipt.deployment?.status !== "succeeded" ||
              receipt.deployment.commit !== life.redeploy.expected.commit ||
              !same(selected, selectionOf(receipt.deployment))
            ) throw new Error("Redeploy lacks selected proof.");
            life.restored = true;
          }
          if (!life.initialized) {
            const prior = await ctx.readResource("state");
            const state = prior ? State.parse(prior) : null;
            life.pre_hold_key =
              state?.job && same(selectionOf(state.job.request), selected)
                ? state.job.key
                : null;
            life.initialized = true;
          }
          await save(life.restored ? "restored" : "held");
          await methods.tick.execute({ mode: "observe" }, ctx);
          const state = State.parse(await ctx.readResource("state"));
          if (state.status === "passed" && state.job) {
            if (life.pre_hold_key === state.job.key) {
              life.generation++;
              Object.assign(state.job, {
                key:
                  `smoke.${config.instance}.${selected.deployment_id}.retry.${life.generation}`,
                operation_id: null,
                result: null,
                resend_used: false,
                refusal_retry_used: false,
                refusal: null,
                started_at: new Date(clock()).toISOString(),
              });
              state.status = "pending";
              await ctx.writeResource(
                "job",
                `job-${selected.deployment_id}`,
                state.job,
              );
              await ctx.writeResource("state", "state", state);
              await save("waiting");
              await methods.tick.execute({ mode: "baseline" }, ctx);
              return await save("waiting");
            }
            life.release = {
              ...hold,
              action: "release",
              expected: selected,
              smoke_request_key: state.job.key,
              rollback_request_key: life.rollback_key,
            };
            await save("held", "Release intent saved.");
            await control(
              life.release,
              hold.hold_key + `.release.${selected.deployment_id}`,
            );
            life.released = true;
            life.released_for = selected;
            return await save("passed");
          }
          if (state.status === "failed" && state.job) {
            if (life.restored || !same(selected, hold.expected)) {
              await alert();
              return await save(
                "assistance_required",
                "Restored or replacement release failed smoke; no further rollback.",
              );
            }
            life.rollback_key =
              `smoke-failure.${config.instance}.${selected.deployment_id}.rollback`;
            await save("recovering");
            await methods.failure.execute({
              apply: true,
              deploymentId: selected.deployment_id,
            }, ctx);
            return await save("recovering");
          }
          if (!state.job) {
            await methods.tick.execute({ mode: "baseline" }, ctx);
            return await save("waiting");
          }
          if (!["unknown", "timed_out"].includes(state.status)) {
            await methods.tick.execute({ mode: "baseline" }, ctx);
            return await save("waiting");
          }
          if (clock() < life.due) {
            return await save("waiting", "Retry backoff.");
          }
          await alert();
          const job = state.job;
          // Read before retry: a local deadline cannot discard a late result or a still-running Gateway job.
          const receipt = await call(
            "GET",
            `/receipt?request_key=${encodeURIComponent(job.key)}`,
            Receipt,
          );
          if (
            receipt.request_key !== job.key ||
            receipt.found !== (receipt.operation !== null) ||
            (receipt.operation &&
              ((job.operation_id !== null &&
                receipt.operation.id !== job.operation_id) ||
                !same(requestOf(receipt.operation.input), job.request) ||
                receipt.operation.target_id !== config.instance))
          ) throw new Error("Retry receipt identity changed.");
          if (receipt.smoke && !same(requestOf(receipt.smoke), job.request)) {
            throw new Error("Retry result identity changed.");
          }
          if (
            receipt.smoke && ["passed", "failed"].includes(receipt.smoke.state)
          ) {
            state.job.result = null;
            state.status = "pending";
          } else if (
            receipt.operation &&
            !["succeeded", "failed", "cancelled"].includes(
              receipt.operation.status,
            )
          ) {
            life.failures++;
            life.due = clock() +
              Math.min(900_000, 30_000 * 2 ** Math.min(life.failures - 1, 5));
            return await save(
              "waiting",
              "Gateway smoke is still running; keep its original key.",
            );
          } else {
            if (receipt.found) {
              life.generation++;
              job.key =
                `smoke.${config.instance}.${selected.deployment_id}.retry.${life.generation}`;
              job.operation_id = null;
            }
            job.result = null;
            job.started_at = new Date(clock()).toISOString();
            job.resend_used = false;
            job.refusal_retry_used = false;
            state.status = "pending";
          }
          life.failures++;
          life.due = clock() +
            Math.min(900_000, 30_000 * 2 ** Math.min(life.failures - 1, 5));
          ctx.signal?.throwIfAborted();
          await ctx.writeResource(
            "job",
            `job-${job.request.deployment_id}`,
            Job.parse(job),
          );
          await ctx.writeResource("state", "state", state);
          await save("waiting", "Retry intent and backoff saved.");
          await methods.tick.execute({ mode: "baseline" }, ctx);
          return await save("waiting");
        } catch (error) {
          if (clock() >= life.due) {
            try {
              await notify?.();
            } catch { /* Alert outage cannot release the hold. */ }
            life.failures++;
            life.due = clock() +
              Math.min(900_000, 30_000 * 2 ** Math.min(life.failures - 1, 5));
          }
          return await save(
            "assistance_required",
            error instanceof Error
              ? error.message
              : "Unreadable recovery evidence; hold retained.",
          );
        }
      }),
  };
}
