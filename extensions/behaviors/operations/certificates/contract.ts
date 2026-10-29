import { z } from "npm:zod@4.4.3";
export const Scope = z.object({
  scope: z.string().min(1),
  home: z.string().startsWith("/"),
});
export const Leaf = Scope.extend({
  domain: z.string().min(1),
  version: z.string().min(1),
  root_hash: z.string().length(64),
  user: z.string().min(1),
});
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: z.string().regex(/^[a-z0-9_.-]+$/).nullable(),
});

export const Invocation = z.object({
  key: z.string(),
  transport: z.string(),
  name: z.string(),
  input: z.record(z.string(), z.unknown()),
});
export const EffectReceipt = z.object({
  effect_key: z.string(),
  state: z.enum(["succeeded", "failed", "running", "unknown"]),
  exit_code: z.number().int().nullable(),
  result: z.record(z.string(), z.unknown()).nullable(),
  finished_at: z.string().nullable(),
});

export class CertificateRefusal extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
