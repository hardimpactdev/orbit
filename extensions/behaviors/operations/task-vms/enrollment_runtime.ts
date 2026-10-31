import { z } from "npm:zod@4.4.3";
import { inspect } from "../../node-worker/canary.ts";
import type { GatewayOperation } from "../_lib/direct.ts";
import { ProcessInstallation } from "../processes-schedules/process-installation.ts";
import type { SystemTransport } from "../node/system_command.ts";
import { type PiCommand, piNative } from "./pi_native.ts";
import { uploadTaskVmPiArtifact } from "./pi_delivery.ts";
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
export const EnrollmentRuntimeInput = z.object({
  guest_node_id: z.number().int().positive(),
  user: z.literal("orbit"),
  agent_sha256: Digest,
  pi_sha256: Digest,
  origin: z.string().url(),
  models: z.array(z.string().min(1).max(255)).min(1).max(256),
  process: ProcessInstallation,
}).strict().superRefine((input, ctx) => {
  if (
    input.process.runtime !== "systemd" ||
    input.process.desired !== "running" ||
    input.process.on_demand ||
    input.process.agent_sha256 !== input.agent_sha256 ||
    input.process.retired !== null
  ) {
    ctx.addIssue({
      code: "custom",
      message: "The Pi Process differs from admitted runtime intent",
    });
  }
});
export type EnrollmentRuntimeIntent = z.infer<typeof EnrollmentRuntimeInput>;
type Material = Pick<GatewayOperation, "withPrivateMaterial">;
export type RuntimeProcessInstaller = (
  input: z.infer<typeof ProcessInstallation>,
  material: Material,
  transport: SystemTransport,
  signal?: AbortSignal,
) => Promise<{ ready: true; state: string } | { error_code: string }>;

/** Same-root Pi configuration and the shared Process lower action; no admission or child Operation. */
export async function configureTaskVmRuntime(
  raw: EnrollmentRuntimeIntent,
  guard: () => Promise<void>,
  material: Material,
  artifact: () => Promise<Uint8Array>,
  transport: SystemTransport,
  process: RuntimeProcessInstaller,
  signal?: AbortSignal,
  identity: typeof inspect = inspect,
): Promise<{ process_id: number; configured: true }> {
  const input = EnrollmentRuntimeInput.parse(raw);
  await guard();
  await identity({ node_id: input.guest_node_id, user: input.user });
  const system: SystemTransport = async (command, commandSignal) => {
    signal?.throwIfAborted();
    await guard();
    return await transport(command, commandSignal ?? signal);
  };
  const call = (name: string, value: Record<string, unknown>) =>
    piNative(name as PiCommand, value, system, signal);
  const current = z.object({ current: z.boolean() }).parse(
    await call("pi.inspect-artifact", {
      user: input.user,
      sha256: input.pi_sha256,
    }),
  );
  if (!current.current) {
    await uploadTaskVmPiArtifact(
      { sha256: input.pi_sha256 },
      guard,
      { artifact: () => artifact() },
      system,
      signal,
    );
    await call("pi.install-artifact", {
      user: input.user,
      sha256: input.pi_sha256,
    });
  }
  await material.withPrivateMaterial(async (raw) => {
    const credentials = z.object({ model_key: Digest, pi_token: Digest })
      .passthrough().parse(raw);
    const result = z.object({ binary: z.string(), token_file: z.string() })
      .parse(
        await call("pi.configure-runtime", {
          user: input.user,
          origin: input.origin,
          models: input.models,
          model_key: credentials.model_key,
          token: credentials.pi_token,
        }),
      );
    // Pi uses the existing enrolled account's canonical files; never adopt caller-selected paths.
    if (
      result.binary !== "/home/orbit/.local/bin/pi-server" ||
      result.token_file !== "/home/orbit/.pi/agent/orbit-token"
    ) {
      throw new Error("task_vm.runtime_failed");
    }
  });
  signal?.throwIfAborted();
  await guard();
  const installed = await process(input.process, material, system, signal);
  if (!("ready" in installed)) {
    throw new Error("task_vm.runtime_failed");
  }
  const observed = await system({
    command: "/usr/bin/sudo",
    args: [
      "-n",
      "--",
      "/usr/bin/systemctl",
      "show",
      "--property=ActiveState",
      "--value",
      "--",
      input.process.name,
    ],
    timeoutMs: 15000,
  }, signal);
  if (!observed.success || observed.stdout.trim() !== "active") {
    throw new Error("task_vm.runtime_failed");
  }
  return { process_id: input.process.process_id, configured: true };
}
