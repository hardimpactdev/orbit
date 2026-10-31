import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "../_lib/direct.ts";

const Args = DirectArgs.extend({
  kind: z.enum([
    "websocket.sync",
    "websocket.app_create",
    "websocket.app_destroy",
    "websocket.app_rotate",
  ]),
  node_id: z.number().int().positive(),
});
const Input = z.strictObject({
  registry_operation_id: z.string().uuid(),
  epoch: z.string(),
  revision: z.number().int().nonnegative(),
});
const State = z.object({
  operation: z.object({
    id: z.string().uuid(),
    epoch: z.string(),
    revision: z.number().int().nonnegative(),
    state: z.enum(["pending", "applied", "superseded"]),
  }),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
const delay = (milliseconds: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    if (signal?.aborted) {
      reject(new DirectOperationCancelled({ effectRunning: false }));
      return;
    }
    const aborted = () => {
      clearTimeout(timer);
      reject(new DirectOperationCancelled({ effectRunning: false }));
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener("abort", aborted);
      resolve();
    }, milliseconds);
    signal?.addEventListener("abort", aborted, { once: true });
  });

/** Reverb applies registry intent itself; only its bounded acknowledgement wait moves here. */
export function websocketRegistry(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(
      ctx.globalArgs.gatewayUrl,
      ctx.globalArgs.caFile,
      undefined,
      10000,
    ),
  now = () => performance.now(),
  wait = delay,
) {
  return {
    type: "@nckrtl/websocket-registry",
    version: "2026.10.30.1",
    globalArguments: Globals,
    methods: {
      confirm: {
        description:
          "Confirm a directly admitted registry change on every current Reverb boot",
        arguments: Args,
        execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
          const args = Args.parse(raw);
          const http = makeHttp(ctx);
          const gateway = new GatewayOperation(http, args);
          return await directOperation(
            args,
            gateway,
            async (admitted) => {
              const input = Input.parse(admitted);
              const end = now() + 300000;
              const checkCancellation = async () => {
                if (ctx.signal?.aborted) {
                  throw new DirectOperationCancelled({ effectRunning: false });
                }
                const current = z.object({
                  id: z.number(),
                  kind: z.string(),
                  node_id: z.number(),
                  cancel_requested: z.boolean(),
                }).parse(await gateway.read());
                if (
                  current.id !== args.operation_id ||
                  current.kind !== args.kind || current.node_id !== args.node_id
                ) throw new Error("websocket.operation_mismatch");
                if (current.cancel_requested || ctx.signal?.aborted) {
                  throw new DirectOperationCancelled({ effectRunning: false });
                }
              };
              while (now() < end) {
                await checkCancellation();
                const response = await http(
                  "GET",
                  `/api/v1/websocket-operations/${input.registry_operation_id}`,
                );
                if (response.status !== 200) {
                  throw new Error(
                    "websocket.confirmation_unavailable",
                  );
                }
                const { operation } = State.parse(response.body);
                await checkCancellation();
                if (
                  operation.id !== input.registry_operation_id ||
                  operation.epoch !== input.epoch ||
                  operation.revision !== input.revision
                ) {
                  throw new Error("websocket.registry_mismatch");
                }
                await checkCancellation();
                if (operation.state === "applied") {
                  return {
                    outcome: "succeeded",
                    data: { backend: "applied" },
                  };
                }
                if (operation.state === "superseded") {
                  return {
                    error_code: "websocket.registry_superseded",
                  };
                }
                await wait(Math.min(2000, end - now()), ctx.signal);
              }
              await checkCancellation();
              return { error_code: "websocket.registry_pending" };
            },
            (result) => result,
            ctx.signal,
          );
        },
      },
    },
  };
}

export const behavior = websocketRegistry();
