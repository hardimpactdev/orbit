import { z } from "npm:zod@4";
import { TargetsSchema } from "./maintenance/batch.ts";

export const PackageTargetSchema = z.strictObject({
  workItem: z.string().regex(/^tg-[1-9]\d*$/),
  targets: TargetsSchema,
  sourceCommit: z.string().regex(/^[a-f0-9]{40}$/).optional(),
});
export type PackageTarget = z.infer<typeof PackageTargetSchema>;
