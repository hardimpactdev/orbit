import { z } from "npm:zod@4.4.3";
import { type Context, ExecutorError } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

export const Receipt = z.object({
  effect_key: z.string(),
  state: z.enum(["succeeded", "failed", "running", "unknown"]),
  exit_code: z.number().int().nullable(),
  result: z.record(z.string(), z.unknown()).nullable(),
  finished_at: z.string().nullable(),
});
export const EffectIntent = z.object({
  key: z.string(),
  transport: z.string(),
  name: z.string(),
  input: z.record(z.string(), z.unknown()),
});

/** Use kit I's target receipt before retrying an invocation whose reply was lost. */
export function effectPost(
  operationId: number,
  ctx: Context,
  http: Http,
  post: (suffix: string, body: Record<string, unknown>) => Promise<unknown>,
) {
  return async (suffix: string, body: Record<string, unknown>) => {
    if (
      suffix !== "run" ||
      !["agent", "remote_program"].includes(String(body.transport))
    ) {
      return await post(suffix, body);
    }
    const intent = EffectIntent.parse(body);
    const resource = `operation-${operationId}-effect-${intent.key}`;
    if (await ctx.readResource(resource)) {
      const receipt = await targetReceipt(operationId, intent.key, http);
      if (receipt.state === "running") {
        throw new ExecutorError("operation.effect_running");
      }
      if (receipt.state !== "unknown") {
        if (receipt.result === null) {
          throw new ExecutorError("operation.receipt_unavailable");
        }
        return receipt.state === "failed" && !receipt.result.error_code
          ? { ...receipt.result, error_code: "fleet.remote_effect_failed" }
          : receipt.result;
      }
    } else {
      await ctx.writeResource("effect", resource, intent);
    }
    // The executor derives effect_key from the Operation and this unchanged run key.
    return await post(suffix, body);
  };
}

export async function targetReceipt(
  operationId: number,
  key: string,
  http: Http,
) {
  const response = await http(
    "GET",
    `/api/v1/operation-executor/${operationId}/receipts/${
      encodeURIComponent(key)
    }`,
  );
  if (response.status !== 200) {
    throw new ExecutorError("operation.receipt_unavailable");
  }
  const receipt = Receipt.parse(
    z.object({ data: z.unknown() }).parse(response.body).data,
  );
  if (receipt.effect_key !== `op-${operationId}.${key}`) {
    throw new ExecutorError("operation.receipt_mismatch");
  }
  return receipt;
}
