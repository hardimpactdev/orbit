import { parse } from "jsr:@std/toml@1.0.9";
import {
  configureNodeWorker,
  WorkerConfig,
} from "../../node-worker/install.ts";
import { z } from "npm:zod@4.4.3";
import { configureAgent } from "./agent_configure_system.ts";
import { nodeWorkerCommand, type SystemTransport } from "./system_command.ts";
import { Agent, Code, Path, Sha } from "./converge_direct_contract.ts";

const Private = z.object({
  secret_hash: z.string().regex(/^[a-f0-9]{64}$/).nullable(),
  worker_credentials: z.object({
    enrollment_token: z.string(),
    server_token: z.string(),
  }).strict().nullable().optional(),
}).strict();
const Configured = z.object({
  restarted: z.boolean(),
  unclosed: z.array(z.string()),
});
/** Reuses the Node configuration boundary on an admitted local or guest transport.
 * The parent owns Node/Operation binding, agent installation and exact worker attestation.
 * Private values and the returned hash remain inside the supplied callback.
 */
export async function configureNodeAgent(
  raw: z.infer<typeof Agent>,
  agentSha256: string,
  binaryChanged: boolean,
  withPrivateMaterial: (
    action: (material: Record<string, unknown>) => Promise<void>,
  ) => Promise<void>,
  invoke: SystemTransport,
  accountFor: (user: string) => Promise<{ home: string; group: string }>,
  signal?: AbortSignal,
  installWorker = configureNodeWorker,
): Promise<{ changed: boolean; error_code?: string }> {
  signal?.throwIfAborted();
  const input = Agent.parse(raw);
  const pin = Sha.parse(agentSha256);
  const configuration = parse(input.configuration);
  const worker = configuration.swamp_worker === undefined
    ? null
    : WorkerConfig.parse(configuration.swamp_worker);
  if (worker !== null && worker.user !== input.user) {
    throw new Error("Worker account differs from admitted Node");
  }
  const account = await accountFor(input.user);
  const root = Path.parse(
    input.apps_root ?? `${Path.parse(account.home)}/apps`,
  );
  const underHome = root.startsWith("/home/") || root.startsWith("/root/");
  const unit = input.unit.replace(
    /^ProtectHome=yes$/m,
    "ProtectHome=tmpfs" + (underHome ? `\nBindReadOnlyPaths=-${root}` : ""),
  );
  let safe: z.infer<typeof Configured> | undefined;
  let refusal: string | undefined;
  await withPrivateMaterial(async (raw) => {
    const material = Private.parse({
      secret_hash: raw.secret_hash,
      ...(Object.hasOwn(raw, "worker_credentials")
        ? { worker_credentials: raw.worker_credentials }
        : {}),
    });
    const result = await configureAgent(
      {
        agent_sha256: pin,
        configuration: input.configuration,
        certificate: input.certificate,
        unit,
        checkouts: input.checkouts.filter((path) =>
          path.startsWith(root + "/")
        ),
        binary_changed: binaryChanged,
        allow_secret_rotation: false,
        secret_hash: material.secret_hash,
        ...(Object.hasOwn(material, "worker_credentials")
          ? { worker_credentials: material.worker_credentials }
          : {}),
      },
      invoke,
      signal,
    );
    if (!result.configured) {
      refusal = Code.parse(result.error_code);
    } else {
      const configured = Configured.parse(result);
      const workerResult = await installWorker(
        worker,
        material.worker_credentials ?? null,
        nodeWorkerCommand(invoke, signal),
      );
      safe = {
        ...configured,
        restarted: configured.restarted || workerResult.changed,
      };
    }
  });
  if (refusal) return { changed: false, error_code: refusal };
  if (!safe) throw new Error("Agent configuration has no proven safe result");
  return { changed: binaryChanged || safe.restarted };
}
