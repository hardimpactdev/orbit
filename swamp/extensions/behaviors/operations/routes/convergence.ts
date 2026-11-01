import { z } from "npm:zod@4.4.3";
import { Leaf, Removal } from "../certificates/input.ts";
const Member = z.object({
    instance_id: z.number().int().positive(),
    node_id: z.number().int().positive(),
    production: z.boolean(),
    certificate_scope: z.string(),
});
export const RouteIntent = z.object({
    route_id: z.number().int().positive(),
    status: z.string().default("pending"),
    mode: z.string(),
    domain: z.string(),
    node_ids: z.array(z.number().int().positive()),
    workloads: z.array(Member),
    certificates: z.array(Leaf),
    rollback_removals: z.array(Removal).default([]),
    rollback_lan: z.array(
        z.object({
            node_id: z.number().int().positive(),
            operation: z.literal("remove"),
            route_id: z.number().int().positive(),
        }).strict(),
    ).default([]),
    workload_node_ids: z.array(z.number().int().positive()).default([]),
    workload_caddy_node_ids: z.array(z.number().int().positive()).default([]),
    public_node_ids: z.array(z.number().int().positive()).default([]),
    upstream_firewall_node_ids: z.array(z.number().int().positive()).default(
        [],
    ),
    caddy_node_ids: z.array(z.number().int().positive()).default([]),
    dns_node_ids: z.array(z.number().int().positive()),
    router_node_id: z.number().int().positive().nullable(),
    ingress_node_id: z.number().int().positive().nullable(),
    production: z.boolean(),
    public: z.boolean(),
    web_root: z.string().nullable(),
});
export function nativeRouteDnsGrace(signal?: AbortSignal) {
    return new Promise<void>((resolve, reject) => {
        signal?.throwIfAborted();
        const abort = () => {
            clearTimeout(timer);
            reject(signal?.reason);
        };
        const timer = setTimeout(() => {
            signal?.removeEventListener("abort", abort);
            resolve();
        }, 31000);
        signal?.addEventListener("abort", abort, { once: true });
    });
}
