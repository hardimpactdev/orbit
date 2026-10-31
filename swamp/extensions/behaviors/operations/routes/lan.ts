import { z } from "npm:zod@4.4.3";
import { removeRouteLan } from "./firewall-local.ts";
import { runLocalProgram } from "./local-program.ts";
export const LanRemoval = z.object({ route_id: z.number().int().positive() })
  .strict();
/** Remove only this Route's exact comment through the fixed local UFW program. */
export async function removeLan(
  raw: unknown,
  invoke = runLocalProgram,
  signal?: AbortSignal,
) {
  const input = LanRemoval.parse(raw);
  const result = await removeRouteLan(
    { operation: "remove", route_id: input.route_id },
    invoke,
    signal,
  );
  return "error_code" in result ? result : { ready: true };
}
