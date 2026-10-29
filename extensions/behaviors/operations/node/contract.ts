import { z } from "npm:zod@4.4.3";

const phpObject = (v: unknown) => Array.isArray(v) && v.length === 0 ? {} : v;
export const ResultObject = z.preprocess(
  phpObject,
  z.record(z.string(), z.unknown()),
);

export const ErrorCode = z.string().regex(/^[a-z0-9_.-]+$/);
export const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: ResultObject,
  error_code: ErrorCode.nullable(),
});

export const ConvergeInput = z.object({
  force_artifacts: z.boolean(),
  first_visit: z.boolean(),
});
const ChildSpec = z.discriminatedUnion("kind", [
  z.object({
    kind: z.literal("route.caddy_converge"),
    node_id: z.number().int().positive(),
    input: z.object({ reason: z.string() }).strict(),
  }),
  z.object({
    kind: z.literal("route.private_dns_publish"),
    node_id: z.number().int().positive(),
    input: z.object({ reason: z.string() }).strict(),
  }),
  z.object({
    kind: z.literal("route.offline_residue_clean"),
    node_id: z.number().int().positive(),
    input: z.object({ removed_node_id: z.number().int().positive().nullable() })
      .strict(),
  }),
  z.object({
    kind: z.literal("ai_router.collector_converge"),
    node_id: z.number().int().positive(),
    input: z.preprocess(
      (v) => Array.isArray(v) && v.length === 0 ? {} : v,
      z.object({}).strict(),
    ),
  }),
]);
export const FootprintSnapshot = z.object({
  agent_pin: z.string().regex(/^[0-9a-f]{64}$/),
  artifacts: z.array(
    z.object({
      name: z.string(),
      digest: z.string(),
      required: z.boolean(),
      repair: z.boolean(),
    }),
  ).max(32),
  payloads: z.preprocess(phpObject, z.record(z.string(), ResultObject)),
  children: z.preprocess(phpObject, z.record(z.string(), ChildSpec)).default(
    {},
  ),
});
