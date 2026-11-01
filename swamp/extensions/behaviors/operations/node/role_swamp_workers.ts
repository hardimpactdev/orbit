import { z } from "npm:zod@4.4.3";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const Identity = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
const Worker = z.string().regex(/^[a-z]$/);
const Workers = z.array(Worker).min(1).max(4).refine(
  (workers) => new Set(workers).size === workers.length,
);

/** The swamp role's workers: two drainable units on the swamp Node that reach serve on loopback. */
export const SwampWorkers = Identity.extend({
  role: z.literal("swamp"),
  assignment_id: z.number().int().positive(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  workers: Workers,
  concurrency: z.number().int().min(1).max(64),
}).strict();
export const SwampWorkersRemoval = Identity.extend({
  role: z.literal("swamp"),
  workers: Workers,
  purge_data: z.boolean(),
}).strict();


type Outcome = { completed: true; changed?: boolean } | { error_code: string };
const Result = z.union([
  z.object({ completed: z.literal(true), changed: z.boolean() }).strict(),
  z.object({ error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/) }).strict(),
]);

/** The root reads `scripts/swamp_workers.py` from its extension and passes its text here. */
async function invoke(
  operation: "converge" | "remove",
  data: unknown,
  program: string,
  transport: SystemTransport,
  signal?: AbortSignal,
): Promise<Outcome> {
  signal?.throwIfAborted();
  const result = await transport({
    command: "/usr/bin/sudo",
    args: ["-n", "--", "/usr/bin/python3", "-I", "-c", program],
    stdin: JSON.stringify({ operation, data }),
    timeoutMs: 60000,
  }, signal);
  if (!result.success) throw new Error("Swamp worker outcome is uncertain");
  return Result.parse(JSON.parse(result.stdout));
}

export async function convergeSwampWorkers(
  raw: unknown,
  program: string,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
): Promise<Outcome> {
  return await invoke("converge", SwampWorkers.parse(raw), program, transport, signal);
}

export async function removeSwampWorkers(
  raw: unknown,
  program: string,
  signal?: AbortSignal,
  transport: SystemTransport = localSystemCommand,
): Promise<Outcome> {
  return await invoke("remove", SwampWorkersRemoval.parse(raw), program, transport, signal);
}
