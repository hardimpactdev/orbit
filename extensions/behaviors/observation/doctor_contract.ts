import { z } from "npm:zod@4.4.3";
import type { NodeCall } from "../operations/_lib/node-agent.ts";

export const Families = [
  "node",
  "role",
  "project",
  "instance",
  "schedule",
  "tool",
  "process",
  "firewall",
  "database_connection",
  "route",
  "database_server",
] as const;
export type Family = typeof Families[number];
export type Row = Record<string, unknown>;
export type NodeIntent = Row & {
  id: number;
  name: string;
  platform: string;
  architecture: string;
  user: string;
  managed: boolean;
  parked: boolean;
  roles: Row[];
  resources: Record<string, Row[]>;
  agent_sha256: string;
};
export type Issue = {
  code: string;
  kind: "drift" | "unverifiable" | "informational";
  resource_type: Family;
  resource_id: number | string | null;
  resource_name: string | null;
  summary: string;
  expected: boolean | string | null;
  observed: boolean | string | null;
};
export type FamilyReport = {
  family: Family;
  status: "healthy" | "drift" | "unverifiable";
  checked: number;
  issues: Issue[];
  observed_resources: (number | string)[];
  observed_checks: Record<string, string[]>;
  observed_inventories: Record<string, (number | string)[]>;
};
export interface ProbeContext {
  node: NodeIntent;
  nodes: NodeIntent[];
  observation: Row;
  inspectionFailed: boolean;
  run: (name: string, input: Row, sudo?: boolean) => Promise<Row>;
  local: (argv: string[]) => Promise<string>;
  privateMaterial: Row;
  signal?: AbortSignal;
}
export type AgentInvoke = (
  call: NodeCall,
  signal?: AbortSignal,
) => Promise<Row>;
export function issue(
  family: Family,
  code: string,
  row: Row | number | string | null,
  summary: string,
  expected: boolean | string | null = "matching",
  observed: boolean | string | null = "mismatch",
  kind: Issue["kind"] = "drift",
): Issue {
  const id = typeof row === "object" && row !== null ? row.id : row;
  return {
    code,
    kind,
    resource_type: family,
    resource_id: typeof id === "number" || typeof id === "string" ? id : null,
    resource_name:
      typeof row === "object" && row !== null && typeof row.name === "string"
        ? row.name
        : null,
    summary,
    expected,
    observed,
  };
}
export function report(
  family: Family,
  checked: number,
  issues: Issue[],
  observed_resources: (number | string)[] = [],
  observed_checks: Record<string, string[]> = {},
  observed_inventories: Record<string, (number | string)[]> = {},
): FamilyReport {
  return {
    family,
    checked,
    issues,
    observed_resources,
    observed_checks,
    observed_inventories,
    status: issues.some((i) => i.kind === "unverifiable")
      ? "unverifiable"
      : issues.some((i) => i.kind === "drift")
      ? "drift"
      : "healthy",
  };
}
export function rows(ctx: ProbeContext, key: string): Row[] {
  return ctx.node.resources[key] ?? [];
}
export function nodeCheck(node: NodeIntent, suffix = "") {
  return `node:${node.id}${suffix ? ":" + suffix : ""}`;
}
export function unreachable(
  family: Family,
  ctx: ProbeContext,
  count: number,
): FamilyReport {
  return report(
    family,
    count,
    count
      ? [
        issue(
          family,
          `${family}.node_unreachable`,
          nodeCheck(ctx.node),
          "The Node cannot be inspected.",
          "reachable",
          "unreachable",
          "unverifiable",
        ),
      ]
      : [],
  );
}
export const NodeInput = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  platform: z.string(),
  architecture: z.string(),
  user: z.string(),
  managed: z.boolean(),
  parked: z.boolean(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  roles: z.array(z.record(z.string(), z.unknown())),
  resources: z.record(z.string(), z.array(z.record(z.string(), z.unknown()))),
}).passthrough();
