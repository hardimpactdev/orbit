import { z } from "npm:zod@4.4.3";
import { networkCommand, type NetworkSystem } from "../node/network_system.ts";
import type { SystemTransport } from "../node/system_command.ts";

const Id = z.number().int().positive();
const Key = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Cidr = z.string().refine((value) => {
  const [address, mask, extra] = value.split("/");
  return extra === undefined && z.ipv4().safeParse(address).success &&
    /^(?:[0-9]|[12][0-9]|3[0-2])$/.test(mask ?? "");
});
export const EnrollmentNetworkInput = z.object({
  task_vm_id: Id,
  guest_node_id: Id,
}).strict();
export const EnrollmentPeerNetwork = z.object({
  server_public_key: Key,
  endpoint: z.string().regex(/^[A-Za-z0-9.:[\]-]{1,255}:[1-9][0-9]{0,4}$/),
  address: Cidr,
  subnet: Cidr,
  dns_server: z.ipv4(),
  domains: z.array(z.string().regex(/^[A-Za-z0-9.-]{1,253}$/)).min(1).max(256),
}).strict();
type Input = z.infer<typeof EnrollmentNetworkInput>;
const refused = () => new Error("task_vm.network_failed");

function guestNetwork(
  system: SystemTransport,
  guard: () => Promise<void>,
): NetworkSystem {
  return async (argv, stdin, signal) => {
    signal?.throwIfAborted();
    await guard();
    return await system({
      command: "/usr/bin/sudo",
      args: ["-n", "--", ...argv],
      stdin,
      timeoutMs: 120000,
    }, signal);
  };
}

/** Reuse the Nodes-owned network policy through the admitted first-visit transport. */
export async function prepareTaskVmNetwork(
  raw: Input,
  guard: () => Promise<void>,
  system: SystemTransport,
  signal?: AbortSignal,
) {
  EnrollmentNetworkInput.parse(raw);
  const answer = await networkCommand(
    "wireguard.prepare-peer-key",
    { existing: false },
    guestNetwork(system, guard),
    signal,
  );
  const result = z.object({ public_key: Key }).strict().safeParse(answer);
  if (!result.success) throw refused();
  return { wireguard_public_key: result.data.public_key };
}

export async function publishTaskVmNetwork(
  raw: Input,
  rawNetwork: z.infer<typeof EnrollmentPeerNetwork>,
  rawKey: string,
  guard: () => Promise<void>,
  system: SystemTransport,
  signal?: AbortSignal,
) {
  const input = EnrollmentNetworkInput.parse(raw);
  const network = EnrollmentPeerNetwork.parse(rawNetwork);
  const key = Key.parse(rawKey);
  const invoke = guestNetwork(system, guard);
  const answer = await networkCommand(
    "wireguard.publish-peer",
    {
      ...network,
      peer_public_key: key,
      operator_dns: null,
      retain: false,
    },
    invoke,
    signal,
  );
  if (
    !z.object({ public_key: z.literal(key) }).strict().safeParse(answer).success
  ) throw refused();
  const committed = await networkCommand(
    "wireguard.commit-peer",
    { public_key: key },
    invoke,
    signal,
  );
  if (!z.object({}).strict().safeParse(committed).success) throw refused();
  return {
    guest_node_id: input.guest_node_id,
    network_configured: true as const,
  };
}
