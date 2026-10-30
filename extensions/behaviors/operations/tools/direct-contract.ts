import { z } from "npm:zod@4.4.3";
import { Input } from "./contract.ts";

export const Kind = z.enum([
  "tool.install",
  "tool.update",
  "tool.remove",
  "tool.adopt",
  "tool.manager_converge",
]);
export const Identity = z.object({
  operation_id: z.number().int().positive(),
  node_id: z.number().int().positive(),
  kind: Kind,
  run_id: z.string().uuid(),
});
export const ToolIntentInput = Input.extend({
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
export const Result = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.object({
    manager_version: z.string().max(255).nullable().optional(),
    installed_version: z.string().max(255).nullable().optional(),
    block: z.string().max(100).nullable().optional(),
    outcome: z.enum(["applied", "unchanged", "blocked_by_constraint"])
      .optional(),
    native_error_code: z.string().max(100).regex(/^[a-z0-9_.-]+$/).optional(),
    error_code: z.string().max(100).regex(/^[a-z0-9_.-]+$/).optional(),
  }),
  error_code: z.string().max(100).regex(/^[a-z0-9_.-]+$/).nullable(),
});
export const Intent = Identity.extend({ input: ToolIntentInput });
