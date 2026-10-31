import { z } from "npm:zod@4.4.3";
import {
    networkCommand,
    type NetworkSystem,
    runNetworkSystem,
} from "./network_system.ts";

import {
    NetworkRefusal as Refusal,
    ParticipantNetworkInput,
    ParticipantNetworkOutcome,
} from "./network_contract.ts";
export {
    ParticipantNetworkInput,
    ParticipantNetworkOutcome,
} from "./network_contract.ts";
const uncertain = () =>
    new Error("The participant network outcome is uncertain");

/** Fixed parent-local actions. No child, new key, peer restart or Gateway effect. */
export async function convergeParticipantNetwork(
    raw: ParticipantNetworkInput,
    invoke: NetworkSystem = runNetworkSystem,
    signal?: AbortSignal,
): Promise<ParticipantNetworkOutcome> {
    const parsed = ParticipantNetworkInput.safeParse(raw);
    if (!parsed.success) throw new Error("Invalid participant network intent");
    const input = parsed.data;
    const commands: {
        name: string;
        input: Record<string, unknown>;
        firewall: boolean;
    }[] = [];
    if (input.router_lan) {
        commands.push({
            name: "firewall.router-lan",
            input: input.router_lan,
            firewall: true,
        });
    }
    if (input.peer_dns) {
        commands.push({
            name: "wireguard.repair-peer-dns",
            input: input.peer_dns,
            firewall: false,
        });
    }
    for (const command of commands) {
        signal?.throwIfAborted();
        let reply: unknown;
        try {
            reply = await networkCommand(
                command.name,
                command.input,
                invoke,
                signal,
            );
        } catch {
            throw uncertain();
        }
        if (
            reply !== null && typeof reply === "object" && "error_code" in reply
        ) {
            const code = Refusal.safeParse(reply.error_code);
            if (!code.success) throw uncertain();
            return {
                schema_version: 1,
                node_id: input.node_id,
                status: "failed",
                error_code: code.data,
            };
        }
        if (
            !(command.firewall
                ? z.object({ matches: z.literal(true) }).strict()
                : z.object({}).strict()).safeParse(reply).success
        ) throw uncertain();
    }
    return {
        schema_version: 1,
        node_id: input.node_id,
        status: "succeeded",
        projected: true,
    };
}
