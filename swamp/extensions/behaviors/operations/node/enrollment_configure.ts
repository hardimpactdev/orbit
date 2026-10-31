import { z } from "npm:zod@4.4.3";
import { configureAgent } from "./agent_configure_system.ts";
import { nodeWorkerCommand, type SystemTransport } from "./system_command.ts";
import {
  configureNodeWorker,
  WorkerConfig,
  type WorkerCredentials,
} from "../../node-worker/install.ts";

const Hash = z.string().regex(/^[a-f0-9]{64}$/);
export const EnrollmentConfiguration = z.object({
  node_id: z.number().int().positive(),
  agent_sha256: Hash,
  binary_changed: z.boolean(),
  configuration: z.object({
    configuration: z.string().max(65536),
    certificate: z.string().max(65536),
    unit: z.string().max(65536),
    checkouts: z.array(z.string()).max(1024),
  }).strict(),
  worker: WorkerConfig.nullable(),
}).strict().superRefine((input, ctx) => {
  if (input.worker !== null && input.worker.node_id !== input.node_id) {
    ctx.addIssue({ code: "custom", message: "Worker differs from guest Node" });
  }
});
export type EnrollmentConfiguration = z.infer<typeof EnrollmentConfiguration>;
export type EnrollmentMaterial = {
  secret_hash?: unknown;
  worker_credentials?: unknown;
};
export type EnrollmentPrivateCallback = (
  action: (
    material: EnrollmentMaterial,
    invoke: SystemTransport,
  ) => Promise<void>,
) => Promise<void>;

/** Same-root lower. The parent binds custody and supplies its admitted guest transport.
 * Store the generated observer hash before starting the guest worker, which may end
 * the bootstrap session. No Gateway, worker-name heuristic or secret resource lives here.
 */
export async function configureEnrolledNode(
  raw: EnrollmentConfiguration,
  withPrivateMaterial: EnrollmentPrivateCallback,
  storeHash: (output: { secret_hash: string }) => Promise<unknown>,
  signal?: AbortSignal,
  installWorker = configureNodeWorker,
) {
  const input = EnrollmentConfiguration.parse(raw);
  signal?.throwIfAborted();
  let result:
    | { configured: true }
    | { configured: false; error_code: string }
    | undefined;
  await withPrivateMaterial(async (material, invoke) => {
    signal?.throwIfAborted();
    const credentials = input.worker === null ? null : z.object({
      enrollment_token: z.string().min(1),
      server_token: z.string().min(1),
    }).strict().parse(material.worker_credentials);
    const configured = await configureAgent(
      {
        ...input.configuration,
        agent_sha256: input.agent_sha256,
        secret_hash: material.secret_hash ?? null,
        allow_secret_rotation: true,
        binary_changed: input.binary_changed,
        worker_credentials: credentials,
      },
      invoke,
      signal,
    );
    if (!configured.configured) {
      result = { configured: false, error_code: configured.error_code };
      return;
    }
    await storeHash({ secret_hash: Hash.parse(configured.secret_hash) });
    if (input.worker !== null) {
      await installWorker(
        input.worker,
        credentials as WorkerCredentials,
        nodeWorkerCommand(invoke, signal),
      );
    }
    result = { configured: true };
  });
  if (result === undefined) throw new Error("node.enrollment_uncertain");
  return result;
}
