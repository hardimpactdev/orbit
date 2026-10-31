import { z } from "npm:zod@4.4.3";
import { DirectArgs } from "../_lib/direct.ts";
export { Leaf } from "./host-intent.ts";
export const Args = DirectArgs.extend({
    node_id: z.number().int().positive(),
    kind: z.literal("analytics.enable"),
});
export const Request = z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    state: z.enum(["current", "request"]),
    request_base64: z.string().max(32768).optional(),
});
export const Signed = z.object({
    node_id: z.number().int().positive(),
    scope: z.string(),
    certificate_base64: z.string().max(32768),
    root_certificate_base64: z.string().max(32768),
});
