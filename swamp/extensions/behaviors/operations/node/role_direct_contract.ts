import { RoleCertificates } from "./role_certificates.ts";
import { ProxyNodeIds, RoleMetricsPlan } from "./role_metrics.ts";
import { RolePublication } from "./role_publication.ts";
import { ParentManagerInput } from "./manager_parent.ts";
import { RoleRuntime } from "./role_runtime_local.ts";
import { z } from "npm:zod@4.4.3";
import { CliConvergePlan as CliPlan } from "../fleet/cli_converge_local.ts";
import { DirectArgs } from "../_lib/direct-input.ts";
import { Snapshot as Footprint } from "./converge_direct_contract.ts";

export const Role = z.enum([
  "gateway",
  "vpn",
  "router",
  "ingress",
  "app-dev",
  "app-prod",
  "metrics",
  "database",
  "websocket",
  "analytics",
  "ci-runner",
  "swamp",
]);
export const AccountName = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
export const Path = z.string().regex(/^(\/[A-Za-z0-9._-]+)+$/).refine((p) =>
  p.split("/").every((part) => part !== "." && part !== "..")
);
const Rule = z.object({
  action: z.enum(["allow", "deny", "reject", "limit"]),
  source: z.string().max(256),
  destination: z.string().max(256),
  port: z.string().max(64),
  protocol: z.string().max(16),
  comment: z.string().max(256),
  direction: z.string().max(16),
  in_interface: z.string().max(64).nullable(),
  out_interface: z.string().max(64).nullable(),
  family: z.string().max(16).nullable(),
}).strict();
export const Firewall = z.object({
  desired: z.array(
    z.object({ shape: Rule, replace_port: z.boolean() }).strict(),
  ).max(128),
  remove: z.array(Rule).max(128),
  enable: z.boolean(),
}).strict();
export const Prerequisites = z.object({
  packages: z.array(z.string().regex(/^[a-z0-9][a-z0-9+.-]{0,127}$/)).max(64),
  releases: z.array(z.string().max(64)).min(1).max(4),
  apps_directory: z.boolean(),
  composer_home: z.boolean(),
}).strict();
export const RoleSnapshot = z.object({
  proxy_node_ids: ProxyNodeIds.optional(),
  source_removal: z.record(z.string(), z.unknown()).optional(),
  withdrawal_grace_seconds: z.union([z.literal(0), z.literal(31)]).optional(),
  publication: RolePublication,
  certificates: RoleCertificates.optional(),
  metrics_fleet: RoleMetricsPlan.nullable().optional(),
  gateway_dns: z.object({
    operation: z.literal("converge"),
    address: z.string(),
    domain: z.string(),
  }).strict().nullable().optional(),
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  assignment_id: z.number().int().positive(),
  role: Role,
  created: z.boolean(),
  user: AccountName,
  prerequisites: Prerequisites,
  firewall: Firewall.nullable(),
  footprint: Footprint,
  cli: CliPlan,
  runtime: RoleRuntime.nullable().optional(),
  javascript: z.record(z.string(), z.unknown()).nullable().optional(),
  storage_root: Path.nullable().optional(),
  router_lan: z.record(z.string(), z.unknown()).nullable().optional(),
  managers: z.array(ParentManagerInput.shape.manager).optional(),
}).strict().superRefine((s, ctx) => {
  if (s.cli.user !== s.user) {
    ctx.addIssue({
      code: "custom",
      message: "CLI account differs from the admitted role",
    });
  }
  if (
    s.footprint.node_id !== s.node_id ||
    s.footprint.agent_sha256 !== s.agent_sha256
  ) {
    ctx.addIssue({
      code: "custom",
      message: "Footprint differs from the admitted role Node",
    });
  }
});
export const RoleArgs = DirectArgs.extend({
  kind: z.enum(["node.role_add", "node.role_converge", "node.role_relocate"]),
  node_id: z.number().int().positive(),
});
export type RoleSnapshot = z.infer<typeof RoleSnapshot>;
