import { z } from "npm:zod@4";
import { MaintenanceFailure } from "./io.ts";
export type Context = {
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ) => Promise<{ name: string }>;
};
export function parse<T>(schema: z.ZodType<T>, value: unknown): T {
  const result = schema.safeParse(value);
  if (!result.success) throw new MaintenanceFailure(result.error.message, 64);
  return result.data;
}
export async function write(
  ctx: Context,
  spec: string,
  schema: z.ZodType,
  value: unknown,
) {
  const data = schema.parse(value) as Record<string, unknown>;
  const handle = await ctx.writeResource(spec, spec, data);
  return { dataHandles: [handle] };
}

export const FailureSchema = z.strictObject({
  exitCode: z.number().int().refine((n) => n !== 0),
  message: z.string(),
});
export const failureResource = {
  description:
    "A completed maintenance failure; transport loss never produces this receipt",
  schema: FailureSchema,
  lifetime: "30d",
  garbageCollection: 20,
};

/** Opt-in typed command failures for workflows. Abort and infrastructure errors still throw. */
export function failureReceipts<
  T extends Record<
    string,
    {
      execute: (
        input: never,
        ctx: Context,
      ) => Promise<{ dataHandles: { name: string }[] }>;
    }
  >,
>(methods: T): T {
  for (const method of Object.values(methods)) {
    const execute = method.execute;
    method.execute = async (input, ctx) => {
      try {
        return await execute(input, ctx);
      } catch (error) {
        if (
          !(input as { captureFailure?: boolean }).captureFailure ||
          !(error instanceof MaintenanceFailure) || ctx.signal?.aborted
        ) throw error;
        return await write(ctx, "failure", FailureSchema, {
          exitCode: error.exitCode || 70,
          message: error.message.slice(-16000),
        });
      }
    };
  }
  return methods;
}
