import { z } from "npm:zod@4.4.3";
import { Leaf } from "./input.ts";
import { RoleLeaf } from "./role-input.ts";

export const SigningLeaf = z.union([Leaf, RoleLeaf]);
import { Signed } from "./input.ts";

export const CertificateRequestRecord = z.union([
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
export const CertificateSignedRecord = z.object({
  node_id: z.number().int().positive(),
  scope: z.string(),
  certificate_base64: z.string().max(32768),
  root_certificate_base64: z.string().max(32768),
}).strict();
const Batch = z.object({
  certificates: z.array(CertificateSignedRecord).max(256),
}).strict();

/** Decode the public bundle returned by the placed native Gateway-sign step. No effects. */
export function signCertificateBatch(
  rawLeaves: unknown,
  rawRequests: unknown,
  rawResponse: unknown,
) {
  const leaves = z.array(SigningLeaf).max(256).parse(rawLeaves);
  const requests = z.array(CertificateRequestRecord).max(256).parse(
    rawRequests,
  );
  const result = Batch.parse(
    JSON.parse(z.string().max(16_777_216).parse(rawResponse)),
  );
  const pending = requests.filter((request) => request.state === "request");
  if (result.certificates.length !== pending.length) {
    throw new Error("certificate.signature_batch_mismatch");
  }
  const seen = new Set<string>();
  for (const certificate of result.certificates) {
    const selector = `${certificate.node_id}:${certificate.scope}`;
    if (
      seen.has(selector) ||
      !pending.some((request) =>
        request.node_id === certificate.node_id &&
        request.scope === certificate.scope
      ) ||
      !leaves.some((leaf) =>
        leaf.node_id === certificate.node_id && leaf.scope === certificate.scope
      )
    ) {
      throw new Error("certificate.signature_batch_mismatch");
    }
    seen.add(selector);
    Signed.parse({
      certificate_base64: certificate.certificate_base64,
      root_certificate_base64: certificate.root_certificate_base64,
    });
  }
  return result;
}
