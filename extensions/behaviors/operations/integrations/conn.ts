import { z } from "npm:zod@4.4.3";
import {
  type Context,
  Executor,
  Operation,
} from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Completion, ErrorCode } from "./contract.ts";
import { runEffect } from "./pipeline.ts";

const Descriptor = z.object({
  key: z.string(),
  step: z.string(),
  transport: z.literal("gateway"),
  name: z.literal("conn.revocation"),
  input: z.object({
    environment: z.object({
      $secret: z.literal("conn.environment"),
      id: z.number().int().positive(),
    }).strict(),
    action: z.enum(["inspect", "session", "link", "receipt"]),
    id: z.string().max(1024),
  }).strict(),
  resumable: z.literal(true),
  required: z.literal(true),
});
const Inspection = z.object({
  backend: z.literal("inspected"),
  sessions: z.array(z.string().max(1024)).max(4096),
  links: z.array(z.string().max(1024)).max(4096),
}).strict();
const Effect = z.object({ backend: z.literal("revoked"), revoked: z.boolean() })
  .strict();
const Final = z.object({
  backend: z.literal("revoked"),
  revoked_sessions: z.number().int().nonnegative(),
}).strict();
const Failure = z.object({ error_code: ErrorCode });

/** Runs each owner-bound session/link separately; a restart reuses the same effect key. */
export async function revokeConn(
  operation: z.infer<typeof Operation>,
  executor: Executor,
  worker: string,
  ctx: Context,
  checkLease: () => void,
) {
  const finalStep = "gateway-sessions-revoke";
  let current = operation;
  for (;;) {
    checkLease();
    const descriptors = z.array(Descriptor).min(1).max(8194).parse(
      current.execution_context?.descriptors,
    );
    const saved = current.step_results[finalStep];
    if (saved !== undefined) {
      return await complete({
        status: "succeeded",
        result: Final.parse(saved),
        error_code: null,
      });
    }
    const descriptor = descriptors.find((item) =>
      current.step_results[item.step] === undefined
    );
    if (!descriptor) throw new Error("conn.revocation_unproven");
    const started = current.steps.some((step) => step.name === descriptor.step);
    if (current.cancel_requested && !started) {
      return await complete({
        status: "cancelled",
        result: {},
        error_code: null,
      });
    }
    await executor.post(`${current.id}/step`, {
      worker,
      key: `${descriptor.key}:start`,
      name: descriptor.step,
      status: "running",
    });
    const raw = await runEffect(
      executor,
      current.id,
      worker,
      descriptor,
      started,
    );
    checkLease();
    const failure = Failure.safeParse(raw);
    const result = failure.success
      ? failure.data
      : descriptor.input.action === "inspect"
      ? Inspection.parse(raw)
      : descriptor.input.action === "receipt"
      ? Final.parse(raw)
      : Effect.parse(raw);
    const error = "error_code" in result ? result.error_code : null;
    await executor.post(`${current.id}/step`, {
      worker,
      key: `${descriptor.key}:finish`,
      name: descriptor.step,
      status: error ? "failed" : "succeeded",
      result,
      error_code: error,
    });
    if (error) {
      return await complete({
        status: error === "operation.cancelled" ? "cancelled" : "failed",
        result,
        error_code: error,
      });
    }
    if (descriptor.input.action === "receipt") {
      return await complete({ status: "succeeded", result, error_code: null });
    }
    current = Operation.parse(
      await executor.post(`${current.id}/heartbeat`, { worker }),
    );
  }
  async function complete(value: z.infer<typeof Completion>) {
    const handle = await ctx.writeResource(
      "receipt",
      `operation-${operation.id}`,
      value,
    );
    await executor.post(`${operation.id}/complete`, { worker, ...value });
    return { status: value.status, dataHandles: [handle] };
  }
}

/** Admission is periodic so a new registration cannot be lost behind an active cleanup. */
export async function maintainConn(http: Http, _tick: string) {
  const response = await http("GET", "/api/v1/conn/environments");
  if (
    response.status === 403 &&
    z.object({ error: z.object({ code: z.literal("extension.disabled") }) })
      .safeParse(response.body).success
  ) return { admitted: [], busy: 0 };
  if (response.status !== 200) throw new Error("conn.maintenance_read_failed");
  const environments = z.object({
    data: z.array(
      z.object({
        environment_id: z.string().max(255),
        status: z.string(),
        cleanup_pending: z.boolean().optional(),
        cleanup_version: z.number().int().nonnegative(),
      }),
    ).max(4096),
  }).parse(response.body).data;
  const admitted: number[] = [];
  let busy = 0;
  for (const environment of environments) {
    if (!environment.cleanup_pending || environment.status !== "registered") {
      continue;
    }
    const digest = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(
        JSON.stringify([
          environment.environment_id,
          environment.cleanup_version,
        ]),
      ),
    );
    const key = "conn.cleanup:" +
      Array.from(
        new Uint8Array(digest),
        (byte) => byte.toString(16).padStart(2, "0"),
      ).join("");
    const result = await http(
      "POST",
      `/api/v1/conn/environments/${
        encodeURIComponent(environment.environment_id)
      }/sessions/cleanup`,
      {},
      key,
    );
    if (result.status === 204) continue;
    if (
      result.status === 409 &&
      z.object({
        error: z.object({ code: z.literal("operation.target_busy") }),
      }).safeParse(result.body).success
    ) {
      busy++;
      continue;
    }
    if (result.status !== 202) throw new Error("conn.cleanup_admission_failed");
    admitted.push(
      z.object({
        data: z.object({
          id: z.number().int().positive(),
          kind: z.literal("conn.gateway_sessions_revoke"),
        }),
      }).parse(result.body).data.id,
    );
  }
  return { admitted, busy };
}
