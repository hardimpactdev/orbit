import { z } from "npm:zod@4.4.3";
import { runLocalProgram } from "../routes/local-program.ts";
import { FirewallProgram } from "../routes/firewall-program.ts";

export const Rule = z.object({
  node_id: z.number().int().positive(),
  name: z.string().regex(/^[a-z0-9](?:[a-z0-9._-]{0,61}[a-z0-9])?$/),
  action: z.enum(["allow", "deny"]),
  source: z.string(),
  protocol: z.enum(["tcp", "udp"]),
  port: z.string(),
});
const Input = Rule.extend({ operation: z.enum(["converge", "remove"]) })
  .strict();
export async function applyNamedRule(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  return await invoke(
    FirewallProgram,
    { mode: "named", ...Input.parse(raw) },
    signal,
  );
}
/** Run one fixed local UFW transaction under the same host lock as Route and role writers. */
export const localRule = (
  input: Record<string, unknown>,
  signal?: AbortSignal,
) => applyNamedRule(input, runLocalProgram, signal);
