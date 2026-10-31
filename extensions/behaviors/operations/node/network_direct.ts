import { z } from "npm:zod@4.4.3";
import { matchesPolicy } from "../fleet/contract.ts";
import { PeerDnsRefusal, reconcilePeerDns } from "../fleet/peer_dns_local.ts";

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
    error_code: PeerDnsRefusal.shape.error_code,
  }).strict(),
]);
export type NodeNetworkResultV1 = z.infer<typeof NodeNetworkResultV1>;

/** Inject the one Fleet implementation for tests; never duplicate its effects. */
export function nodeNetwork(
  reconcile: typeof reconcilePeerDns = reconcilePeerDns,
) {
  return async (
    actionInput: NodeNetworkInputV1,
    signal?: AbortSignal,
  ): Promise<NodeNetworkResultV1> => {
    const input = NodeNetworkInputV1.parse(actionInput);
    const policy = {
      ...input.peer_dns,
      server: input.peer_dns.dns_server,
      node_id: input.node_id,
      agent_sha256: input.agent_sha256,
    };
    const outcome = await reconcile(policy, { signal });
    if ("error_code" in outcome.result) {
      return NodeNetworkResultV1.parse({
        schema_version: 1,
        name: "peer-dns",
        status: "failed",
        error_code: outcome.result.error_code,
      });
    }
    if (
      !outcome.result.uplink || !matchesPolicy(policy, outcome.result) ||
      typeof outcome.changed !== "boolean"
    ) {
      throw new Error("Peer DNS returned no verified policy");
    }
    return NodeNetworkResultV1.parse({
      schema_version: 1,
      name: "peer-dns",
      status: outcome.changed ? "applied" : "unchanged",
      policy: true,
      uplink: true,
    });
  };
}

/** Same-root local action: no Gateway read/project, child, run or private bytes. */
export const convergeNodeNetwork = nodeNetwork();
