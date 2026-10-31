import { repairMethod, RepairState } from "./repair.ts";
import { Lifecycle, lifecycleMethod } from "./recovery.ts";
import { failureMethod, Recovery } from "./failure.ts";
import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import {
  type ProjectConfigContext,
  projectConfigurations,
  ProjectExtensionsSchema,
} from "../_lib/project_config.ts";
import {
  Job,
  Observation,
  Operation,
  Receipt,
  Request,
  requestOf,
  same,
  selectionOf,
  State,
} from "./contract.ts";

const Globals = z.object({
  projectId: z.number().int().positive(),
  projectType: ProjectExtensionsSchema.element.shape.type,
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().default(
    "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
  ),
});
export interface Context extends ProjectConfigContext {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
}
class GatewayError extends Error {
  constructor(readonly status: number, readonly code: string, message: string) {
    super(`${code}: ${message}`);
  }
}
export async function withinBudget<T>(
  ctx: Context,
  ms: number,
  run: (ctx: Context) => Promise<T>,
): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(
    () => controller.abort(new Error("Smoke tick budget exceeded.")),
    ms,
  );
  const signal = AbortSignal.any([
    controller.signal,
    ...(ctx.signal ? [ctx.signal] : []),
  ]);
  let onAbort = () => {};
  const aborted = new Promise<never>((_, reject) => {
    onAbort = () => reject(signal.reason);
    signal.addEventListener("abort", onAbort, { once: true });
  });
  try {
    signal.throwIfAborted();
    return await Promise.race([run({ ...ctx, signal }), aborted]);
  } finally {
    clearTimeout(timer);
    signal.removeEventListener("abort", onAbort);
  }
}
/** Recovery effects stay disabled in the shipped behavior. */
export function productionSmoke(
  makeHttp?: (ctx: Context) => Http,
  clock = () => Date.now(),
  budgetMs = 120_000,
  recoveryEffects = false,
) {
  return {
    type: "@nckrtl/production-smoke",
    version: "2026.10.28.4",
    globalArguments: Globals,
    resources: {
      repair: {
        description:
          "Permanent repair intent, Gateway receipt and factory handoff",
        schema: RepairState,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      lifecycle: {
        description: "Owned hold, retry backoff and restored smoke",
        schema: Lifecycle,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      recovery: {
        description: "Failure recovery intent and receipts",
        schema: Recovery,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      state: {
        description: "Current observation and last proven baseline",
        schema: State,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      job: {
        description: "Permanent per-deployment intent and smoke evidence",
        schema: Job,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      repair: repairMethod(makeHttp, recoveryEffects, budgetMs),
      recover: lifecycleMethod(makeHttp, clock, budgetMs, recoveryEffects),
      failure: failureMethod(makeHttp, recoveryEffects, budgetMs),
      tick: {
        description:
          "Observe production; dispatch only an explicitly requested baseline",
        arguments: z.object({
          mode: z.enum(["observe", "baseline"]).default("observe"),
        }),
        execute: (
          input: { mode?: "observe" | "baseline" },
          ctx: Context,
        ) =>
          withinBudget(ctx, budgetMs, async (ctx) => {
            const { projectId, projectType } = Globals.parse(ctx.globalArgs);
            const mode = input.mode ?? "observe";
            const state = State.parse(
              await ctx.readResource("state") ?? {
                status: "disabled",
                reason: null,
                selected: null,
                job: null,
                known_good: null,
              },
            );
            if (state.job) {
              // The permanent job is written first and can be newer than the state projection.
              const retained = await ctx.readResource(
                `job-${state.job.request.deployment_id}`,
              );
              if (retained) state.job = Job.parse(retained);
            }
            const save = async () => {
              ctx.signal?.throwIfAborted();
              if (state.job) {
                await ctx.writeResource(
                  "job",
                  `job-${state.job.request.deployment_id}`,
                  state.job,
                );
              }
              ctx.signal?.throwIfAborted();
              return {
                dataHandles: [await ctx.writeResource("state", "state", state)],
              };
            };
            const [project] = await projectConfigurations(
              [],
              [{ projectId, type: projectType }],
              ctx,
              projectId,
            );
            const config = project.productionSmoke;
            if (!config) {
              state.status = "disabled";
              state.reason = "Not opted in; intent retained.";
              return await save();
            }
            const http = makeHttp?.(ctx) ??
              gatewayHttp(
                ctx.globalArgs.gatewayUrl,
                ctx.globalArgs.caFile,
                ctx.signal,
              );
            const path = `/api/v1/instances/${config.instance}/smoke`;
            async function call<T>(
              method: "GET" | "POST",
              suffix: string,
              schema: z.ZodType<T>,
              body?: unknown,
              key?: string,
            ) {
              ctx.signal?.throwIfAborted();
              const response = await http(method, path + suffix, body, key);
              if (![200, 202].includes(response.status)) {
                const error = z.object({
                  error: z.object({ code: z.string(), message: z.string() }),
                }).safeParse(response.body);
                throw new GatewayError(
                  response.status,
                  error.success
                    ? error.data.error.code
                    : `gateway.http_${response.status}`,
                  error.success
                    ? error.data.error.message
                    : "Unreadable smoke Gateway response.",
                );
              }
              return schema.parse(
                z.object({ data: z.unknown() }).parse(response.body).data,
              );
            }
            const dispatch = async () => {
              const job = state.job!;
              job.refusal = null;
              state.status = "pending";
              state.reason = null;
              await save(); // Every send, including recovery, has a durable bound intent.
              try {
                const operation = await call(
                  "POST",
                  "",
                  Operation,
                  job.request,
                  job.key,
                );
                if (
                  operation.target_id !== config.instance ||
                  !same(requestOf(operation.input), job.request)
                ) {
                  throw new Error("smoke admission identity changed");
                }
                job.operation_id = operation.id;
              } catch (error) {
                if (
                  error instanceof GatewayError && ((error.status >= 400 &&
                    error.status < 500) || (error.status === 503 &&
                      error.code === "instance.smoke_github_unavailable"))
                ) {
                  job.refusal = { status: error.status, code: error.code };
                  state.status = "unknown";
                  state.reason = error.message;
                } else {
                  state.reason =
                    "Unknown admission outcome; reconcile its key before recovery.";
                }
              }
              return await save();
            };
            const observe = async () => {
              const observation = await call("GET", "/context", Observation);
              if (
                observation.instance_id !== config.instance ||
                observation.project_id !== projectId ||
                observation.repository_url !== project.repository ||
                !observation.production ||
                !observation.base_urls.includes(config.baseUrl) ||
                (observation.selected &&
                  !observation.retained_releases.includes(
                    observation.selected.release,
                  ))
              ) {
                throw new Error(
                  "Smoke target does not match Project policy.",
                );
              }
              return observation.selected;
            };
            state.selected = await observe();
            const fingerprint = JSON.stringify({
              projectId,
              repository: project.repository,
              ...config,
            });
            if (
              state.job && !same(state.selected, selectionOf(state.job.request))
            ) {
              state.status = "superseded";
              state.reason = "Selected release changed; evidence superseded.";
              await save();
              state.job = null;
              return await save();
            }
            if (!state.selected) {
              state.status = "observed";
              state.reason = "No successful selected deployment is retained.";
              return await save();
            }
            if (!state.job) {
              const retained = await ctx.readResource(
                `job-${state.selected.deployment_id}`,
              );
              if (retained) {
                state.job = Job.parse(retained);
                state.status = "pending";
              }
            }
            if (state.job) {
              if (state.job.config !== fingerprint) {
                state.status = "unknown";
                state.reason = "Config changed; original intent retained.";
                return await save();
              }
              if (
                state.job.result && state.job.result.state === state.status &&
                ["passed", "failed", "unknown"].includes(state.status)
              ) return await save();
              const receipt = await call(
                "GET",
                `/receipt?request_key=${encodeURIComponent(state.job.key)}`,
                Receipt,
              );
              if (
                receipt.request_key !== state.job.key ||
                (receipt.found !== (receipt.operation !== null)) ||
                (!receipt.found && receipt.smoke)
              ) {
                throw new Error("smoke receipt identity is invalid");
              }
              if (receipt.operation) {
                const op = receipt.operation;
                if (
                  op.target_id !== config.instance ||
                  !same(requestOf(op.input), state.job.request) ||
                  (state.job.operation_id !== null &&
                    state.job.operation_id !== op.id)
                ) throw new Error("smoke operation identity changed");
                state.job.operation_id = op.id;
              }
              if (receipt.smoke) {
                const result = receipt.smoke;
                if (
                  !receipt.operation ||
                  !same(requestOf(result), state.job.request)
                ) throw new Error("smoke result identity changed");
                state.job.result = result;
                state.status = result.state;
                if (
                  result.state === "failed" &&
                  (!result.run_id || !result.run_attempt ||
                    !result.workflow_sha || !result.run_url ||
                    result.tests <= result.skipped ||
                    result.failures + result.errors < 1)
                ) {
                  state.status = "unknown";
                  state.reason =
                    "Smoke failure has no verified test failure or error.";
                  return await save();
                }
                if (result.state === "passed") {
                  if (receipt.operation.status !== "succeeded") {
                    state.status = "unknown";
                    state.reason = receipt.operation.error_code ??
                      "Smoke pass has no successful operation.";
                    return await save();
                  }
                  if (
                    result.conclusion !== "success" ||
                    !result.run_id || !result.run_attempt ||
                    !result.workflow_sha || !result.run_url ||
                    result.tests < 1 ||
                    result.failures + result.errors + result.skipped !== 0
                  ) {
                    throw new Error(
                      "Smoke pass has no execution proof.",
                    );
                  }
                  state.selected = await observe();
                  state.status =
                    same(state.selected, selectionOf(state.job.request))
                      ? "passed"
                      : "superseded";
                  if (state.status === "passed") {
                    state.known_good = state.selected;
                    state.known_good_key = state.job.key;
                  }
                }
                state.reason = state.status === "passed"
                  ? null
                  : (result.output || receipt.operation.error_code);
                if (result.state !== "pending") return await save();
              }
              if (
                receipt.operation &&
                ["failed", "cancelled"].includes(receipt.operation.status)
              ) {
                state.status = "unknown";
                state.reason = receipt.operation.error_code ??
                  "Smoke operation ended without a verified result.";
                return await save();
              }
              const expired = clock() - Date.parse(state.job.started_at) >=
                config.timeoutSeconds * 1000;
              if (!receipt.found && state.job.refusal) {
                state.status = "unknown";
                state.reason =
                  `${state.job.refusal.code}: Gateway refused smoke admission (HTTP ${state.job.refusal.status}).`;
                const transient =
                  state.job.refusal.code === "operation.target_busy" ||
                  state.job.refusal.code ===
                    "instance.smoke_github_unavailable";
                if (
                  mode === "observe" && transient && !expired &&
                  !state.job.refusal_retry_used
                ) {
                  // The workflow's durable wait precedes this follow-up observation.
                  // Spend the automatic retry before sending; keep transport recovery separate.
                  state.job.refusal_retry_used = true;
                  return await dispatch();
                }
                if (mode === "baseline" && !expired) return await dispatch();
                return await save();
              }
              if (expired) {
                state.status = "timed_out";
                state.reason =
                  "Smoke deadline expired without proof; receipt remains readable.";
                return await save();
              }
              if (
                !receipt.found && state.job.operation_id === null &&
                mode === "baseline" && !state.job.resend_used
              ) {
                state.job.resend_used = true; // Persist the one recovery allowance before POST.
                return await dispatch();
              }
              state.status = "pending";
              state.reason = receipt.found
                ? "Waiting for smoke evidence."
                : "Unresolved admission receipt.";
              return await save();
            }
            state.status = "observed";
            state.reason = "Read-only observation; baseline was not requested.";
            if (mode === "observe") return await save();
            const request = Request.parse({
              ...state.selected,
              base_url: config.baseUrl,
              workflow: config.workflow,
              ref: config.ref,
            });
            state.job = {
              key: `smoke.${config.instance}.${request.deployment_id}`,
              config: fingerprint,
              request,
              started_at: new Date(clock()).toISOString(),
              operation_id: null,
              result: null,
              previous_good: state.known_good,
              previous_good_key: state.known_good_key,
              resend_used: false,
              refusal_retry_used: false,
              refusal: null,
            };
            return await dispatch();
          }),
      },
    },
  };
}
export const behavior = {
  ...productionSmoke(),
  type: "@nckrtl/production-smoke",
};
