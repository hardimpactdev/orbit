import type { z } from "npm:zod@4.4.3";
import type { ToolIntentInput } from "./direct-contract.ts";

/** Wire the shared approved local API here once its closed catalogue covers Tools. */
export function localTool(
  _name: string,
  _input: Record<string, unknown>,
  _node: z.infer<typeof ToolIntentInput>,
  _signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  return Promise.reject(
    new Error("Tools requires the approved local Node privilege boundary"),
  );
}
