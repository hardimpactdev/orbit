import { z } from "npm:zod@4.4.3";

export const Kinds = [
  "database_server.create",
  "database_server.destroy",
  "database.create",
  "database.destroy",
  "database.write",
  "database.clone",
  "database.destroy_owned",
  "database.sqlite_export",
  "database.sqlite_seed",
  "database.sqlite_seed_abandon",
] as const;
export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const Result = z.object({
  backend: z.enum(["active", "absent"]).optional(),
  error_code: ErrorCode.optional(),
  row_count: z.number().int().nonnegative().optional(),
  truncated: z.boolean().optional(),
  rows: z.array(z.string()).max(500).optional(),
  bytes: z.number().int().nonnegative().optional(),
  sha256: z.string().optional(),
  mode: z.number().int().nonnegative().optional(),
  incoming: z.string().optional(),
  state: z.string().optional(),
}).strict();
export const Descriptor = z.object({
  key: z.string().min(1),
  step: z.string().min(1),
  transport: z.enum([
    "agent",
    "remote_program",
    "gateway",
    "database_sqlite",
    "database_removal",
  ]),
  name: z.string(),
  input: z.record(z.string(), z.unknown()),
  resumable: z.boolean(),
  required: z.boolean(),
  cleanup: z.boolean().default(false),
});
export const Child = z.object({
  key: z.string().min(1),
  kind: z.string(),
  node_id: z.number().int().positive(),
  input: z.record(z.string(), z.unknown()),
  phase: z.enum(["before", "after"]),
});
export const Plan = z.object({
  descriptors: z.array(Descriptor).nonempty(),
  children: z.array(Child).optional(),
}).transform((plan) => ({
  descriptors: plan.descriptors,
  children: plan.children ??
    z.array(Child).parse(
      plan.descriptors.find((entry) => entry.name === "database.proven")?.input
        .children ?? [],
    ),
}));
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: Result,
  error_code: ErrorCode.nullable(),
});
