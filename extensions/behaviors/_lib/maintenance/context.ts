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
