import { z } from "npm:zod@4.4.3";
import { Leaf, Removal, Request, Scope } from "./input.ts";
import { CertificateRequestRecord, signCertificateBatch } from "./signing.ts";

const Intent = z.union([Leaf, Removal, Scope]);
const Assets = z.object({
  input_json: z.string(),
  request_script: z.string(),
  publish_script: z.string(),
  remove_script: z.string(),
  inspect_script: z.string(),
});
const RequestOutput = z.object({
  request: Request,
  record: CertificateRequestRecord,
  request_json: z.string(),
  state: z.enum(["request", "current"]),
});
type Context = {
  extensionFile: (path: string) => string;
  writeResource: (
    spec: string,
    name: string,
    value: unknown,
  ) => Promise<unknown>;
};

/** Public metadata and typed decoders only. Native command steps own every effect. */
export const behavior = {
  type: "@nckrtl/certificate-script-assets",
  version: "2026.10.31.1",
  resources: {
    assets: { schema: Assets, lifetime: "1d", garbageCollection: 10 },
    request: { schema: RequestOutput, lifetime: "1d", garbageCollection: 10 },
    signed: {
      schema: z.object({ signed_json: z.string() }),
      lifetime: "1d",
      garbageCollection: 10,
    },
  },
  methods: {
    read: {
      description:
        "Resolve cached scripts and serialize the admitted public leaf",
      arguments: z.object({
        node_id: z.number().int().positive(),
        intent: Intent,
      }),
      execute: async (
        args: { node_id: number; intent: z.infer<typeof Intent> },
        ctx: Context,
      ) => {
        const intent = Intent.parse(args.intent);
        if (intent.node_id !== args.node_id || [1, 18].includes(args.node_id)) {
          throw new Error("certificate.node_placement_unavailable");
        }
        const assets = Assets.parse({
          input_json: JSON.stringify(intent),
          request_script: ctx.extensionFile("scripts/request.py"),
          publish_script: ctx.extensionFile("scripts/publish.py"),
          remove_script: ctx.extensionFile("scripts/remove.py"),
          inspect_script: ctx.extensionFile("scripts/inspect.py"),
        });
        await ctx.writeResource("assets", "assets", assets);
        return assets;
      },
    },
    decodeRequest: {
      description: "Decode only the public request emitted by the local script",
      arguments: z.object({ leaf: Leaf, stdout: z.string().max(65536) }),
      execute: async (
        args: { leaf: z.infer<typeof Leaf>; stdout: string },
        ctx: Context,
      ) => {
        const leaf = Leaf.parse(args.leaf);
        const request = Request.parse(JSON.parse(args.stdout));
        if ("error_code" in request) throw new Error(request.error_code);
        if (request.state === "request") {
          const pem = atob(request.request_base64);
          if (
            !pem.startsWith("-----BEGIN CERTIFICATE REQUEST-----") ||
            pem.includes("PRIVATE KEY")
          ) {
            throw new Error("certificate.request_invalid");
          }
        }
        const result = RequestOutput.parse({
          request,
          record: { node_id: leaf.node_id, scope: leaf.scope, ...request },
          request_json: JSON.stringify(request),
          state: request.state,
        });
        await ctx.writeResource("request", "request", result);
        return result;
      },
    },
    decodeSigned: {
      description:
        "Select the admitted public certificate from the Gateway response",
      arguments: z.object({ leaf: Leaf, request: Request, stdout: z.string() }),
      execute: async (
        args: {
          leaf: z.infer<typeof Leaf>;
          request: z.infer<typeof Request>;
          stdout: string;
        },
        ctx: Context,
      ) => {
        const request = Request.parse(args.request);
        if ("error_code" in request) throw new Error(request.error_code);
        const batch = signCertificateBatch(
          [args.leaf],
          [{ node_id: args.leaf.node_id, scope: args.leaf.scope, ...request }],
          args.stdout,
        );
        const signed = batch.certificates[0] ?? { skipped: true };
        const result = { signed_json: JSON.stringify(signed) };
        await ctx.writeResource("signed", "signed", result);
        return result;
      },
    },
  },
};
