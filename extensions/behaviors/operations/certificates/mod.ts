import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  GatewayOperation,
  Globals as SharedGlobals,
} from "../_lib/direct.ts";
import { certificateActions } from "./actions.ts";
import { type CertificateCaVault, signCertificateBatch } from "./signing.ts";
import {
  Applied,
  Leaf,
  Presence,
  Removal,
  Request,
  Scope,
  Signed,
} from "./input.ts";

const Globals = SharedGlobals.extend({
  vaultName: z.string().min(1).optional(),
  secretKey: z.string().min(1).optional(),
});
const Args = DirectArgs.extend({
  node_id: z.number().int().positive(),
  target_node_id: z.number().int().positive().optional(),
});
const Intent = z.object({
  leaf: z.union([Leaf, Removal]),
  cancelled: z.boolean(),
});
const InputResult = z.record(z.string(), z.unknown());
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
  vaultService?: CertificateCaVault["vaultService"];
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};
type ArgsType = z.infer<typeof Args>;

const RequestRecord = z.union([
  z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    state: z.literal("current"),
  }).strict(),
  z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    state: z.literal("request"),
    request_base64: z.string().max(32768),
  }).strict(),
]);
const SignedRecord = z.object({
  node_id: z.number().int().positive(),
  scope: z.string(),
  certificate_base64: z.string(),
  root_certificate_base64: z.string(),
}).strict();
const SignedBatch = z.object({ certificates: z.array(SignedRecord).max(256) })
  .strict();

export function certificates(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  actions = certificateActions(),
  signBatch = signCertificateBatch,
) {
  const custody = (ctx: Context): CertificateCaVault => {
    if (
      !ctx.vaultService || !ctx.globalArgs.vaultName ||
      !ctx.globalArgs.secretKey
    ) {
      throw new Error("certificate.ca_custody_unconfigured");
    }
    return {
      vaultService: ctx.vaultService,
      vaultName: ctx.globalArgs.vaultName,
      secretKey: ctx.globalArgs.secretKey,
    };
  };
  const output = async (ctx: Context, spec: string, value: unknown) => ({
    dataHandles: [await ctx.writeResource(spec, spec, { [spec]: value })],
  });
  const gateway = (args: ArgsType, ctx: Context) =>
    new GatewayOperation(makeHttp(ctx), args);
  const target = (args: ArgsType, leaf: { node_id: number }) => {
    if ((args.target_node_id ?? args.node_id) !== leaf.node_id) {
      throw new Error(
        "Certificate effect Node differs from admitted step placement",
      );
    }
  };
  const scope = (leaf: z.infer<typeof Leaf>) => ({
    node_id: leaf.node_id,
    scope: leaf.scope,
    home: leaf.home,
    agent_sha256: leaf.agent_sha256,
  });
  return {
    type: "@nckrtl/certificates",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      requests: {
        schema: z.object({ requests: z.array(RequestRecord).max(256) }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      signedBatch: {
        schema: SignedBatch,
        lifetime: "1d",
        garbageCollection: 1,
      },

      intent: {
        schema: z.object({ intent: Intent }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      request: {
        schema: z.object({ request: Request }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      signed: {
        schema: z.object({ signed: Signed }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      applied: {
        schema: z.object({ applied: Applied }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      presence: {
        schema: z.object({ presence: Presence }),
        lifetime: "1d",
        garbageCollection: 1,
      },
      result: {
        schema: z.object({ result: InputResult }),
        lifetime: "1d",
        garbageCollection: 1,
      },
    },
    methods: {
      requestAll: {
        description: "Request all leaves placed on one admitted participant",
        arguments: Args.extend({ leaves: z.array(Leaf).max(256) }),
        execute: async (
          args: ArgsType & { leaves: z.infer<typeof Leaf>[] },
          ctx: Context,
        ) => {
          const requests: z.infer<typeof RequestRecord>[] = [];
          for (const leaf of args.leaves) {
            target(args, leaf);
            const request = await actions.request(leaf, ctx.signal);
            if ("error_code" in request) throw new Error(request.error_code);
            requests.push({
              node_id: leaf.node_id,
              scope: leaf.scope,
              ...request,
            });
          }
          return {
            dataHandles: [
              await ctx.writeResource("requests", `requests-${args.run_id}`, {
                requests,
              }),
            ],
          };
        },
      },
      signBatch: {
        description:
          "Sign admitted public leaf requests in the control worker using Swamp vault custody",
        arguments: Args.extend({
          leaves: z.array(Leaf).max(256),
          requests: z.array(z.array(RequestRecord).max(256)).max(
            256,
          ),
        }),
        execute: async (
          args: z.infer<typeof Args> & {
            leaves: z.infer<typeof Leaf>[];
            requests: z.infer<typeof RequestRecord>[][];
          },
          ctx: Context,
        ) => {
          const requests = args.requests.flat();
          const batch = requests.every((request) => request.state === "current")
            ? { certificates: [] }
            : await signBatch(args.leaves, requests, custody(ctx), ctx.signal);
          return {
            dataHandles: [
              await ctx.writeResource(
                "signedBatch",
                `signed-${args.run_id}`,
                batch,
              ),
            ],
          };
        },
      },
      sign: {
        description:
          "Sign one public request in the control worker without exporting CA material",
        arguments: Args.extend({ leaf: Leaf, request: Request }),
        execute: async (
          args: z.infer<typeof Args> & {
            leaf: z.infer<typeof Leaf>;
            request: z.infer<typeof Request>;
          },
          ctx: Context,
        ) => {
          let signed: z.infer<typeof Signed>;
          if ("error_code" in args.request) signed = args.request;
          else if (args.request.state === "current") signed = { skipped: true };
          else {
            const batch = await signBatch(
              [args.leaf],
              [{
                node_id: args.leaf.node_id,
                scope: args.leaf.scope,
                ...args.request,
              }],
              custody(ctx),
              ctx.signal,
            );
            const match = batch.certificates.find((cert) =>
              cert.node_id === args.leaf.node_id &&
              cert.scope === args.leaf.scope
            );
            if (!match) throw new Error("app-dev.certificate_sign_failed");
            signed = Signed.parse({
              certificate_base64: match.certificate_base64,
              root_certificate_base64: match.root_certificate_base64,
            });
          }
          return {
            dataHandles: [
              await ctx.writeResource("signed", "signed", { signed }),
            ],
          };
        },
      },
      publishAll: {
        description: "Publish and inspect all leaves on one participant",
        arguments: Args.extend({
          leaves: z.array(Leaf).max(256),
          requests: z.array(RequestRecord).max(256),
          signed: z.array(SignedRecord).max(256),
        }),
        execute: async (
          args: ArgsType & {
            leaves: z.infer<typeof Leaf>[];
            requests: z.infer<typeof RequestRecord>[];
            signed: z.infer<typeof SignedRecord>[];
          },
          ctx: Context,
        ) => {
          for (const leaf of args.leaves) {
            target(args, leaf);
            const record = args.requests.find((r) =>
              r.node_id === leaf.node_id && r.scope === leaf.scope
            );
            if (!record) {
              throw new Error("Public request missing for admitted leaf");
            }
            const request = record.state === "current"
              ? { state: "current" as const }
              : {
                state: "request" as const,
                request_base64: record.request_base64,
              };
            const signed = args.signed.find((r) =>
              r.node_id === leaf.node_id && r.scope === leaf.scope
            );
            const signature = signed
              ? {
                certificate_base64: signed.certificate_base64,
                root_certificate_base64: signed.root_certificate_base64,
              }
              : { skipped: true };
            const applied = await actions.publish(
              leaf,
              request,
              signature,
              ctx.signal,
            );
            const presence = await actions.inspect(scope(leaf), applied);
            if ("error_code" in presence) throw new Error(presence.error_code);
            if (!presence.present) {
              throw new Error("certificate.presence_mismatch");
            }
          }
          return output(ctx, "presence", { present: true });
        },
      },

      admit: {
        description: "Read standalone admitted public certificate intent",
        arguments: Args,
        execute: async (args: ArgsType, ctx: Context) => {
          const g = gateway(args, ctx);
          const current = z.object({
            id: z.literal(args.operation_id),
            kind: z.literal(args.kind),
            node_id: z.literal(args.node_id),
            input: z.unknown(),
            cancel_requested: z.boolean(),
          }).parse(await g.read(ctx.signal));
          const leaf = args.kind === "certificate.converge"
            ? Leaf.parse(current.input)
            : Removal.parse(current.input);
          target(args, leaf);
          await g.project({
            status: "running",
            started_at: new Date().toISOString(),
          });
          return output(ctx, "intent", {
            leaf,
            cancelled: current.cancel_requested,
          });
        },
      },
      request: {
        description: "Prepare local key and public CSR",
        arguments: Args.extend({
          leaf: Leaf,
          cancelled: z.boolean().default(false),
        }),
        execute: async (
          args: ArgsType & { leaf: z.infer<typeof Leaf>; cancelled: boolean },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          return output(
            ctx,
            "request",
            args.cancelled
              ? { error_code: "operation.cancelled" }
              : await actions.request(args.leaf, ctx.signal),
          );
        },
      },
      publish: {
        description: "Publish signed public leaf with local private key",
        arguments: Args.extend({
          leaf: Leaf,
          request: Request,
          signed: Signed,
        }),
        execute: async (
          args: ArgsType & {
            leaf: z.infer<typeof Leaf>;
            request: z.infer<typeof Request>;
            signed: z.infer<typeof Signed>;
          },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          return output(
            ctx,
            "applied",
            await actions.publish(
              args.leaf,
              args.request,
              args.signed,
              ctx.signal,
            ),
          );
        },
      },
      remove: {
        description: "Remove an admitted unused local certificate",
        arguments: Args.extend({
          leaf: Removal,
          cancelled: z.boolean().default(false),
        }),
        execute: async (
          args: ArgsType & {
            leaf: z.infer<typeof Removal>;
            cancelled: boolean;
          },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          return output(
            ctx,
            "applied",
            args.cancelled
              ? { error_code: "operation.cancelled" }
              : await actions.remove(args.leaf, ctx.signal),
          );
        },
      },
      removeLeaf: {
        description: "Remove and inspect a leaf as one reusable parent step",
        arguments: Args.extend({ leaf: Removal }),
        execute: async (
          args: ArgsType & { leaf: z.infer<typeof Removal> },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          const applied = await actions.remove(args.leaf, ctx.signal);
          const presence = await actions.inspect({
            node_id: args.leaf.node_id,
            scope: args.leaf.scope,
            home: args.leaf.home,
            agent_sha256: args.leaf.agent_sha256,
          }, applied);
          if ("error_code" in presence) throw new Error(presence.error_code);
          if (presence.present) {
            throw new Error("certificate.presence_mismatch");
          }
          return output(ctx, "presence", presence);
        },
      },
      inspect: {
        description: "Observe local certificate presence after its effect",
        arguments: Args.extend({ leaf: Scope, previous: Applied }),
        execute: async (
          args: ArgsType & {
            leaf: z.infer<typeof Scope>;
            previous: z.infer<typeof Applied>;
          },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          return output(
            ctx,
            "presence",
            await actions.inspect(args.leaf, args.previous, ctx.signal),
          );
        },
      },
      project: {
        description: "Project standalone bounded presence or refusal",
        arguments: Args.extend({ presence: Presence }),
        execute: async (
          args: ArgsType & { presence: z.infer<typeof Presence> },
          ctx: Context,
        ) => {
          const p = args.presence;
          let result: Record<string, unknown> = p;
          let status = "error_code" in p
            ? (p.error_code === "operation.cancelled" ? "cancelled" : "failed")
            : "succeeded";
          if (
            "present" in p &&
            p.present !== (args.kind === "certificate.converge")
          ) {
            status = "failed";
            result = { error_code: "certificate.presence_mismatch" };
          }
          if (status === "cancelled") result = {};
          await gateway(args, ctx).project({
            status,
            started_at: new Date().toISOString(),
            finished_at: new Date().toISOString(),
            result,
            error_code: result.error_code ?? null,
          });
          if (status === "failed") throw new Error(String(result.error_code));
          return output(ctx, "result", { status });
        },
      },
    },
  };
}
export const behavior = { ...certificates(), type: "@nckrtl/certificates" };
