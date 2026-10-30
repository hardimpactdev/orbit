import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  type Context,
  Executor,
  Globals,
  Operation,
} from "../../operation-runner/mod.ts";
import { childExecutor, WaitingForChildren } from "./children.ts";
import { ErrorCode } from "./contract.ts";
import { lifecycle } from "./lifecycle.ts";
import { LifecycleSession } from "./session.ts";

const Kinds = z.enum([
  "instance.rename",
  "instance.destroy",
  "instance.create",
  "instance.register",
  "instance.clone",
  "instance.transfer",
  "instance.transfer_rollback",
  "instance.transfer_discard",
]);
const Assignment = z.object({ operation_id: z.number().int().positive() });

const Completion = z.object({
  status: z.enum(["succeeded", "failed", "cancelled"]),
  result: z.record(z.string(), z.unknown()),
  error_code: ErrorCode.nullable(),
});

function waitForChild(signal?: AbortSignal): Promise<void> {
  signal?.throwIfAborted();
  return new Promise((resolve, reject) => {
    const abort = () => {
      clearTimeout(timer);
      reject(signal?.reason ?? new Error("Lifecycle wait interrupted"));
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", abort);
      resolve();
    }, 5000);
    signal?.addEventListener("abort", abort, { once: true });
  });
}

export function instanceLifecycle(
  makeHttp = (ctx: Context) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      ctx.signal,
      600_000,
    ),
) {
  return {
    type: "@nckrtl/operation-instance-lifecycle",
    version: "2026.10.28.1",
    globalArguments: Globals,
    resources: {
      assignment: {
        description:
          "Bind a dispatch retry to its claimed Operation before effects",
        schema: Assignment,
        lifetime: "7d",
        garbageCollection: 1,
      },
      waiting: {
        description: "Recorded child keys while the parent worker is released",
        schema: z.object({ keys: z.array(z.string()).nonempty() }),
        lifetime: "infinite",
        garbageCollection: 10,
      },
      receipt: {
        description: "Permanent lifecycle completion intent",
        schema: Completion,
        lifetime: "infinite",
        garbageCollection: 10,
      },
    },
    methods: {
      execute: {
        description:
          "Execute the admitted Instance lifecycle through proven keyed effects",
        arguments: z.object({
          kind: Kinds,
          worker: z.string().min(1),
        }),
        execute: async (
          args: { kind: string; worker: string },
          ctx: Context,
        ) => {
          const http = makeHttp(ctx);
          const executor = new Executor(http);
          const attempt = `assignment-${args.worker}`;
          const assigned = await ctx.readResource(attempt);
          let operation: z.infer<typeof Operation>;
          if (assigned) {
            const id = Assignment.parse(assigned).operation_id;
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
                error_code: ErrorCode.nullable(),
              }).parse(await executor.operation(id));
              if (
                ["succeeded", "failed", "cancelled"].includes(outcome.status)
              ) {
                const canonical = (value: Record<string, unknown>) =>
                  JSON.stringify(
                    Object.entries(value).sort(([a], [b]) =>
                      a.localeCompare(b)
                    ),
                  );
                if (
                  outcome.id !== id || outcome.kind !== args.kind ||
                  outcome.status !== completion.status ||
                  outcome.error_code !== completion.error_code ||
                  canonical(outcome.result ?? {}) !==
                    canonical(completion.result)
                ) {
                  throw new Error("Unproven saved lifecycle completion");
                }
                return {
                  claimed: true,
                  operationId: id,
                  status: completion.status,
                };
              }
            }
            // An old dispatch renews only its assignment; a fresh dispatch owns recovery.
            operation = Operation.parse(
              await executor.post(`${id}/heartbeat`, { worker: args.worker }),
            );
            if (operation.id !== id) {
              throw new Error("Wrong lifecycle assignment");
            }
          } else {
            const claimed = await executor.claim(args.kind, args.worker);
            if (claimed === null) return { claimed: false };
            operation = claimed;
            await ctx.writeResource("assignment", attempt, {
              operation_id: operation.id,
            });
          }
          if (operation.kind !== args.kind) {
            throw new Error("Wrong claimed lifecycle kind");
          }
          const operationId = operation.id;
          const resource = `operation-${operationId}`;
          const saved = await ctx.readResource(resource);
          if (saved !== null) {
            let completion = Completion.parse(saved);
            if (
              operation.kind === "instance.create" &&
              operation.cancel_requested && completion.status === "failed"
            ) {
              completion = {
                status: "cancelled",
                result: {},
                error_code: "operation.cancelled",
              };
              await ctx.writeResource("receipt", resource, completion);
            }
            await executor.post(`${operationId}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return { claimed: true, operationId, status: completion.status };
          }
          const session = new LifecycleSession(
            http,
            operationId,
            args.worker,
            childExecutor(
              http,
              operationId,
              args.worker,
            ),
            () => waitForChild(ctx.signal),
          );
          await session.refresh();
          let heartbeatError: unknown;
          const heartbeat = setInterval(() => {
            executor.post(`${operationId}/heartbeat`, {
              worker: args.worker,
            }).catch((error) => {
              heartbeatError = error;
            });
          }, 30_000);
          try {
            const completion = Completion.parse(await lifecycle(session));
            if (heartbeatError) throw heartbeatError;
            const handle = await ctx.writeResource(
              "receipt",
              resource,
              completion,
            );
            await executor.post(`${operationId}/complete`, {
              worker: args.worker,
              ...completion,
            });
            return {
              dataHandles: [handle],
              claimed: true,
              operationId,
              status: completion.status,
            };
          } catch (error) {
            if (error instanceof WaitingForChildren) {
              const handle = await ctx.writeResource(
                "waiting",
                `${resource}-waiting`,
                { keys: error.keys },
              );
              return {
                dataHandles: [handle],
                claimed: true,
                operationId,
                status: "waiting",
              };
            }
            throw error;
          } finally {
            clearInterval(heartbeat);
          }
        },
      },
    },
  };
}

export const behavior = {
  ...instanceLifecycle(),
  type: "@nckrtl/operation-instance-lifecycle",
};
