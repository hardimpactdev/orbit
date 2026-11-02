import { z } from "npm:zod@4.4.3";

const Identity = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
});
const Worker = z.string().regex(/^[a-z]$/);
const Workers = z.array(Worker).min(1).max(4).refine(
  (workers) => new Set(workers).size === workers.length,
);
/** Official SSH from Node 209 to the Node that holds the role. */
const SshExecution = z.object({
  control_node_id: z.literal(209),
  identity_file: z.literal("/etc/orbit/swamp-role-worker/ssh-identity"),
  public_key: z.string().min(1),
  target: z.object({
    name: z.literal("target"),
    host: z.string().min(1),
    user: z.string().min(1),
    port: z.number().int().positive(),
    host_keys: z.array(z.string().min(1)).min(1),
  }).strict(),
}).strict();

/**
 * The swamp role's runtime: serve and its drainable workers. The Gateway renders both unit files; generated
 * `@swamp/cfgmgmt` steps apply them over SSH (role_swamp_runtime_workflow.ts), so no shipped script runs here.
 */
export const SwampWorkers = Identity.extend({
  role: z.literal("swamp"),
  assignment_id: z.number().int().positive(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  workers: Workers,
  units: z.object({ serve: z.string().min(1), worker: z.string().min(1) })
    .strict(),
  ssh_execution: SshExecution,
}).strict();
export const SwampWorkersRemoval = Identity.extend({
  role: z.literal("swamp"),
  workers: Workers,
  purge_data: z.boolean(),
}).strict();
