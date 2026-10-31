import { z } from "npm:zod@4.4.3";
export const proxyCgroupPattern =
  "^0::/system\\.slice/orbit-node-proxy-worker-([1-9][0-9]*)\\.service(?:/[^\\n]*)?$";
export function proxyAddress(node: 1 | 18) {
  return node === 1 ? "10.44.0.1" : "10.44.0.13";
}
const identityShape = {
  node_id: z.union([z.literal(1), z.literal(18)]),
  host: z.string(),
  user: z.literal("orbit"),
};
const validateIdentity = (
  v: { node_id: 1 | 18; host: string },
  ctx: z.RefinementCtx,
) => {
  if (v.host !== proxyAddress(v.node_id)) {
    ctx.addIssue({
      code: "custom",
      message: "Proxy host differs from admitted Node",
    });
  }
};
export const ProxyIdentity = z.object(identityShape).strict().superRefine(
  validateIdentity,
);
export const ProxyTarget = z.object({
  ...identityShape,
  host_key: z.string().regex(/^ssh-ed25519 [A-Za-z0-9+/]+={0,2}$/),
  host_fingerprint: z.string().regex(/^SHA256:[A-Za-z0-9+/]{43}$/),
}).strict().superRefine(validateIdentity);
export type ProxyTarget = z.infer<typeof ProxyTarget>;
