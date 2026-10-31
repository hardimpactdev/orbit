import { z } from "npm:zod@4.4.3";
import {
  networkCommand,
  type NetworkSystem,
  runNetworkSystem,
} from "./network_system.ts";

const PublicKey = z.string().regex(/^[A-Za-z0-9+/]{43}=$/);
const Cidr = z.string().regex(/^[0-9.]+\/(?:[0-9]|[12][0-9]|3[0-2])$/)
  .refine((v) => z.ipv4().safeParse(v.split("/")[0]).success);
const Endpoint = z.string().max(262).regex(/^[A-Za-z0-9.[\]:-]+$/)
  .refine((v) => {
    const split = v.lastIndexOf(":");
    const port = v.slice(split + 1);
    return split > 0 && /^[0-9]{1,5}$/.test(port) &&
      Number(port) > 0 && Number(port) <= 65535;
  });
const Identity = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
export const PreparePeerInput = Identity.extend({
  existing_public_key: PublicKey.nullable(),
});
export const PublishPeerInput = PreparePeerInput.extend({
  peer: z.object({
    server_public_key: PublicKey,
    peer_public_key: PublicKey,
    endpoint: Endpoint,
    address: Cidr,
    subnet: Cidr,
    dns_server: z.ipv4(),
    domains: z.array(
      z.string().min(1).max(253).regex(/^[A-Za-z0-9.-]+$/)
        .refine((v) => v !== "."),
    ).min(1).max(512),
    operator_dns: z.ipv4().nullable(),
    retain: z.boolean(),
  }).strict(),
}).refine((v) =>
  v.peer.retain === (v.existing_public_key !== null) &&
  (v.existing_public_key === null ||
    v.existing_public_key === v.peer.peer_public_key)
);
export const CommitPeerInput = Identity.extend({ public_key: PublicKey });
export const RollbackPeerInput = Identity;
export const RecoverPeerInput = Identity.extend({
  peer: PublishPeerInput.shape.peer.omit({ peer_public_key: true }),
});
export const PeerRecovery = z.object({ restore_public_key: z.boolean() })
  .strict();

/** Public admission shared by peer roots, enrollment and removal refresh. */
export const NodeNetworkSnapshot = Identity.extend({
  hub_id: z.number().int().positive(),
  existing_key: PublicKey.nullable(),
  architecture: z.string().min(1),
  peer: PublishPeerInput.shape.peer.omit({ peer_public_key: true }),
  trust: z.object({
    desired: z.array(z.unknown()),
    remove: z.array(z.unknown()),
    enable: z.boolean(),
  }).strict(),
}).strict();
export type NodeNetworkSnapshot = z.infer<typeof NodeNetworkSnapshot>;

const Refusal = z.enum([
  "agent.version_mismatch",
  "wireguard.peer_busy",
  "wireguard.peer_key_missing",
  "wireguard.peer_key_failed",
  "wireguard.peer_state_unsupported",
  "wireguard.peer_recovery_pending",
  "wireguard.peer_dns_uplink_missing",
  "wireguard.peer_config_failed",
  "wireguard.peer_commit_failed",
  "wireguard.peer_rollback_failed",
  "vpn.peer_key_mismatch",
]);
const KeyResult = z.object({ public_key: PublicKey }).strict();
const EmptyResult = z.object({}).strict();
const commands = {
  prepare: "wireguard.prepare-peer-key",
  publish: "wireguard.publish-peer",
  commit: "wireguard.commit-peer",
  rollback: "wireguard.rollback-peer",
} as const;
type Stage = keyof typeof commands;
const OutcomeBase = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  stage: z.enum(["prepare", "publish", "commit", "rollback"]),
});
export const PeerNetworkOutcome = z.discriminatedUnion("status", [
  OutcomeBase.extend({
    status: z.literal("succeeded"),
    public_key: PublicKey.optional(),
  }).strict(),
  OutcomeBase.extend({
    status: z.literal("failed"),
    error_code: Refusal,
  }).strict(),
]).refine((v) =>
  v.status !== "succeeded" ||
  ((v.stage === "prepare" || v.stage === "publish") ===
    (v.public_key !== undefined))
);
export type PeerNetworkOutcome = z.infer<typeof PeerNetworkOutcome>;

function intent<T>(schema: z.ZodType<T>, raw: unknown): T {
  const parsed = schema.safeParse(raw);
  if (!parsed.success) throw new Error("Invalid parent peer intent");
  return parsed.data;
}
const uncertain = () => new Error("The peer action outcome is uncertain");

/** Fixed local actions only; the parent owns placement, checkpoints and compensation. */
export function peerNetworkActions(invoke: NetworkSystem = runNetworkSystem) {
  const run = async (
    stage: Stage,
    identity: z.infer<typeof Identity>,
    input: Record<string, unknown>,
    signal?: AbortSignal,
    expectedKey?: string,
  ): Promise<PeerNetworkOutcome> => {
    signal?.throwIfAborted();
    const raw = await networkCommand(commands[stage], input, invoke, signal);
    const base = {
      schema_version: 1 as const,
      node_id: identity.node_id,
      stage,
    };
    if (raw !== null && typeof raw === "object" && "error_code" in raw) {
      const code = Refusal.safeParse(raw.error_code);
      if (!code.success) throw uncertain();
      return { ...base, status: "failed", error_code: code.data };
    }
    if (stage === "prepare" || stage === "publish") {
      const result = KeyResult.safeParse(raw);
      if (!result.success) throw uncertain();
      if (expectedKey !== undefined && result.data.public_key !== expectedKey) {
        return {
          ...base,
          status: "failed",
          error_code: "vpn.peer_key_mismatch",
        };
      }
      return {
        ...base,
        status: "succeeded",
        public_key: result.data.public_key,
      };
    }
    if (!EmptyResult.safeParse(raw).success) throw uncertain();
    return { ...base, status: "succeeded" };
  };
  return {
    async prepare(raw: unknown, signal?: AbortSignal) {
      const input = intent(PreparePeerInput, raw);
      return await run(
        "prepare",
        input,
        {
          existing: input.existing_public_key !== null,
        },
        signal,
        input.existing_public_key ?? undefined,
      );
    },
    async publish(raw: unknown, signal?: AbortSignal) {
      const input = intent(PublishPeerInput, raw);
      return await run(
        "publish",
        input,
        input.peer,
        signal,
        input.peer.peer_public_key,
      );
    },
    async commit(raw: unknown, signal?: AbortSignal) {
      const input = intent(CommitPeerInput, raw);
      return await run(
        "commit",
        input,
        { public_key: input.public_key },
        signal,
      );
    },
    async rollback(raw: unknown, signal?: AbortSignal) {
      return await run("rollback", intent(RollbackPeerInput, raw), {}, signal);
    },
    async recover(raw: unknown) {
      const input = intent(RecoverPeerInput, raw);
      const result = await networkCommand("wireguard.recover-peer", {
        peer: input.peer,
      }, invoke);
      const recovery = PeerRecovery.safeParse(result);
      if (!recovery.success) throw uncertain();
      return recovery.data;
    },
  };
}

export const localPeerNetwork = peerNetworkActions();
