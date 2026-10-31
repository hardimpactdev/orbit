import { z } from "npm:zod@4.4.3";
import { Applied, Request, Signed } from "./input.ts";
import { RoleLeaf } from "./role-input.ts";
import { signCertificateBatch } from "./signing.ts";

const Read = z.object({ leaf: RoleLeaf }).strict();
const Assets = z.object({
    input_json: z.string(),
    scripts_directory: z.string(),
}).strict();
const PublicRequest = z.object({
    request: Request,
    request_json: z.string(),
    leaf_json: z.string(),
}).strict();
const Signature = z.object({ signed: Signed, signed_json: z.string() })
    .strict();
const Completion = z.object({
    ready: z.literal(true),
    node_id: z.number().int().positive(),
    scope: RoleLeaf.shape.scope,
    version: RoleLeaf.shape.version,
}).strict();
type Context = {
    extensionFile: (path: string) => string | Promise<string>;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const retained = <T>(schema: T) => ({
    schema,
    lifetime: "1d",
    garbageCollection: 10,
});

/** Public assets and pure decoders. Official SSH and core steps own effects. */
export const behavior = {
    type: "@nckrtl/node-role-certificate-assets",
    version: "2026.10.31.1",
    resources: {
        assets: retained(Assets),
        request: retained(PublicRequest),
        signed: retained(Signature),
        completion: retained(Completion),
    },
    methods: {
        read: {
            description:
                "Resolve shared scripts and the admitted public role leaf",
            arguments: Read,
            execute: async (raw: z.infer<typeof Read>, ctx: Context) => {
                const { leaf } = Read.parse(raw);
                const script = await ctx.extensionFile(
                    "scripts/role_request.py",
                );
                const result = Assets.parse({
                    input_json: JSON.stringify(leaf),
                    scripts_directory: script.replace(/\/[^/]+$/, ""),
                });
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
        decodeRequest: {
            description: "Decode the Node-local public role CSR",
            arguments: z.object({
                leaf: RoleLeaf,
                stdout: z.string().max(65536),
            }).strict(),
            execute: async (
                args: { leaf: z.infer<typeof RoleLeaf>; stdout: string },
                ctx: Context,
            ) => {
                const leaf = RoleLeaf.parse(args.leaf),
                    request = Request.parse(JSON.parse(args.stdout));
                if ("error_code" in request) {
                    throw new Error(request.error_code);
                }
                if (request.state === "request") {
                    const pem = atob(request.request_base64);
                    if (
                        !pem.startsWith(
                            "-----BEGIN CERTIFICATE REQUEST-----",
                        ) || pem.includes("PRIVATE KEY")
                    ) throw new Error("certificate.request_invalid");
                }
                const result = PublicRequest.parse({
                    request,
                    request_json: JSON.stringify(request),
                    leaf_json: JSON.stringify(leaf),
                });
                await ctx.writeResource("request", "request", result);
                return result;
            },
        },
        decodeSigned: {
            description: "Decode the canonical parent-bound role signature",
            arguments: z.object({
                leaf: RoleLeaf,
                request: Request,
                stdout: z.string().max(16777216),
            }).strict(),
            execute: async (
                args: {
                    leaf: z.infer<typeof RoleLeaf>;
                    request: z.infer<typeof Request>;
                    stdout: string;
                },
                ctx: Context,
            ) => {
                const leaf = RoleLeaf.parse(args.leaf),
                    request = Request.parse(args.request);
                if ("error_code" in request) {
                    throw new Error(request.error_code);
                }
                const batch = signCertificateBatch([leaf], [{
                    node_id: leaf.node_id,
                    scope: leaf.scope,
                    ...request,
                }], args.stdout);
                const record = batch.certificates[0];
                const signed = record
                    ? {
                        certificate_base64: record.certificate_base64,
                        root_certificate_base64: record.root_certificate_base64,
                    }
                    : { skipped: true };
                const result = Signature.parse({
                    signed,
                    signed_json: JSON.stringify(signed),
                });
                await ctx.writeResource("signed", "signed", result);
                return result;
            },
        },
        result: {
            description:
                "Record readiness after native publication and activation",
            arguments: z.object({
                leaf: RoleLeaf,
                stdout: z.string().max(65536),
            }).strict(),
            execute: async (
                args: {
                    leaf: z.infer<typeof RoleLeaf>;
                    stdout: string;
                },
                ctx: Context,
            ) => {
                const leaf = RoleLeaf.parse(args.leaf);
                const applied = Applied.parse(JSON.parse(args.stdout));
                if ("error_code" in applied) {
                    throw new Error(applied.error_code);
                }
                const result = Completion.parse({
                    ready: true,
                    node_id: leaf.node_id,
                    scope: leaf.scope,
                    version: leaf.version,
                });
                await ctx.writeResource("completion", "completion", result);
                return result;
            },
        },
    },
};
