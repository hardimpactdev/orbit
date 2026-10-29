import registration from "./registration.json" with { type: "json" };
import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import {
  Completion,
  EffectReceipt,
  Invocation,
  Leaf,
  Result,
  Scope,
} from "./contract.ts";
import { CertificateRefusal } from "./contract.ts";

export function certificates(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-certificates",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description: "Bind a dispatch retry to its original claimed Operation",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      invocation: {
        description: "Certificate invocation identity before a Node effect",
        schema: Invocation,
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Permanent certificate completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description: "Converge or remove one Node-owned certificate scope",
        arguments: z.object({
          kind: z.string().refine((kind) => registration.kinds.includes(kind)),
          worker: z.string(),
        }),
        execute: async (
          dispatch: { kind: string; worker: string },
          ctx: Context,
        ) => {
          if (!registration.kinds.includes(dispatch.kind)) {
            throw new Error("Unregistered family kind");
          }
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const attempt = `assignment-${dispatch.worker}`;
          const assigned = await ctx.readResource(attempt);
          let id: number;
          if (assigned) {
            id = z.object({ operation_id: z.number().int().positive() })
              .parse(assigned).operation_id;
            const saved = await ctx.readResource(`operation-${id}`);
            if (saved) {
              const completion = Completion.parse(saved);
              const outcome = z.object({
                id: z.number().int().positive(),
                kind: z.string(),
                status: z.string(),
                result: z.preprocess(
                  (value) =>
                    Array.isArray(value) && value.length === 0 ? {} : value,
                  z.record(z.string(), z.unknown()).nullable(),
                ),
                error_code: z.string().nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                const ordered = (value: unknown): unknown =>
                  Array.isArray(value)
                    ? value.map(ordered)
                    : value !== null && typeof value === "object"
                    ? Object.fromEntries(
                      Object.entries(value).sort(([a], [b]) =>
                        a.localeCompare(b)
                      )
                        .map(([key, item]) => [key, ordered(item)]),
                    )
                    : value;
                if (
                  outcome.id !== id || outcome.kind !== dispatch.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  JSON.stringify(ordered(outcome.result ?? {})) !==
                    JSON.stringify(ordered(completion.result))
                ) {
                  throw new Error("Unproven saved certificates completion");
                }
                return { status: completion.status };
              }
            }
          } else {
            const claimed = await executor.claim(
              dispatch.kind,
              dispatch.worker,
            );
            if (!claimed) return { status: "idle" };
            if (claimed.kind !== dispatch.kind) {
              throw new Error("Wrong claimed certificates kind");
            }
            id = claimed.id;
            await ctx.writeResource("assignment", attempt, {
              operation_id: id,
            });
          }
          const args = { operationId: id, worker: dispatch.worker };
          const path = String(id), resource = `operation-${path}`;
          const saved = await ctx.readResource(resource);
          const operation = Operation.parse(
            await executor.post(`${path}/heartbeat`, { worker: args.worker }),
          );
          if (operation.id !== id || operation.kind !== dispatch.kind) {
            throw new Error("Wrong certificates assignment");
          }
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { status: completion.status };
          }
          if (
            !["certificate.converge", "certificate.remove"].includes(
              operation.kind,
            )
          ) throw new Error("Unknown certificate kind");
          const leaf = operation.kind === "certificate.converge"
            ? Leaf.parse(operation.input)
            : null;
          const scope = Scope.parse(operation.input);
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          const step = async (
            key: string,
            transport: string,
            name: string,
            input: Record<string, unknown>,
          ) => {
            const recorded = operation.step_results[key];
            if (recorded) {
              return Result.parse(recorded);
            }
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${key}:start`,
              name: key,
              status: "running",
              output: `${key}\n`,
            });
            const invocation = { key, transport, name, input };
            const invocationResource = `${resource}-effect-${key}`;
            const attempted = transport === "gateway"
              ? null
              : await ctx.readResource(invocationResource);
            let recovered: Record<string, unknown> | undefined;
            if (attempted) {
              if (
                JSON.stringify(Invocation.parse(attempted)) !==
                  JSON.stringify(invocation)
              ) {
                throw new Error(
                  "Certificate effect input changed during recovery",
                );
              }
              const response = await http(
                "GET",
                `/api/v1/operation-executor/${path}/receipts/${
                  encodeURIComponent(key)
                }`,
              );
              if (response.status !== 200) {
                throw new Error("Certificate effect receipt unavailable");
              }
              const effect = EffectReceipt.parse(
                z.object({ data: z.unknown() }).parse(response.body).data,
              );
              if (effect.effect_key !== `op-${path}.${key}`) {
                throw new Error(
                  "Certificate effect receipt has another identity",
                );
              }
              if (effect.state === "running") {
                throw new Error("Certificate effect is still running");
              }
              if (effect.state === "succeeded" || effect.state === "failed") {
                if (!effect.result) {
                  throw new Error("Certificate effect receipt has no result");
                }
                recovered = effect.state === "failed" &&
                    typeof effect.result.error_code !== "string"
                  ? {
                    ...effect.result,
                    error_code: "operation.effect_failed",
                  }
                  : effect.result;
              }
            } else if (transport !== "gateway") {
              await ctx.writeResource(
                "invocation",
                invocationResource,
                invocation,
              );
            }
            const result = Result.parse(
              recovered ??
                await executor.post(`${path}/run`, {
                  worker: args.worker,
                  ...invocation,
                }),
            );
            if (heartbeatError) throw heartbeatError;
            const error = typeof result.error_code === "string"
              ? result.error_code
              : null;
            if (error && !/^[a-z0-9_.-]+$/.test(error)) {
              throw new Error("Invalid certificate refusal code");
            }
            await executor.post(`${path}/step`, {
              worker: args.worker,
              key: `${key}:finish`,
              name: key,
              status: error ? "failed" : "succeeded",
              result,
              error_code: error,
              output: error ? `${error}\n` : `${key} succeeded\n`,
            });
            if (error) throw new CertificateRefusal(error);
            return result;
          };
          try {
            let completion: z.infer<typeof Completion>;
            if (
              operation.cancel_requested && operation.steps.length === 0 &&
              !operation.step_results["certificate-inspect"]
            ) {
              completion = {
                status: "cancelled",
                result: {},
                error_code: null,
              };
            } else {
              try {
                let result: Record<string, unknown>;
                if (!leaf) {
                  result = await step(
                    "certificate-remove",
                    "agent",
                    "certificate.remove-leaf",
                    scope,
                  );
                } else {
                  const request = await step(
                    "certificate-request",
                    "certificate-request",
                    "certificate.request-leaf",
                    leaf,
                  );
                  if (request.state === "request") {
                    if (typeof request.request_base64 !== "string") {
                      throw new Error("Unproven signing request");
                    }
                    const signed = await step(
                      "certificate-sign",
                      "gateway",
                      "certificate.sign-leaf",
                      {
                        domain: leaf.domain,
                        request_base64: request.request_base64,
                      },
                    );
                    if (signed.signed !== true) {
                      throw new Error("Unproven certificate signature");
                    }
                    await step(
                      "certificate-publish",
                      "agent",
                      "certificate.publish-leaf",
                      {
                        ...leaf,
                        certificate: {
                          $secret: "certificate.leaf",
                          id: operation.id,
                        },
                        root_certificate: {
                          $secret: "certificate.leaf",
                          id: operation.id,
                        },
                      },
                    );
                  } else if (request.state !== "current") {
                    throw new Error("Unproven certificate request");
                  }
                  result = await step(
                    "certificate-inspect",
                    "agent",
                    "certificate.inspect-leaf",
                    scope,
                  );
                  if (result.present !== true) {
                    throw new Error("Unproven certificate publication");
                  }
                }
                completion = { status: "succeeded", result, error_code: null };
              } catch (error) {
                if (!(error instanceof CertificateRefusal)) throw error;
                completion = {
                  status: error.code === "operation.cancelled"
                    ? "cancelled"
                    : "failed",
                  result: { error_code: error.code },
                  error_code: error.code,
                };
              }
            }
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { dataHandles: [handle], status: completion.status };
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = {
  ...certificates(),
  type: "@nckrtl/operation-certificates",
};
