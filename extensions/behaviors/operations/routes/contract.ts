import { z } from "npm:zod@4.4.3";

export const Descriptor = z.object({
  key: z.string().min(1),
  step: z.string().min(1),
  transport: z.string().min(1),
  name: z.string().min(1),
  input: z.record(z.string(), z.unknown()),
  resumable: z.boolean(),
  required: z.boolean(),
});
export const ExecutionContext = z.object({
  descriptors: z.array(Descriptor),
  result: z.preprocess(
    (value) => Array.isArray(value) && value.length === 0 ? {} : value,
    z.record(z.string(), z.unknown()),
  ).optional(),
});
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable(),
});

export const EffectReceipt = z.object({
  effect_key: z.string(),
  state: z.enum(["succeeded", "failed", "running", "unknown"]),
  exit_code: z.number().int().nullable(),
  result: z.record(z.string(), z.unknown()).nullable(),
  finished_at: z.string().nullable(),
});
export const Invocation = Descriptor.pick({
  key: true,
  transport: true,
  name: true,
  input: true,
});

export class RouteRefusal extends Error {
  constructor(readonly code: string, readonly stage?: "render" | "validate") {
    super(code);
  }
}
