import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";
import { Path, Sha } from "./converge_direct_contract.ts";

export const AgentRekeyInput = z.object({
  schema_version: z.literal(1),
  node_id: z.number().int().positive(),
  configure_operation_id: z.number().int().positive(),
  worker_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  agent_sha256: Sha,
  configuration: z.object({
    configuration: z.string().max(65536),
    certificate: z.string().max(65536),
    unit: z.string().max(65536),
    checkouts: z.array(Path).max(1024),
    binary_changed: z.boolean(),
  }).strict(),
}).strict();
const Refusal = z.enum([
  "agent.version_mismatch",
  "agent.update_busy",
]);
const Configured = z.object({
  secret_hash: Sha,
  restarted: z.literal(true),
  unclosed: z.array(z.string().max(4096)).max(1024),
}).strict();
export const AgentRekeyOutcome = z.discriminatedUnion("rekeyed", [
  z.object({ rekeyed: z.literal(true), restarted: z.literal(true) }).strict(),
  z.object({
    rekeyed: z.literal(false),
    error_code: Refusal.or(z.literal("node.agent_rekey_unavailable")),
  }).strict(),
]);
type Invoke = (call: NodeCall, signal?: AbortSignal) => Promise<unknown>;
type Store = (outputs: { secret_hash: string }) => Promise<void>;

/** The owning native method holds its per-Node lock through private output storage. */
export function agentRekeyAction(invoke: Invoke = runNodeAgent) {
  return async (raw: unknown, store: Store, signal?: AbortSignal) => {
    const parsed = AgentRekeyInput.safeParse(raw);
    if (!parsed.success) {
      return AgentRekeyOutcome.parse({
        rekeyed: false,
        error_code: "node.agent_rekey_unavailable",
      });
    }
    const input = parsed.data;
    const worker = `\n[swamp_worker]\nnode_id = ${input.node_id}\nuser = ${
      JSON.stringify(input.worker_user)
    }\n`;
    if (!input.configuration.configuration.endsWith(worker)) {
      return AgentRekeyOutcome.parse({
        rekeyed: false,
        error_code: "node.agent_rekey_unavailable",
      });
    }
    if (signal?.aborted) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    try {
      const reply = await invoke({
        name: "agent.configure",
        input: {
          ...input.configuration,
          secret_hash: null,
          allow_secret_rotation: true,
          // Existing root-owned worker files are validated and preserved.
          worker_credentials: null,
        },
        pin: input.agent_sha256,
        sudo: true,
        mutates: true,
        deadlineMs: 420000,
        maxOutputBytes: 65536,
      }, signal);
      const refusal = z.object({ error_code: Refusal }).strict().safeParse(
        reply,
      );
      if (refusal.success) {
        return AgentRekeyOutcome.parse({ rekeyed: false, ...refusal.data });
      }
      const configured = Configured.parse(reply);
      // The hash exists only in this effect callback and the protected HTTP body.
      await store({ secret_hash: configured.secret_hash });
      return AgentRekeyOutcome.parse({ rekeyed: true, restarted: true });
    } catch {
      throw new Error("Agent recovery has no proven private output storage");
    }
  };
}

export const rekeyAgent = agentRekeyAction();
