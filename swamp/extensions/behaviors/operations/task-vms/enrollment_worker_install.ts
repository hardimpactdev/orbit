import { z } from "npm:zod@4.4.3";
import { configureNodeWorker } from "../../node-worker/install.ts";
import { commandLaunch, type NodeCommand } from "../_lib/node-commands.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import type { SystemTransport } from "../node/system_command.ts";

/** Adapt the admitted first-visit transport to the canonical installer; never select another Node. */
export function taskVmWorkerCommand(
  system: SystemTransport,
  guard: () => Promise<void>,
  signal?: AbortSignal,
): NodeCommand {
  return async (argv, stdin, user, options = {}) => {
    const activeSignal = options.signal ?? signal;
    activeSignal?.throwIfAborted();
    await guard();
    const command = commandLaunch(argv, user, null);
    const result = await system({
      ...command,
      stdin: stdin === undefined
        ? undefined
        : new TextDecoder("utf-8", { fatal: true }).decode(stdin),
      timeoutMs: options.deadlineMs ?? 60000,
    }, activeSignal);
    return {
      success: result.success,
      code: result.success ? 0 : 1,
      stdout: new TextEncoder().encode(result.stdout),
    };
  };
}

/** Call only after configureTaskVmWorker has persisted the observer hash. This is still an ACK. */
export async function installTaskVmWorker(
  guestNodeId: number,
  guard: () => Promise<void>,
  material: Pick<GatewayOperation, "withPrivateMaterial">,
  system: SystemTransport,
  signal?: AbortSignal,
  install = configureNodeWorker,
): Promise<{ configured: true }> {
  z.number().int().positive().parse(guestNodeId);
  await guard();
  try {
    await material.withPrivateMaterial(async (raw) => {
      const value = z.object({
        secret_hash: z.string().regex(/^[a-f0-9]{64}$/),
        worker_credentials: z.object({
          enrollment_token: z.string().startsWith(`orbit-node-${guestNodeId}.`),
          server_token: z.string().startsWith(
            `orbit-node-${guestNodeId}-access.`,
          ),
        }).strict(),
      }).passthrough().parse(raw);
      signal?.throwIfAborted();
      await guard();
      const result = await install(
        { node_id: guestNodeId, user: "orbit" },
        value.worker_credentials,
        taskVmWorkerCommand(system, guard, signal),
      );
      if (!result.configured) throw new Error();
    });
  } catch {
    throw new Error("task_vm.worker_bootstrap_refused");
  }
  return { configured: true };
}
