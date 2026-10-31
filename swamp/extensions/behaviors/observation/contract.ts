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
};

export const Args = z.object({ runId: z.string().uuid() });
