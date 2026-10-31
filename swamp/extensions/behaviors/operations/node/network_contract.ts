import { z } from "npm:zod@4.4.3";
export const ParticipantNetworkInput = z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
    router_lan: z.object({
        desired: z.array(z.record(z.string(), z.unknown())),
        expand: z.boolean(),
        prune: z.boolean(),
    }).strict().nullable(),
    peer_dns: z.object({
        dns_server: z.ipv4(),
        public_key: z.string().regex(/^[A-Za-z0-9+/]{43}=$/),
        domains: z.array(z.string().min(1).max(253).regex(/^[A-Za-z0-9.-]+$/))
            .min(
                1,
            ).max(512),
    }).strict().nullable(),
}).strict();
export type ParticipantNetworkInput = z.infer<typeof ParticipantNetworkInput>;
export const NetworkRefusal = z.enum([
    "agent.version_mismatch",
    "firewall.input_invalid",
    "firewall.status_failed",
    "firewall.status_unrecognized",
    "firewall.rule_collision",
    "firewall.apply_failed",
    "firewall.reconcile_failed",
    "firewall.verify_failed",
    "firewall.remove_failed",
    "firewall.remove_verify_failed",
    "node.firewall_convergence_failed",
    "router.lan_ingress_recovery_failed",
    "wireguard.peer_dns_busy",
    "wireguard.peer_recovery_pending",
    "wireguard.peer_dns_state_unsupported",
    "wireguard.peer_dns_candidate_invalid",
    "wireguard.peer_dns_apply_failed",
    "wireguard.peer_dns_uplink_missing",
]);
export const ParticipantNetworkOutcome = z.discriminatedUnion("status", [
    z.object({
        schema_version: z.literal(1),
        node_id: z.number().int().positive(),
        status: z.literal("succeeded"),
        projected: z.literal(true),
    }).strict(),
    z.object({
        schema_version: z.literal(1),
        node_id: z.number().int().positive(),
        status: z.literal("failed"),
        error_code: NetworkRefusal,
    }).strict(),
]);
export type ParticipantNetworkOutcome = z.infer<
    typeof ParticipantNetworkOutcome
>;

const Domain = z.string().min(1).max(253).regex(
    /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?![\s\S])/,
);
const Domains = z.array(Domain).min(1).max(512).refine(
    (domains) =>
        domains.every((domain, index) =>
            (index === 0 || domains[index - 1] < domain) &&
            !domains.some((parent) =>
                parent !== domain && domain.endsWith(`.${parent}`)
            )
        ),
    "Routing domains must be a canonical sorted suffix set",
);
const PublicKey = z.string().regex(/^[A-Za-z0-9+/]{43}=$/).refine((value) => {
    try {
        const bytes = atob(value);
        return bytes.length === 32 && btoa(bytes) === value;
    } catch {
        return false;
    }
}, "WireGuard public key must be canonical base64 for 32 bytes");

/** The root reads this immutable public slice and verifies its Node binding. */
export const NodeNetworkInputV1 = z.object({
    schema_version: z.literal(1),
    node_id: z.number().int().positive(),
    agent_sha256: z.string().length(64).regex(/^[a-f0-9]{64}$/),
    peer_dns: z.object({
        dns_server: z.ipv4(),
        public_key: PublicKey,
        domains: Domains,
    }).strict(),
}).strict();
export type NodeNetworkInputV1 = z.infer<typeof NodeNetworkInputV1>;
export const NodeNetworkResultV1 = z.discriminatedUnion("status", [
    z.object({
        schema_version: z.literal(1),
        name: z.literal("peer-dns"),
        status: z.enum(["applied", "unchanged"]),
        policy: z.literal(true),
        uplink: z.literal(true),
    }).strict(),
    z.object({
        schema_version: z.literal(1),
        name: z.literal("peer-dns"),
        status: z.literal("failed"),
        error_code: NetworkRefusal,
    }).strict(),
]);
export type NodeNetworkResultV1 = z.infer<typeof NodeNetworkResultV1>;
