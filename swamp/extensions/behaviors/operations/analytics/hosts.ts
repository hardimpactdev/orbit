import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import { certificateActions } from "../certificates/actions.ts";
import { removeLan } from "../routes/lan.ts";
import { Probe, verifyLocal } from "../routes/verification.ts";
import { Args, Leaf, Request, Signed } from "./lifecycle.ts";
import { analyticsRouteActions } from "./route-actions.ts";

const Participant = Args.extend({
  target_node_id: z.number().int().positive(),
});
const LocalArgs = Participant.extend({ leaf: Leaf });
type Intent = z.infer<typeof Participant>;
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
type Actions = ReturnType<typeof certificateActions>;
export async function readParticipant(
  args: Intent,
  gateway: Pick<GatewayOperation, "read">,
) {
  const current = z.object({
    id: z.number(),
    kind: z.string(),
    node_id: z.number(),
    status: z.string(),
    cancel_requested: z.boolean(),
    input: z.object({
      participant_node_id: z.number(),
      certificates: z.array(Leaf),
      cleanup: z.array(Leaf),
      public_verification: z.array(Probe).optional(),
    }),
  }).parse(await gateway.read());
  if (
    current.id !== args.operation_id || current.kind !== args.kind ||
    current.node_id !== args.node_id || current.status !== "running" ||
    current.cancel_requested ||
    current.input.participant_node_id !== args.target_node_id
  ) throw new Error("analytics.participant_mismatch");
  return current.input;
}
async function admitted(
  args: z.infer<typeof LocalArgs>,
  gateway: Pick<GatewayOperation, "read">,
  field: "certificates" | "cleanup",
) {
  const view = await readParticipant(args, gateway);
  const wanted = Leaf.parse(args.leaf);
  if (
    wanted.node_id !== args.target_node_id ||
    !view[field].some((leaf) => JSON.stringify(leaf) === JSON.stringify(wanted))
  ) throw new Error("analytics.certificate_mismatch");
  return wanted;
}
function successful<T extends Record<string, unknown>>(result: T): T {
  if (result.error_code !== undefined) {
    throw new Error(
      z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(result.error_code),
    );
  }
  return result;
}
export async function publishLeaf(
  args: z.infer<typeof LocalArgs> & {
    signed: z.infer<typeof Signed>[];
    requests: z.infer<typeof Request>[];
  },
  gateway: Pick<GatewayOperation, "read">,
  actions: Actions = certificateActions(),
  signal?: AbortSignal,
) {
  const leaf = await admitted(args, gateway, "certificates");
  return await analyticsRouteActions(actions).publish(
    leaf,
    args.requests,
    args.signed,
    signal,
  );
}
export async function removeLeaf(
  args: z.infer<typeof LocalArgs>,
  gateway: Pick<GatewayOperation, "read">,
  actions: Actions = certificateActions(),
  signal?: AbortSignal,
  lan = removeLan,
) {
  const leaf = await admitted(args, gateway, "cleanup");
  return await analyticsRouteActions(actions, lan).hostWithdraw(leaf, signal);
}
export async function verifyPublic(
  args: Intent,
  gateway: Pick<GatewayOperation, "read">,
  verify = verifyLocal,
) {
  const view = await readParticipant(args, gateway);
  if (!view.public_verification?.length) {
    throw new Error("analytics.verification_missing");
  }
  for (const probe of view.public_verification) {
    z.object({ ready: z.literal(true) }).parse(successful(await verify(probe)));
  }
  return { ready: true };
}
export function analyticsCertificates(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  actions: Actions = certificateActions(),
  verify = verifyLocal,
  lan = removeLan,
) {
  const gateway = (args: Intent, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  return {
    type: "@nckrtl/analytics-certificate",
    version: "2026.10.31.3",
    globalArguments: Globals,
    resources: {},
    methods: {
      publish: {
        description:
          "Publish and inspect the admitted Router leaf through canonical certificate actions",
        arguments: LocalArgs.extend({
          signed: z.array(Signed).max(1),
          requests: z.array(Request),
        }),
        execute: (
          args: z.infer<typeof LocalArgs> & {
            signed: z.infer<typeof Signed>[];
            requests: z.infer<typeof Request>[];
          },
          ctx: Context,
        ) => publishLeaf(args, gateway(args, ctx), actions, ctx.signal),
      },
      remove: {
        description:
          "Remove and inspect an unused Router leaf after host withdrawal",
        arguments: LocalArgs,
        execute: (args: z.infer<typeof LocalArgs>, ctx: Context) =>
          removeLeaf(args, gateway(args, ctx), actions, ctx.signal, lan),
      },
      verifyPublic: {
        description:
          "Verify each admitted Router TLS hop from its placed Ingress worker",
        arguments: Participant,
        execute: (args: Intent, ctx: Context) =>
          verifyPublic(args, gateway(args, ctx), verify),
      },
    },
  };
}
export const behavior = analyticsCertificates();
