import { z } from "npm:zod@4.4.3";

export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: ErrorCode.nullable(),
});
