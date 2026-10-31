import { z } from "npm:zod@4.4.3";
import { DirectArgs } from "../_lib/direct.ts";
import { NodeNetworkInputV1 } from "./network_direct.ts";

export const Sha = z.string().regex(/^[a-f0-9]{64}$/);
export const Code = z.string().regex(/^[a-z][a-z0-9_]*\.[a-z0-9_.-]{1,90}$/);
export const Name = z.string().regex(/^[a-z][a-z0-9-]{0,63}$/);
export const Stage = z.enum([
  "agent-install",
  "caddy-package",
  "caddy",
  "peer-dns",
  "private-dns",
  "route-residue",
  "php-runtime",
  "tmpfiles",
  "agent",
]);
export const Args = DirectArgs.extend({
  kind: z.literal("node.converge"),
  node_id: z.number().int().positive(),
});
const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
export const Path = z.string().regex(/^(\/[A-Za-z0-9._-]+)+$/)
  .refine((p) => p.split("/").every((part) => part !== "." && part !== ".."));
export const Network = NodeNetworkInputV1;
export const Artifact = z.object({
  name: Name,
  digest: z.string().min(1).max(256),
  required: z.boolean(),
  repair: z.boolean(),
}).strict();
export const Snapshot = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  agent_sha256: Sha,
  force_artifacts: z.boolean(),
  first_visit: z.boolean(),
  artifacts: z.array(Artifact).max(32).refine((a) =>
    new Set(a.map((v) => v.name)).size === a.length
  ),
  payloads: z.record(z.string(), z.unknown()),
  network: Network.nullable(),
  refusal: Code.nullable(),
}).strict().superRefine((v, ctx) => {
  if (
    v.network &&
    (v.network.node_id !== v.node_id ||
      v.network.agent_sha256 !== v.agent_sha256)
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Network binding differs from the admitted Node",
    });
  }
  if (!v.refusal && v.artifacts.some((a) => !Sha.safeParse(a.digest).success)) {
    ctx.addIssue({ code: "custom", message: "Artifact digest is invalid" });
  }
});
export const Entry = z.object({
  name: Name,
  status: z.enum(["applied", "unchanged", "skipped"]),
  reason: z.enum([
    "caddy_render_refused",
    "caddy_validate_refused",
    "route_residue_cleanup_failed",
  ]).nullable().optional(),
}).strict().superRefine((v, ctx) => {
  const allowed = v.name === "caddy"
    ? ["caddy_render_refused", "caddy_validate_refused"]
    : v.name === "route-residue"
    ? ["route_residue_cleanup_failed"]
    : [];
  if (
    v.status === "skipped"
      ? !allowed.includes(v.reason ?? "")
      : v.reason != null
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Artifact skip policy is invalid",
    });
  }
});
export const Outcome = z.object({
  stage: Stage,
  ok: z.boolean(),
  entry: Entry.optional(),
  error_code: Code.optional(),
  failed_artifact: Name.optional(),
  deferred_error: z.literal("node.footprint_caddy_failed").optional(),
  binary_changed: z.boolean().optional(),
}).strict().superRefine((v, ctx) => {
  if (
    v.ok === (v.error_code !== undefined) || (!v.ok && v.entry !== undefined)
  ) {
    ctx.addIssue({ code: "custom", message: "Stage outcome is contradictory" });
  }
});
export const Agent = z.object({
  configuration: z.string().max(65536),
  certificate: z.string().max(65536),
  unit: z.string().max(65536),
  user: Account,
  apps_root: Path.nullable(),
  checkouts: z.array(Path).max(10000),
  url: z.url().nullable(),
}).strict();
export type Snapshot = z.infer<typeof Snapshot>;
export type Outcome = z.infer<typeof Outcome>;
export type Stage = z.infer<typeof Stage>;
