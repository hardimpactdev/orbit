import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../../operation-runner/mod.ts";
import { GatewayOperation } from "../_lib/direct.ts";
import { certificateActions } from "../certificates/actions.ts";
import { Leaf as CertificateLeaf, Scope } from "../certificates/input.ts";
import { removeLan } from "../routes/lan.ts";
import { Probe, verifyLocal } from "../routes/verification.ts";
import { Args, Leaf, Request, Signed } from "./lifecycle.ts";

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
function scope(leaf: z.infer<typeof Leaf>) {
  return Scope.parse({
    node_id: leaf.node_id,
    home: leaf.home,
    scope: leaf.scope,
    agent_sha256: leaf.agent_sha256,
  });
}
function certificate(leaf: z.infer<typeof Leaf>) {
  return CertificateLeaf.parse({
    ...scope(leaf),
    domain: leaf.domain,
    version: leaf.version,
    root_hash: leaf.root_hash,
    user: leaf.user,
  });
}
export async function requestLeaf(
  args: z.infer<typeof LocalArgs>,
  gateway: Pick<GatewayOperation, "read">,
  actions: Actions = certificateActions(),
  signal?: AbortSignal,
) {
  const leaf = await admitted(args, gateway, "certificates");
  const request = successful(await actions.request(certificate(leaf), signal));
  return Request.parse({
    node_id: leaf.node_id,
    scope: leaf.scope,
    ...request,
  });
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
  if (args.signed.length > 1) throw new Error("analytics.certificate_mismatch");
  const signed = args.signed.length ? Signed.parse(args.signed[0]) : undefined;
  if (
    signed && (signed.node_id !== leaf.node_id || signed.scope !== leaf.scope)
  ) {
    throw new Error("analytics.certificate_mismatch");
  }
  const requested = args.requests.map((request) => Request.parse(request))
    .filter((request) =>
      request.node_id === leaf.node_id && request.scope === leaf.scope
    );
  if (requested.length !== 1) throw new Error("analytics.certificate_mismatch");
  const { node_id: _node, scope: _scope, ...request } = requested[0];
  if ((request.state === "request") !== Boolean(signed)) {
    throw new Error("analytics.certificate_mismatch");
  }
  const applied = successful(
    await actions.publish(
      certificate(leaf),
      request,
      signed
        ? {
          certificate_base64: signed.certificate_base64,
          root_certificate_base64: signed.root_certificate_base64,
        }
        : { skipped: true },
      signal,
    ),
  );
  z.object({ present: z.literal(true) }).parse(
    successful(await actions.inspect(scope(leaf), applied, signal)),
  );
  return { ready: true };
}
export async function removeLeaf(
  args: z.infer<typeof LocalArgs>,
  gateway: Pick<GatewayOperation, "read">,
  actions: Actions = certificateActions(),
  signal?: AbortSignal,
  lan = removeLan,
) {
  const leaf = await admitted(args, gateway, "cleanup");
  if (leaf.in_use !== false) throw new Error("analytics.certificate_in_use");
  const removed = successful(
    await actions.remove({ ...scope(leaf), in_use: false }, signal),
  );
  z.object({ present: z.literal(false) }).parse(
    successful(await actions.inspect(scope(leaf), removed, signal)),
  );
  successful(
    await lan({ route_id: leaf.route_id }, undefined, signal),
  );
  return { ready: true };
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
    version: "2026.10.31.2",
    globalArguments: Globals,
    resources: {
      request: { schema: Request, lifetime: "7d", garbageCollection: 10 },
    },
    methods: {
      request: {
        description:
          "Request the admitted Router leaf through the canonical local certificate action",
        arguments: LocalArgs,
        execute: async (args: z.infer<typeof LocalArgs>, ctx: Context) => ({
          dataHandles: [
            await ctx.writeResource(
              "request",
              `${args.run_id}-${args.leaf.scope}`,
              await requestLeaf(args, gateway(args, ctx), actions, ctx.signal),
            ),
          ],
        }),
      },
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
