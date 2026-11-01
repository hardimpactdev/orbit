import { z } from "npm:zod@4.4.3";
import { Intent, Result } from "./direct-contract.ts";
import { localTool } from "./local.ts";
import { refusalCode, safeVersion, ToolRefusal } from "./contract.ts";

type Context = { signal?: AbortSignal; writeResource(spec: string, name: string, value: unknown): Promise<unknown>; readResource(name: string): Promise<unknown> };
/** Retained Node-parent manager prerequisite. Standalone Tools use the official SSH DAG. */
export function toolNode(invoke = localTool) {
  return {
    type: "@nckrtl/tool-node", version: "2026.10.30.1",
    resources: {
      before: { schema: z.object({ operation_id: z.number(), run_id: z.string(), installed_version: z.string().nullable() }), lifetime: "workflow", garbageCollection: 10 },
      outcome: { schema: z.object({ outcome: Result }), lifetime: "workflow", garbageCollection: 10 },
    },
    methods: { apply: {
      description: "Prepare only a retained Node parent's admitted manager",
      arguments: z.object({ intent: Intent }),
      execute: async ({ intent }: { intent: z.infer<typeof Intent> }, ctx: Context) => {
        const input = intent.input;
        if (intent.kind !== "tool.manager_converge" || input.node_id !== intent.node_id || !["apt", "composer", "vp"].includes(input.manager)) throw new Error("Invalid parent manager action");
        ctx.signal?.throwIfAborted();
        let manager_version: string | null = null;
        const save = async (outcome: z.infer<typeof Result>) => ({ outcome, dataHandles: [await ctx.writeResource("outcome", "outcome-" + intent.run_id, { outcome })] });
        const call = async (name: string, fields: Record<string, unknown>, step: string) => {
          ctx.signal?.throwIfAborted();
          const value = await invoke(name, fields, input, ctx.signal);
          if (typeof value.error_code === "string") throw new ToolRefusal(refusalCode(step, value.error_code, intent.kind), { error_code: value.error_code });
          return value;
        };
        try {
          if (input.materialize !== null) {
            await call("tool.materialize-" + input.manager, input.materialize, "manager-materialize");
          }
          for (const step of ["manager-ready", "tool-verified"]) {
            const version = (await call("tool." + input.manager + "-version", {}, step)).version;
            if (!safeVersion(version)) throw new ToolRefusal("tool.version_probe_failed");
            manager_version = version;
          }
          return await save({ status: "succeeded", result: { manager_version }, error_code: null });
        } catch (error) {
          if (!(error instanceof ToolRefusal)) throw error;
          return await save({ status: error.code === "operation.cancelled" ? "cancelled" : "failed", result: { manager_version, native_error_code: String(error.result.error_code ?? error.code), error_code: error.code }, error_code: error.code });
        }
      },
    } },
  };
}
