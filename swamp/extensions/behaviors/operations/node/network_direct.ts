import { convergeParticipantNetwork } from "./network_participant.ts";
import { type NetworkSystem, runNetworkSystem } from "./network_system.ts";

import { NodeNetworkInputV1, NodeNetworkResultV1 } from "./network_contract.ts";
export { NodeNetworkInputV1, NodeNetworkResultV1 } from "./network_contract.ts";
/** Same-root direct-system DNS convergence; no observer command performs work. */
export function nodeNetwork(invoke: NetworkSystem = runNetworkSystem) {
    return async (
        actionInput: NodeNetworkInputV1,
        signal?: AbortSignal,
    ): Promise<NodeNetworkResultV1> => {
        const input = NodeNetworkInputV1.parse(actionInput);
        const outcome = await convergeParticipantNetwork(
            {
                schema_version: 1,
                node_id: input.node_id,
                agent_sha256: input.agent_sha256,
                router_lan: null,
                peer_dns: input.peer_dns,
            },
            invoke,
            signal,
        );
        if (outcome.status === "failed") {
            return NodeNetworkResultV1.parse({
                schema_version: 1,
                name: "peer-dns",
                status: "failed",
                error_code: outcome.error_code,
            });
        }
        return NodeNetworkResultV1.parse({
            schema_version: 1,
            name: "peer-dns",
            status: "applied",
            policy: true,
            uplink: true,
        });
    };
}

export const convergeNodeNetwork = nodeNetwork();
