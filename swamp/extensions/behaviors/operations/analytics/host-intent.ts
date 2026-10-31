import { z } from "npm:zod@4.4.3";
import { Scope } from "../certificates/input.ts";

export const Domain = z.string().regex(/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/)
    .max(253);

/** Public tracking-leaf identity shared by admission payloads and native steps. */
export const Leaf = z.object({
    node_id: z.number().int().positive(),
    route_id: z.number().int().positive(),
    user: Scope.shape.user,
    agent_sha256: Scope.shape.agent_sha256,
    home: Scope.shape.home,
    in_use: z.literal(false).optional(),
    domain: Domain,
    scope: z.string(),
    version: z.string().regex(/^[a-f0-9]{16}$/).optional(),
    root_hash: z.string().regex(/^[a-f0-9]{64}$/).optional(),
}).strict().refine((leaf) => leaf.scope === `route-${leaf.route_id}-router`);
