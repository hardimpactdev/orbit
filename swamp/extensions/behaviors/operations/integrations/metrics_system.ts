import { ipv4, LocalMetricsError, sameState } from "./metrics_policy.ts";
export { ipv4, LocalMetricsError, sameState } from "./metrics_policy.ts";
import { run } from "../_lib/node-commands.ts";
import type { SystemTransport } from "../node/system_command.ts";

/** Adapter to the canonical placed Node runner. Its owner supplies local/proxy policy. */
export const metricsTransport = async (
  input: Parameters<SystemTransport>[0] & { maxOutputBytes?: number },
  signal?: AbortSignal,
) => {
  signal?.throwIfAborted();
  const result = await run(
    [input.command, ...input.args],
    input.stdin === undefined
      ? undefined
      : new TextEncoder().encode(input.stdin),
    "node",
    {
      deadlineMs: input.timeoutMs,
      maxOutputBytes: input.maxOutputBytes ?? 65536,
    },
  );
  return {
    success: result.success,
    stdout: new TextDecoder().decode(result.stdout),
  };
};
/** Local system tools only. Inputs are argv/stdin, never shell source. */
export type RunOptions = {
  input?: string;
  signal?: AbortSignal;
  timeoutMs?: number;
  maxOutputBytes?: number;
};
export type Runner = (
  program: string,
  args: string[],
  options?: RunOptions,
) => Promise<{ code: number; stdout: string }>;
export const runMetricsProcess: Runner = async (
  program,
  args,
  options = {},
) => {
  try {
    const output = await metricsTransport({
      command: "/usr/bin/sudo",
      args: ["-n", "--", program, ...args],
      stdin: options.input,
      timeoutMs: options.timeoutMs ?? 120000,
      maxOutputBytes: options.maxOutputBytes,
    }, options.signal);
    return { code: output.success ? 0 : 1, stdout: output.stdout };
  } catch {
    throw new Error("metrics.outcome_unknown");
  }
};
export type SystemOptions = { root?: string; owner?: number };
export function metricsSystem(
  run: Runner = runMetricsProcess,
  options: SystemOptions = {},
  ownership = "metrics.filesystem_ownership",
) {
  const root = (options.root ?? "").replace(/\/$/, "");
  const path = (name: string) => root + name;
  const command = (program: string, args: string[], options?: RunOptions) =>
    run(program, args, options);
  const check = async (
    program: string,
    args: string[],
    code = "metrics.system_failed",
    options?: RunOptions,
  ) => {
    const result = await command(program, args, options);
    if ([124, 137].includes(result.code)) {
      throw new Error("metrics.outcome_unknown");
    }
    if (result.code !== 0) throw new LocalMetricsError(code);
    return result.stdout;
  };
  const info = async (name: string) => {
    const exists = await command("/usr/bin/test", ["-e", path(name)]);
    const symlink = await command("/usr/bin/test", ["-L", path(name)]);
    if (symlink.code === 0) throw new LocalMetricsError(ownership);
    if (exists.code === 1) return null;
    if (exists.code !== 0 || symlink.code !== 1) {
      throw new LocalMetricsError(ownership);
    }
    const words = (await check(
      "/usr/bin/stat",
      ["--printf=%F|%u|%a|%s", "--", path(name)],
      ownership,
    )).split("|");
    const result = {
      type: words[0],
      uid: Number(words[1]),
      mode: parseInt(words[2], 8),
      size: Number(words[3]),
    };
    if (
      words.length !== 4 || result.uid !== (options.owner ?? 0) ||
      (result.mode & 0o022) !== 0
    ) throw new LocalMetricsError(ownership);
    return result;
  };
  const parents = async (name: string) => {
    const parts = name.split("/").slice(1, -1);
    for (let n = 0; n <= parts.length; n++) {
      const parent = "/" + parts.slice(0, n).join("/");
      const item = await info(parent);
      if (item && item.type !== "directory") {
        throw new LocalMetricsError(ownership);
      }
    }
  };
  const read = async (name: string, marker?: string, privateFile = false) => {
    await parents(name);
    const item = await info(name);
    if (!item) return null;
    if (
      item.type !== "regular file" || item.size > 65536 ||
      (privateFile && (item.mode & 0o077) !== 0)
    ) throw new LocalMetricsError(ownership);
    const value = await check("/usr/bin/cat", ["--", path(name)], ownership);
    if (marker && !value.startsWith(marker)) {
      throw new LocalMetricsError(ownership);
    }
    return value;
  };
  const mkdir = async (name: string, mode = "0755") => {
    await parents(name + "/child");
    await check("/usr/bin/mkdir", ["-p", "-m", mode, "--", path(name)]);
  };
  const write = async (name: string, value: string, mode = "0644") => {
    await parents(name);
    await info(name);
    const candidate = name + ".orbit-" + crypto.randomUUID();
    try {
      await check(
        "/usr/bin/install",
        ["-m", mode, "/dev/stdin", path(candidate)],
        "metrics.file_publication_failed",
        { input: value },
      );
      await check(
        "/usr/bin/mv",
        ["-T", "--", path(candidate), path(name)],
        "metrics.file_publication_failed",
      );
    } finally {
      await command("/usr/bin/rm", ["-f", "--", path(candidate)]);
    }
  };
  const remove = async (name: string) => {
    await parents(name);
    await info(name);
    await check("/usr/bin/rm", ["-f", "--", path(name)]);
  };
  const download = async (name: string, url: string, checksum: string) => {
    await parents(name);
    await info(name);
    const candidate = name + ".orbit-" + crypto.randomUUID();
    try {
      await check("/usr/bin/curl", [
        "--fail",
        "--silent",
        "--show-error",
        "--location",
        "--proto",
        "=https",
        "--max-time",
        "120",
        "--max-filesize",
        "134217728",
        "--output",
        path(candidate),
        url,
      ], "metrics.binary_download_failed");
      if (
        (await check("/usr/bin/sha256sum", ["--", path(candidate)])).split(
          /\s/,
        )[0] !== checksum
      ) throw new LocalMetricsError("metrics.checksum_mismatch");
      await check("/usr/bin/chmod", ["0755", "--", path(candidate)]);
      await check("/usr/bin/mv", ["-T", "--", path(candidate), path(name)]);
    } finally {
      await command("/usr/bin/rm", ["-f", "--", path(candidate)]);
    }
  };
  return {
    path,
    command,
    check,
    info,
    parents,
    read,
    mkdir,
    write,
    remove,
    download,
  };
}
