import { z } from "npm:zod@4.4.3";
import { DirectArgs, Globals as SharedGlobals } from "../_lib/direct.ts";
import { certificateActions } from "./actions.ts";
import { signCertificateBatch, SigningLeaf } from "./signing.ts";
import { RoleLeaf } from "./role-input.ts";
import { roleCertificateActions } from "./role-actions.ts";
import {
  Applied,
  Leaf,
  Presence,
  Removal,
  Request,
  Scope,
  Signed,
} from "./input.ts";

const Globals = SharedGlobals;
const Args = DirectArgs.extend({
  node_id: z.number().int().positive(),
  target_node_id: z.number().int().positive().optional(),
});
type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
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
  actions = certificateActions(),
  signBatch: (
    leaves: unknown,
    requests: unknown,
    response: unknown,
  ) =>
    | ReturnType<typeof signCertificateBatch>
    | Promise<ReturnType<typeof signCertificateBatch>> = signCertificateBatch,
  roleActions = roleCertificateActions(),
) {
  const output = async (ctx: Context, spec: string, value: unknown) => ({
    dataHandles: [await ctx.writeResource(spec, spec, { [spec]: value })],
  });
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
    user: leaf.user,
    agent_sha256: leaf.agent_sha256,
  });
  return {
    type: "@nckrtl/certificates",
    version: "2026.10.31.4",
    globalArguments: Globals,
    resources: {
      signingInput: {
        schema: z.object({
          mode: z.string(),
          requests_json: z.string(),
          leaf_json: z.string(),
        }),
        lifetime: "1d",
        garbageCollection: 10,
      },
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
    },
    methods: {
      prepareSign: {
        description: "Shape public signing inputs for the placed HTTP command",
        arguments: Args.extend({ leaf: SigningLeaf, request: Request }),
        execute: async (
          args: ArgsType & {
            leaf: z.infer<typeof SigningLeaf>;
            request: z.infer<typeof Request>;
          },
          ctx: Context,
        ) => {
          const value = {
            mode: "single",
            requests_json: JSON.stringify(args.request),
            leaf_json: JSON.stringify(args.leaf),
          };
          await ctx.writeResource(
            "signingInput",
            `input-${args.run_id}`,
            value,
          );
          return value;
        },
      },
      prepareSignBatch: {
        description:
          "Shape public participant requests for the placed HTTP command",
        arguments: Args.extend({
          leaves: z.array(SigningLeaf).max(256),
          requests: z.array(z.array(RequestRecord).max(256)).max(256),
          restoring: z.boolean().optional(),
        }),
        execute: async (
          args: ArgsType & { requests: unknown[][] },
          ctx: Context,
        ) => {
          const value = {
            mode: "batch",
            requests_json: JSON.stringify(args.requests),
            leaf_json: "{}",
          };
          await ctx.writeResource(
            "signingInput",
            `input-${args.run_id}`,
            value,
          );
          return value;
        },
      },
      roleRequest: {
        description:
          "Prepare a fixed role pair key locally and return only its public CSR",
        arguments: Args.extend({ leaf: RoleLeaf }),
        execute: async (
          args: ArgsType & { leaf: z.infer<typeof RoleLeaf> },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          const request = await roleActions.request(args.leaf, ctx.signal);
          if ("error_code" in request) throw new Error(request.error_code);
          return output(ctx, "request", request);
        },
      },
      rolePublish: {
        description:
          "Publish a signed fixed role pair using only the key on this effect Node",
        arguments: Args.extend({
          leaf: RoleLeaf,
          request: Request,
          signed: Signed,
        }),
        execute: async (
          args: ArgsType & {
            leaf: z.infer<typeof RoleLeaf>;
            request: z.infer<typeof Request>;
            signed: z.infer<typeof Signed>;
          },
          ctx: Context,
        ) => {
          target(args, args.leaf);
          const applied = await roleActions.publish(
            args.leaf,
            args.request,
            args.signed,
            ctx.signal,
          );
          if ("error_code" in applied) throw new Error(applied.error_code);
          return output(ctx, "applied", applied);
        },
      },
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
          "Decode the public Gateway signing response from the placed command step",
        arguments: Args.extend({
          restoring: z.boolean().optional(),
          native_stdout: z.string().max(16_777_216),
          leaves: z.array(SigningLeaf).max(256),
          requests: z.array(z.array(RequestRecord).max(256)).max(
            256,
          ),
        }),
        execute: async (
          args: z.infer<typeof Args> & {
            restoring?: boolean;
            native_stdout: string;
            leaves: z.infer<typeof SigningLeaf>[];
            requests: z.infer<typeof RequestRecord>[][];
          },
          ctx: Context,
        ) => {
          const requests = args.requests.flat();
          const batch = await signBatch(
            args.leaves,
            requests,
            args.native_stdout,
          );
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
          "Decode one public Gateway signature without accessing CA material",
        arguments: Args.extend({
          leaf: SigningLeaf,
          request: Request,
          native_stdout: z.string().max(16_777_216),
        }),
        execute: async (
          args: z.infer<typeof Args> & {
            leaf: z.infer<typeof SigningLeaf>;
            request: z.infer<typeof Request>;
            native_stdout: string;
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
              args.native_stdout,
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
            user: args.leaf.user,
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
    },
  };
}
export const behavior = { ...certificates(), type: "@nckrtl/certificates" };
