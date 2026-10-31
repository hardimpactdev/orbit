import { z } from "npm:zod@4.4.3";
import type { incusGuest } from "./image_guest.ts";

/** Shared recipe supervision. Unknown reads never repeat the phase mutation. */
export async function imagePhase(
  key: { identity: string; profile: string; phase: string; execution: string },
  recipe: Record<string, unknown>,
  builder: ReturnType<typeof incusGuest>,
  artifact: () => Promise<Uint8Array>,
  sha256: string | null,
  expectedBoot: string | null,
) {
  let lastReadAt = Date.now();
  let deadline = lastReadAt + 1440000;
  const observe = async (
    boot_id: string | null,
  ): Promise<Record<string, unknown>> => {
    for (;;) {
      const remaining = Math.min(deadline, lastReadAt + 60000) - Date.now();
      if (remaining <= 0) throw new Error("task_vm.image_channel_lost");
      try {
        const observed = await builder.command(
          "image.inspect-phase",
          { key, boot_id },
          Math.min(30, Math.ceil(remaining / 1000)),
        );
        lastReadAt = Date.now();
        return observed;
      } catch (error) {
        if (
          !(error instanceof Error) ||
          error.message !== "task_vm.image_channel_lost"
        ) throw error;
        // systemd can re-exec during APT. Re-read status; never repeat a mutation.
        await new Promise((resolve) => setTimeout(resolve, 1000));
      }
    }
  };
  let observation = await observe(null);
  const boot_id = z.string().uuid().parse(observation.boot_id);
  if (expectedBoot !== null && boot_id !== expectedBoot) {
    throw new Error("task_vm.image_builder_rebooted");
  }
  if (observation.state === "missing") {
    if (sha256 !== null) {
      await builder.artifact(await artifact(), sha256);
    }
    observation = await builder.command("image.start-phase", {
      key,
      boot_id,
      recipe,
    });
    lastReadAt = Date.now();
    deadline = lastReadAt + 1440000;
  }
  // Local observation of the guest's existing bounded service, never a Gateway wait or child.
  while (observation.state === "running") {
    if (Date.now() >= deadline) throw new Error("task_vm.image_build_failed");
    await new Promise((resolve) => setTimeout(resolve, 1000));
    observation = await observe(boot_id);
  }
  if (observation.state !== "complete" || observation.boot_id !== boot_id) {
    throw new Error("task_vm.image_build_failed");
  }
  return {};
}
