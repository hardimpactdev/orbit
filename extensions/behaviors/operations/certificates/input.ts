import { z } from "npm:zod@4.4.3";

export const Code = z.string().regex(/^[a-z0-9_.-]{1,100}$/);
export const Refusal = z.object({ error_code: Code }).strict();
export const Scope = z.object({
  node_id: z.number().int().positive(),
  scope: z.string().regex(
    /^(?:app-instance-[1-9][0-9]*(?:-hostname-change)?|route-[1-9][0-9]*(?:-router(?:-hostname-change)?|-ingress)?)$/,
  ),
  home: z.string().startsWith("/").max(4096).refine((value) =>
    !/[\0\r\n]/.test(value) &&
    value.slice(1).split("/").every((part) =>
      part !== "" && part !== "." && part !== ".."
    )
  ).optional(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
export const Leaf = Scope.extend({
  domain: z.string().min(1).max(253),
  version: z.string().regex(/^[a-f0-9]{16}$/),
  root_hash: z.string().regex(/^[a-f0-9]{64}$/),
});
export const Removal = Scope.extend({ in_use: z.boolean() });
export const Request = z.union([
  z.object({ state: z.literal("current") }).strict(),
  z.object({
    state: z.literal("request"),
    request_base64: z.string().max(32768),
  }).strict(),
  Refusal,
]);
const PublicCertificate = z.string().max(32768).refine((encoded) => {
  try {
    const pem = atob(encoded);
    return pem.startsWith("-----BEGIN CERTIFICATE-----") &&
      !pem.includes("PRIVATE KEY");
  } catch {
    return false;
  }
});
export const Signed = z.union([
  z.object({
    certificate_base64: PublicCertificate,
    root_certificate_base64: PublicCertificate,
  }).strict(),
  z.object({ skipped: z.literal(true) }).strict(),
  Refusal,
]);
export type CertificateLeaf = z.infer<typeof Leaf>;
export type CertificateScope = z.infer<typeof Scope>;
export const Presence = z.union([
  z.object({ present: z.boolean() }).strict(),
  Refusal,
]);
export const Applied = z.union([
  z.object({ applied: z.literal(true) }).strict(),
  Refusal,
]);
