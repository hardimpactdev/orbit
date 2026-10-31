import { z } from "npm:zod@4.4.3";
import { runNodeAgent } from "../_lib/node-agent.ts";

export const LanRemoval = z.object({
  route_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();

/** The pinned local agent removes only this Route's exact firewall comment. */
export async function removeLan(raw: unknown, invoke = runNodeAgent) {
  const input = LanRemoval.parse(raw);
  const result = await invoke({
    name: "firewall.route-lan",
    input: { operation: "remove", route_id: input.route_id },
    pin: input.agent_sha256,
    sudo: true,
    mutates: true,
    deadlineMs: 120000,
    maxOutputBytes: 4096,
  });
  if (result.error_code !== undefined) {
    return {
      error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
        result.error_code,
      ),
    };
  }
  return result.matches === true
    ? { ready: true }
    : { error_code: "node.firewall_convergence_failed" };
}
