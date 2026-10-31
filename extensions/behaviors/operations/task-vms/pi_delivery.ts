import { z } from "npm:zod@4.4.3";
import type { GatewayOperation } from "../_lib/direct.ts";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import { imageArtifact } from "./image_guest.ts";
import { piNative } from "./pi_native.ts";

export const PiDeliveryInput = z.object({
  sha256: z.string().regex(/^[a-f0-9]{64}$/),
}).strict();
type Material = Pick<GatewayOperation, "artifact">;

/** One parent step. Artifact bytes remain local and the receiver performs no agent action. */
export async function uploadTaskVmPiArtifact(
  raw: z.infer<typeof PiDeliveryInput>,
  guard: () => Promise<void>,
  material: Material,
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<{ uploaded: true; sha256: string }> {
  try {
    const { sha256 } = PiDeliveryInput.parse(raw);
    signal?.throwIfAborted();
    await guard();
    const bytes = await material.artifact("pi_artifact_file", sha256);
    await imageArtifact(bytes, sha256, async (_name, input) => {
      signal?.throwIfAborted();
      await guard();
      return await piNative("pi.receive-artifact", input, transport, signal);
    });
    return { uploaded: true, sha256 };
  } catch {
    throw new Error("task_vm.pi_artifact_failed");
  }
}
