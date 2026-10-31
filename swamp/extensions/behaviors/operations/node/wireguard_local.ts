import { z } from "npm:zod@4.4.3";
const Id = z.number().int().positive();
/** Public admitted participant only; executor and effect owner remain distinct. */
export const HubPublicationInput = z.object({
    schema_version: z.literal(1),
    executor_node_id: Id,
    hub_id: Id,
    context_node_id: Id,
    excluded_node_id: Id.nullable(),
    agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict().refine((value) => (value.excluded_node_id === null ||
    value.excluded_node_id === value.context_node_id)
);
export type HubPublicationInput = z.infer<typeof HubPublicationInput>;
export const HubPublicationOutcome = z.discriminatedUnion("status", [
    z.object({
        schema_version: z.literal(1),
        hub_id: Id,
        status: z.literal("succeeded"),
        projected: z.literal(true),
    }).strict(),
    z.object({
        schema_version: z.literal(1),
        hub_id: Id,
        status: z.literal("failed"),
        error_code: z.enum([
            "agent.version_mismatch",
            "wireguard.hub_install_failed",
            "wireguard.hub_config_invalid",
            "wireguard.hub_start_failed",
            "wireguard.hub_state_unsupported",
            "wireguard.hub_busy",
        ]),
    }).strict(),
]);
export type HubPublicationOutcome = z.infer<typeof HubPublicationOutcome>;
