import { z } from "npm:zod@4";

/** Separate native CLI executor: serve only awaits process I/O, never plans GC. */
export async function executeRetention(
  repoDir: string,
  executable = Deno.execPath(),
  signal?: AbortSignal,
) {
  signal?.throwIfAborted();
  const controller = new AbortController();
  const stop = AbortSignal.any([
    controller.signal,
    ...(signal ? [signal] : []),
  ]);
  const child = new Deno.Command(executable, {
    args: [
      "behavior",
      "run",
      "runtime-retention-executor",
      "prune",
      "--repo-dir",
      repoDir,
      "--skip-reports",
      "--json",
    ],
    cwd: repoDir,
    env: { SWAMP_SERVER_URL: "", SWAMP_SERVE_URL: "" },
    stdin: "null",
    stdout: "null",
    stderr: "null",
  }).spawn();
  const deadline = setTimeout(() => controller.abort(), 120_000);
  let onAbort: () => void = () => {};
  const aborted = new Promise<null>((resolve) => {
    onAbort = () => resolve(null);
    stop.addEventListener("abort", onAbort, { once: true });
    if (stop.aborted) onAbort();
  });
  try {
    const result = await Promise.race([child.status, aborted]);
    if (result === null) {
      // Native Swamp catches SIGTERM. An ignored abort must still be bounded.
      try {
        child.kill("SIGTERM");
      } catch { /* already exited */ }
      let grace: ReturnType<typeof setTimeout> | undefined;
      const ended = await Promise.race([
        child.status,
        new Promise<null>((resolve) => {
          grace = setTimeout(() => resolve(null), 1_000);
        }),
      ]);
      clearTimeout(grace);
      if (ended === null) {
        try {
          child.kill("SIGKILL");
        } catch { /* already exited */ }
      }
      await child.status;
      throw new Error("runtime_retention_executor_cancelled");
    }
    if (!result.success) throw new Error("runtime_retention_executor_failed");
  } finally {
    clearTimeout(deadline);
    stop.removeEventListener("abort", onAbort);
  }
}
export const behavior = {
  type: "@nckrtl/runtime-retention",
  version: "2026.10.28.2",
  globalArguments: z.object({}),
  methods: {
    prune: {
      description:
        "Dispatch native retention to a separate process with a two-minute deadline",
      outputLifetime: "7d",
      arguments: z.object({}),
      execute: async (
        _args: Record<string, never>,
        ctx: { repoDir: string; signal?: AbortSignal },
      ) => {
        await executeRetention(ctx.repoDir, Deno.execPath(), ctx.signal);
        return { dataHandles: [] };
      },
    },
  },
};
