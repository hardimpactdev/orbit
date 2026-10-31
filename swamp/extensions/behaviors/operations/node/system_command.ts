import type { NodeCommand } from "../../node-worker/command.ts";
import { run } from "../_lib/node-commands.ts";

/** Internal transport for code-owned programs. Never accept command source from an intent. */
export type SystemCommand = {
  command: string;
  args: readonly string[];
  stdin?: string;
  timeoutMs: number;
  maxOutputBytes?: number;
};
export type SystemOutput = { success: boolean; stdout: string };
export type SystemTransport = (
  command: SystemCommand,
  signal?: AbortSignal,
) => Promise<SystemOutput>;

/** The placement-aware shared runner selects local or pinned SSH execution. */
export const localSystemCommand: SystemTransport = async (input, signal) => {
  signal?.throwIfAborted();
  const maxOutputBytes = input.maxOutputBytes ?? 65536;
  if (
    !Number.isSafeInteger(maxOutputBytes) || maxOutputBytes < 1 ||
    maxOutputBytes > 64 * 1024 * 1024
  ) {
    throw new Error(
      "System command output bound must be between 1 byte and 64 MiB",
    );
  }
  try {
    const result = await run(
      [input.command, ...input.args],
      input.stdin === undefined
        ? undefined
        : new TextEncoder().encode(input.stdin),
      "node",
      { deadlineMs: input.timeoutMs, maxOutputBytes },
    );
    return {
      success: result.success,
      stdout: new TextDecoder().decode(result.stdout),
    };
  } catch {
    throw new Error("The system command has no confirmed outcome");
  }
};

/** Adapter shared with fixed network/bootstrap programs; private input stays on stdin. */
export function systemArguments(
  transport: SystemTransport = localSystemCommand,
) {
  return (argv: readonly string[], stdin?: string, signal?: AbortSignal) => {
    if (argv.length === 0) throw new Error("The system command is missing");
    return transport({
      command: argv[0],
      args: argv.slice(1),
      stdin,
      timeoutMs: 420000,
    }, signal);
  };
}

/** Adapt the parent-bound transport for the worker installer; custody stays in its callback. */
export function nodeWorkerCommand(
  invoke: SystemTransport,
  signal?: AbortSignal,
): NodeCommand {
  return async (argv, stdin, asUser, options = {}) => {
    if (asUser !== "root" || argv.length === 0) {
      throw new Error("swamp.node_worker_invalid");
    }
    const result = await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "--", ...argv],
      stdin: stdin === undefined ? undefined : new TextDecoder().decode(stdin),
      timeoutMs: options.deadlineMs ?? 660000,
      maxOutputBytes: options.maxOutputBytes,
    }, options.signal ?? signal);
    return {
      success: result.success,
      code: result.success ? 0 : 1,
      stdout: new TextEncoder().encode(result.stdout),
    };
  };
}
