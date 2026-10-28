import { z } from "npm:zod@4.4.3";

export const Receipt = z.strictObject({
  status: z.enum(["succeeded", "skipped", "disabled"]),
  finalized: z.number().int().nonnegative().optional(),
  requeued: z.number().int().nonnegative().optional(),
  pruned: z.number().int().nonnegative().optional(),
});
export const ReadReceipt = z.strictObject({
  status: z.enum(["observed", "recorded", "removed", "disabled"]),
});
export const Args = z.object({ runId: z.string().uuid() });
