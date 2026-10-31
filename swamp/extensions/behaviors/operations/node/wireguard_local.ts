import { z } from "npm:zod@4.4.3";
import {
  type BootstrapEnvironment,
  type BootstrapSsh,
  bootstrapSsh,
  bootstrapSystem,
  BootstrapTarget,
} from "../node-bootstrap/bootstrap_ssh.ts";
import {
  networkCommand,
  type NetworkSystem,
  runNetworkSystem,
} from "./network_system.ts";

const Id = z.number().int().positive();
const PublicKey = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Cidr = z.string().regex(/^[0-9.]+\/(?:[0-9]|[12][0-9]|3[0-2])$/)
  .refine((value) => z.ipv4().safeParse(value.split("/")[0]).success);
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
export const HubPeer = z.object({
  node_id: Id,
  name: z.string().min(1).max(253).regex(/^[A-Za-z0-9._-]+$/),
  public_key: PublicKey,
  address: z.ipv4(),
}).strict();
/** Hydrated only inside withPrivateMaterial, after acquiring the hub method lock. */
export const HubPrivateConfiguration = z.object({
  snapshot: HubPublicationInput,
  address: Cidr,
  port: z.number().int().min(1).max(65535),
  private_key: PublicKey,
  peers: z.array(HubPeer).max(4096),
  connection: z.object({
    target: BootstrapTarget,
    identity: z.string().startsWith("/").max(4096).regex(/^[^\0\r\n]+$/),
  }).strict().optional(),
}).strict();
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
export type HubInvoke = NetworkSystem;
const uncertain = () => new Error("The WireGuard hub publication is uncertain");

/** Same root only. The parent uses one wireguard-hub-N native method lock. */
export async function convergeWireGuardHub(
  raw: HubPublicationInput,
  privateValue: unknown,
  invoke: HubInvoke = runNetworkSystem,
  signal?: AbortSignal,
  remoteExecution?: { environment: BootstrapEnvironment; ssh?: BootstrapSsh },
): Promise<HubPublicationOutcome> {
  // Parse failures may include credential bytes; never propagate Zod diagnostics.
  const input = HubPublicationInput.safeParse(raw);
  const configuration = HubPrivateConfiguration.safeParse(privateValue);
  if (!input.success || !configuration.success) throw uncertain();
  const snapshot = input.data;
  const material = configuration.data;
  if (JSON.stringify(snapshot) !== JSON.stringify(material.snapshot)) {
    throw uncertain();
  }
  const remote = snapshot.executor_node_id !== snapshot.hub_id;
  if (
    remote !== (material.connection !== undefined) ||
    (material.connection &&
      material.connection.target.node_id !== snapshot.hub_id)
  ) {
    throw uncertain();
  }
  if (remote) {
    if (!remoteExecution || !material.connection) throw uncertain();
    invoke = bootstrapSystem(
      material.connection.target,
      material.connection.identity,
      remoteExecution.environment,
      remoteExecution.ssh ?? bootstrapSsh,
    );
  }
  const peers = material.peers.filter((peer) =>
    peer.node_id !== snapshot.hub_id &&
    peer.node_id !== snapshot.excluded_node_id
  ).sort((left, right) => left.node_id - right.node_id);
  if (
    new Set(peers.map((peer) => peer.node_id)).size !== peers.length ||
    new Set(peers.map((peer) => peer.public_key)).size !== peers.length ||
    new Set(peers.map((peer) => peer.address)).size !== peers.length
  ) throw uncertain();
  const lines = [
    "[Interface]",
    `Address = ${material.address}`,
    `ListenPort = ${material.port}`,
    `PrivateKey = ${material.private_key}`,
  ];
  for (const peer of peers) {
    lines.push(
      "",
      "[Peer]",
      `# ${peer.name}`,
      `PublicKey = ${peer.public_key}`,
      `AllowedIPs = ${peer.address}/32`,
    );
  }
  signal?.throwIfAborted();
  let reply: unknown;
  try {
    reply = await networkCommand(
      "wireguard.project-hub",
      { config: lines.join("\n") + "\n" },
      invoke,
      signal,
    );
  } catch {
    throw uncertain();
  }
  const base = { schema_version: 1, hub_id: snapshot.hub_id };
  const success = z.object({}).strict();
  if (success.safeParse(reply).success) {
    return HubPublicationOutcome.parse({
      ...base,
      status: "succeeded",
      projected: true,
    });
  }
  const failed = HubPublicationOutcome.safeParse({
    ...base,
    status: "failed",
    error_code: reply !== null && typeof reply === "object" &&
        "error_code" in reply
      ? reply.error_code
      : undefined,
  });
  if (!failed.success) throw uncertain();
  return failed.data;
}
