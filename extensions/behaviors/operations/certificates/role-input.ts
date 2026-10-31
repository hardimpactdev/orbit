import { z } from "npm:zod@4.4.3";

/** Matches Orbit AnalyticsHostname::Value (analytics + ReservedPrivateHostname::PrivateSpace). */
export const AnalyticsHostname = "analytics.orbit";

/** Only these admitted roles own fixed Caddy pair paths. */
export const RoleLeaf = z.object({
  node_id: z.number().int().positive(),
  scope: z.enum([
    "role-gateway",
    "role-metrics",
    "role-websocket",
    "role-analytics",
  ]),
  domain: z.string().max(253).regex(
    /^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/,
  ),
  version: z.string().regex(/^[a-f0-9]{16}$/),
  root_hash: z.string().regex(/^[a-f0-9]{64}$/),
}).strict().refine(
  (leaf) =>
    leaf.scope !== "role-analytics" || leaf.domain === AnalyticsHostname,
  { message: "Analytics role uses its reserved hostname" },
);
