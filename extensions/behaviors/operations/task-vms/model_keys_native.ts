import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import { inspect } from "../../node-worker/canary.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
  issueTaskVmModelKey,
  ModelKeyInput,
  revokeTaskVmModelKey,
} from "./model_keys.ts";

const IssueKinds = [
  "task_vm.enroll",
  "task_vm.prepare_runtime",
  "task_vm.provision",
  "tasks.provision",
] as const;
const Args = DirectArgs.extend({
  kind: z.enum([...IssueKinds, "task_vm.destroy"]),
  node_id: z.number().int().positive(),
});
const Binding = z.object({
  task_vm_id: z.number().int().positive(),
  model_router_node_id: z.number().int().positive(),
  gateway_node_id: z.number().int().positive(),
  model_origin: ModelKeyInput.shape.origin,
});
const Result = z.union([
  z.object({ task_vm_id: z.number().int().positive(), issued: z.literal(true) })
    .strict(),
  z.object({
    task_vm_id: z.number().int().positive(),
    revoked: z.literal(true),
  }).strict(),
]);
type Context = {
  globalArgs: z.infer<typeof Globals>;
  definition: { name: string };
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<{ name: string }>;
};
const failed = () => new Error("task_vm.model_key_failed");

/** One stable instance per admitted router. Swamp owns the method lock before dispatch. */
export function taskVmModelKeys(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile, ctx.signal),
  identity: typeof inspect = inspect,
  request: typeof fetch = fetch,
) {
  const method = (action: "issue" | "revoke") => ({
    description: action === "issue"
      ? "Issue this parent's VM model key under the router's native lock"
      : "Revoke this parent's VM model key under the same native router lock",
    arguments: Args,
    async execute(raw: z.infer<typeof Args>, ctx: Context) {
      try {
        const args = Args.parse(raw);
        if ((action === "revoke") !== (args.kind === "task_vm.destroy")) {
          throw failed();
        }
        const api = new GatewayOperation(makeHttp(ctx), args);
        const read = async () => {
          ctx.signal?.throwIfAborted();
          const current = z.object({
            id: z.literal(args.operation_id),
            kind: z.literal(args.kind),
            node_id: z.literal(args.node_id),
            status: z.enum(["queued", "running"]),
            cancel_requested: z.literal(false),
            input: Binding,
          }).parse(await api.read(ctx.signal));
          return current.input;
        };
        const binding = await read();
        if (
          ctx.definition.name !==
            `task-vm-model-router-${binding.model_router_node_id}`
        ) throw failed();
        await identity({ node_id: binding.gateway_node_id, user: "orbit" });
        const guard = async () => {
          if (JSON.stringify(await read()) !== JSON.stringify(binding)) {
            throw failed();
          }
        };
        const result = action === "issue"
          ? await issueTaskVmModelKey(
            { origin: binding.model_origin },
            guard,
            api,
            ctx.signal,
            request,
          )
          : await revokeTaskVmModelKey(
            { origin: binding.model_origin },
            guard,
            api,
            ctx.signal,
            request,
          );
        const handle = await ctx.writeResource(
          "result",
          action + "-" + args.run_id,
          Result.parse({ task_vm_id: binding.task_vm_id, ...result }),
        );
        return { dataHandles: [handle] };
      } catch {
        throw failed();
      }
    },
  });
  return {
    type: "@nckrtl/task-vm-model-keys",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      result: {
        description: "Public proof for one parent step; no credentials",
        schema: Result,
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: { issue: method("issue"), revoke: method("revoke") },
  };
}

export const behavior = taskVmModelKeys();
