import { z } from "npm:zod@4.4.3";
import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";
import { nativeEffect } from "./native_effect.ts";

export const DestroyInput = z.object({
  process_id: z.number().int().positive().nullable(),
  container: z.string().nullable(),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict().refine((input): boolean =>
  input.process_id === null
    ? input.container === null
    : new RegExp(`^orbit-process-${input.process_id}-[a-z0-9]+(?:-[a-z0-9]+)*$`)
      .test(input.container ?? "")
);
const Refusal = z.object({
  error_code: z.enum([
    "agent.version_mismatch",
    "process.invalid",
    "process.name_collision",
    "process.converge_failed",
    "process.remove_failed",
  ]),
}).strict();
export const DestroyResult = z.union([
  z.object({ backend: z.literal("absent") }).strict(),
  Refusal,
]);

/** The pinned agent checks every container's ownership before removing any. */
export async function destroyServer(
  raw: unknown,
  signal?: AbortSignal,
  invoke = runNodeAgent,
) {
  try {
    const input = DestroyInput.parse(raw);
    signal?.throwIfAborted();
    if (input.process_id !== null) {
      const call: NodeCall = {
        name: "process.remove",
        pin: input.agent_sha256,
        sudo: true,
        mutates: true,
        deadlineMs: 300_000,
        input: {
          process_id: input.process_id,
          runtime: "docker",
          name: input.container,
        },
      };
      const result = await invoke(call, signal);
      const refusal = Refusal.safeParse(result);
      if (refusal.success) return refusal.data;
      z.object({}).strict().parse(result);
    }
    return { backend: "absent" as const };
  } catch {
    throw new Error("database.server_destroy_outcome_unknown");
  }
}

export function serverDestroy(
  makeHttp?: Parameters<typeof nativeEffect>[4],
  invoke = runNodeAgent,
) {
  return nativeEffect(
    "database_server.destroy",
    "@nckrtl/database-server-destroy",
    DestroyResult,
    (input, _gateway, signal) => destroyServer(input, signal, invoke),
    makeHttp,
  );
}
export const behavior = {
  ...serverDestroy(),
  type: "@nckrtl/database-server-destroy",
};
