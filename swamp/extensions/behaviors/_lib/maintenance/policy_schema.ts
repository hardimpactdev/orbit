import { z } from "npm:zod@4";

export const PolicySchema = z.record(
  z.string(),
  z.strictObject({ minimumReleaseAgeDays: z.number().int().nonnegative() }),
);
