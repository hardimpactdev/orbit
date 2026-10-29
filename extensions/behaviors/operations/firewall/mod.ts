import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";

const Input = z.object({
  name: z.string(),
  node_id: z.number().int().positive(),
  action: z.enum(["allow", "deny"]),
  source: z.string(),
  protocol: z.enum(["tcp", "udp"]),
  port: z.string(),
});
const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: z.string().nullable(),
});
export function firewall(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-firewall",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description:
          "Bind a dispatch retry to its first claimed Operation before effects",
        schema: z.object({ operation_id: z.number().int().positive() }),
        lifetime: "7d",
        garbageCollection: 1,
      },
      receipt: {
        description: "Permanent completion intent for an Operation",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Claim and execute one firewall Operation on the same worker Node",
        arguments: z.object({
          kind: z.enum(["firewall.allow", "firewall.deny", "firewall.remove"]),
          worker: z.string().min(1),
        }),
        execute: async (
          args: {
            kind: string;
            worker: string;
          },
          ctx: Context,
        ) => {
          const executor = new Executor(makeHttp(ctx));
          const attempt = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(attempt);
          let operation: z.infer<typeof Operation>;
          if (assigned) {
            const id =
              z.object({ operation_id: z.number().int().positive() }).parse(
                assigned,
              ).operation_id;
            const saved = await ctx.readResource(`operation-${id}`);
            if (saved) {
              const completion = Completion.parse(saved);
              const outcome = z.object({
                id: z.number().int(),
                kind: z.string(),
                status: z.string(),
                result: z.preprocess(
                  (value) =>
                    Array.isArray(value) && value.length === 0 ? {} : value,
                  z.record(z.string(), z.unknown()).nullable(),
                ),
                error_code: z.string().nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                const scalarResult = (value: Record<string, unknown>) =>
                  JSON.stringify(
                    Object.entries(value).sort(([a], [b]) =>
                      a.localeCompare(b)
                    ),
                  );
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  scalarResult(outcome.result ?? {}) !==
                    scalarResult(completion.result)
                ) {
                  throw new Error("Unproven saved firewall completion");
                }
                return {
                  claimed: true,
                  operationId: id,
                  status: completion.status,
                };
              }
            }
            // A retry never claims another Operation. An expired/lost lease is
            // recovered by normal discovery and a new worker dispatch instead.
            operation = Operation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
            if (operation.id !== id) {
              throw new Error("Wrong firewall assignment");
            }
          } else {
            // Claim and every later write share this invocation's authenticated Node.
            const claimed = await executor.post("claim", {
              kind: args.kind,
              worker: args.worker,
            });
            if (claimed === null) return { claimed: false };
            operation = Operation.parse(claimed);
            await ctx.writeResource("assignment", attempt, {
              operation_id: operation.id,
            });
          }
          if (operation.kind !== args.kind) {
            throw new Error("Wrong claimed firewall kind");
          }
          const path = `${operation.id}`;
          const resource = `operation-${path}`;
          const saved = await ctx.readResource(resource);
          if (saved) {
            const completion = Completion.parse(saved);
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              claimed: true,
              operationId: operation.id,
              status: completion.status,
            };
          }
          const input = Input.parse(operation.input);
          if (
            !["firewall.allow", "firewall.deny", "firewall.remove"].includes(
              operation.kind,
            )
          ) throw new Error("Wrong firewall kind");
          if (input.node_id !== operation.node_id) {
            throw new Error("Wrong firewall Node");
          }
          const remove = operation.kind === "firewall.remove";
          const step = remove ? "ufw-remove" : "ufw-converge";
          let heartbeatError: unknown;
          const timer = setInterval(() => {
            executor.post(`${path}/heartbeat`, { worker: args.worker }).catch(
              (error) => {
                heartbeatError = error;
              },
            );
          }, 30_000);
          try {
            let completion: z.infer<typeof Completion>;
            if (
              operation.cancel_requested && !operation.step_results[step] &&
              !operation.steps.some((saved) => saved.name === step)
            ) {
              completion = {
                status: "cancelled",
                result: {},
                error_code: null,
              };
            } else {
              let result = operation.step_results[step] as
                | Record<string, unknown>
                | undefined;
              if (!result) {
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: `${step}:start`,
                  name: step,
                  status: "running",
                  output: `${
                    remove ? "Remove" : "Converge"
                  } owned UFW rule ${input.name}\n`,
                });
                result = z.record(z.string(), z.unknown()).parse(
                  await executor.post(`${path}/run`, {
                    worker: args.worker,
                    key: step,
                    transport: "agent",
                    name: "firewall.rule",
                    input: {
                      ...input,
                      operation: remove ? "remove" : "converge",
                    },
                  }),
                );
                if (heartbeatError) throw heartbeatError;
                const error = typeof result.error_code === "string"
                  ? result.error_code
                  : result.backend === "inactive"
                  ? "firewall.backend_inactive"
                  : null;
                if (
                  !error && result.backend !== (remove ? "absent" : "active")
                ) throw new Error("Unproven firewall outcome");
                await executor.post(`${path}/step`, {
                  worker: args.worker,
                  key: `${step}:finish`,
                  name: step,
                  status: error ? "failed" : "succeeded",
                  result,
                  error_code: error,
                  output: error
                    ? `${error}\n`
                    : `UFW backend: ${String(result.backend)}\n`,
                });
                completion = {
                  status: error === "operation.cancelled"
                    ? "cancelled"
                    : error
                    ? "failed"
                    : "succeeded",
                  result,
                  error_code: error,
                };
              } else {
                completion = { status: "succeeded", result, error_code: null };
              }
            }
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${path}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              dataHandles: [handle],
              claimed: true,
              operationId: operation.id,
              status: completion.status,
            };
          } finally {
            clearInterval(timer);
          }
        },
      },
    },
  };
}
export const behavior = { ...firewall(), type: "@nckrtl/operation-firewall" };
