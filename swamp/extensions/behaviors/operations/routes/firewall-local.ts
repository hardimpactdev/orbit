import { z } from "npm:zod@4.4.3";
import { runLocalProgram } from "./local-program.ts";
import { FirewallProgram } from "./firewall-program.ts";
import { FirewallRules } from "./firewall-schema.ts";
export { FirewallRules } from "./firewall-schema.ts";
const Id = z.number().int().positive();
export const RouteLan = z.discriminatedUnion("operation", [
  z.object({
    operation: z.literal("converge"),
    route_id: Id,
    router: z.ipv4(),
    workload: z.ipv4(),
  }).strict(),
  z.object({ operation: z.literal("remove"), route_id: Id }).strict(),
  z.object({
    operation: z.literal("retire"),
    route_id: Id,
    keep: z.array(z.object({ router: z.ipv4(), workload: z.ipv4() }).strict())
      .max(256),
  }).strict(),
]);
const result = (raw: unknown) =>
  z.union([
    z.object({ matches: z.literal(true) }).strict(),
    z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
  ]).parse(raw);
export async function convergeRouteLan(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  const input = RouteLan.parse(raw);
  return result(
    await invoke(FirewallProgram, { mode: "route", ...input }, signal),
  );
}
export async function removeRouteLan(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  const input = RouteLan.options[1].parse(raw);
  return result(
    await invoke(FirewallProgram, { mode: "route", ...input }, signal),
  );
}
export async function convergeFirewall(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  return result(
    await invoke(FirewallProgram, {
      mode: "rules",
      ...FirewallRules.parse(raw),
    }, signal),
  );
}
