import { parse, stringify } from "jsr:@std/toml@1.0.9";
import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import type { SystemTransport } from "../node/system_command.ts";

const Id = z.number().int().positive();
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/)
  .refine((user) => !["root", "orbit-swamp-package"].includes(user));
export const EnrollmentWorkerInput = z.object({
  task_vm_id: Id,
  guest_node_id: Id,
  user: User,
  agent_sha256: Digest,
  agent: z.object({
    configuration: z.string().min(1).max(65536),
    certificate: z.string().min(1).max(65536),
    unit: z.string().min(1).max(65536),
    checkouts: z.array(z.string().min(1).max(4096)).max(1024),
  }).strict(),
}).strict();
export type EnrollmentWorkerIntent = z.infer<typeof EnrollmentWorkerInput>;
type Private = Pick<
  GatewayOperation,
  "withPrivateMaterial" | "storeSecretOutput"
>;
export type NativeAgentConfiguration = EnrollmentWorkerIntent["agent"] & {
  agent_sha256: string;
  secret_hash: string | null;
  allow_secret_rotation: boolean;
  binary_changed: boolean;
  worker_credentials: { enrollment_token: string; server_token: string } | null;
};
/** Matches the canonical Node configureAgent lower; the caller imports its implementation. */
export type NativeConfigureAgent = (
  input: NativeAgentConfiguration,
  transport: SystemTransport,
  signal?: AbortSignal,
) => Promise<unknown>;
const Material = z.object({
  secret_hash: Digest.nullable(),
  worker_credentials: z.object({
    enrollment_token: z.string(),
    server_token: z.string(),
  }).strict().nullable(),
}).passthrough();
const Configured = z.object({
  configured: z.literal(true),
  secret_hash: Digest,
  restarted: z.boolean(),
  unclosed: z.array(z.string()),
});
const refused = () => new Error("task_vm.worker_bootstrap_refused");

/** Configure through the canonical native lower and admitted first-visit transport. No readiness claim. */
export async function configureTaskVmWorker(
  raw: EnrollmentWorkerIntent,
  enabled: boolean,
  guard: () => Promise<void>,
  privateMaterial: Private,
  transport: SystemTransport,
  configure: NativeConfigureAgent,
  signal?: AbortSignal,
): Promise<{ node_id: number; user: string; configured: true }> {
  try {
    const input = EnrollmentWorkerInput.parse(raw);
    signal?.throwIfAborted();
    await guard();
    const configuration = parse(input.agent.configuration);
    // The base is rendered for this Task VM. A fleet-selected or other worker identity cannot enter it.
    if ("swamp_worker" in configuration) throw refused();
    if (enabled) {
      configuration.swamp_worker = {
        node_id: input.guest_node_id,
        user: input.user,
      };
    }
    await privateMaterial.withPrivateMaterial(async (raw) => {
      const material = Material.parse(raw);
      const pair = material.worker_credentials;
      if (enabled && pair !== null) {
        for (
          const [field, prefix] of [
            ["enrollment_token", `orbit-node-${input.guest_node_id}.`],
            ["server_token", `orbit-node-${input.guest_node_id}-access.`],
          ] as const
        ) {
          if (
            !pair[field].startsWith(prefix) ||
            !/^[a-z0-9-]+\.[A-Za-z0-9_-]{16,4096}$/.test(pair[field])
          ) throw refused();
        }
      }
      await guard();
      signal?.throwIfAborted();
      const result = Configured.parse(
        await configure(
          {
            ...input.agent,
            agent_sha256: input.agent_sha256,
            configuration: stringify(configuration),
            secret_hash: material.secret_hash,
            allow_secret_rotation: material.secret_hash === null,
            binary_changed: false,
            worker_credentials: enabled ? pair : null,
          },
          transport,
          signal,
        ),
      );
      if (
        material.secret_hash !== null &&
        result.secret_hash !== material.secret_hash
      ) throw refused();
      // The generated hash stays on the existing protected transport, never the native result.
      await privateMaterial.storeSecretOutput({
        secret_hash: result.secret_hash,
      });
      if (result.unclosed.length) throw refused();
    });
    // This acknowledges installation only; a separately placed identity/sudo canary must prove readiness.
    return { node_id: input.guest_node_id, user: input.user, configured: true };
  } catch {
    throw refused();
  }
}
