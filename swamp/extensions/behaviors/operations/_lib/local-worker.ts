/** Legacy local executors must refuse proxy placement before reading or changing host state. */
export function assertLocalExecution() {
  if (Deno.build.os !== "linux") return;
  // Fixed and bounded subprocess: the compiled runtime forbids direct /proc reads.
  const result = new Deno.Command("/usr/bin/timeout", {
    args: [
      "--kill-after=1s",
      "8s",
      "/usr/bin/head",
      "-c",
      "65537",
      "/proc/self/cgroup",
    ],
    stdout: "piped",
    stderr: "null",
    stdin: "null",
    clearEnv: true,
  }).outputSync();
  if (!result.success || result.stdout.length > 65536) {
    throw new Error("Node worker membership unavailable");
  }
  if (
    new TextDecoder().decode(result.stdout).includes("orbit-node-proxy-worker-")
  ) throw new Error("This executor cannot perform a proxy Node effect locally");
}

/** Existing local subprocess semantics, with the shared refusal before process creation. */
export function localCommand(
  command: string | URL,
  options?: Deno.CommandOptions,
): Deno.Command {
  assertLocalExecution();
  return new Deno.Command(command, options);
}

/** Guard each legacy filesystem effect at the point where it occurs. */
export function localFilesystem(): typeof Deno {
  assertLocalExecution();
  return Deno;
}
