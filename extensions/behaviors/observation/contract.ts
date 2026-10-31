import { z } from "npm:zod@4.4.3";

export const Globals = z.object({
  gatewayUrl: z.string().url().default("https://gateway.orbit"),
  caFile: z.string().min(1).default(
    "/usr/local/share/ca-certificates/orbit-root.crt",
  ),
});
export type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  readResource(name: string): Promise<Record<string, unknown> | null>;
  writeResource(
    spec: string,
    name: string,
    value: Record<string, unknown>,
  ): Promise<{ name: string }>;
};

export const Receipt = z.strictObject({
  status: z.enum(["succeeded", "skipped", "disabled"]),
  finalized: z.number().int().nonnegative().optional(),
  requeued: z.number().int().nonnegative().optional(),
  pruned: z.number().int().nonnegative().optional(),
});
export const Args = z.object({ runId: z.string().uuid() });
