import {
  commandOutput,
  type NodeProxy,
  proxyLaunch,
  runtimeNodeProxy,
} from "./node-proxy.ts";

export type { NodeCommand } from "../../node-worker/command.ts";
import type { NodeCommand } from "../../node-worker/command.ts";
export type CommandResult = Awaited<ReturnType<NodeCommand>>;
export type CommandOptions = NonNullable<Parameters<NodeCommand>[3]>;

/** Program and argv are reviewed source, never an Operation-provided executable. */
export function commandLaunch(
  command: readonly string[],
  as_user: string,
  proxy: NodeProxy | null,
  deadlineMs = 60000,
) {
  if (
    !command.length || !command[0].startsWith("/") ||
    command.some((v) => v.includes("\0")) ||
    command[0].split("/").at(-1) === "orbit-agent" ||
    !/^[a-z_][a-z0-9_-]{0,31}$/.test(as_user)
  ) {
    throw new Error("Invalid direct Node command");
  }
  const args = as_user === "node" ? [...command] : [
    "/usr/bin/sudo",
    "-n",
    ...(as_user === "root" ? [] : ["-u", as_user]),
    "--",
    ...command,
  ];
  return proxy
    ? proxyLaunch(proxy, args, deadlineMs)
    : { command: args[0], args: args.slice(1) };
}

/** One transport for the placed Node's own commands. No agent action or host fallback. */
export const run: NodeCommand = async (
  command,
  stdin,
  as_user,
  options = {},
) => {
  const proxy = await runtimeNodeProxy();
  const timeout = AbortSignal.timeout(options.deadlineMs ?? 60000);
  return await commandOutput(
    commandLaunch(command, as_user, proxy, options.deadlineMs ?? 60000),
    stdin,
    options.maxOutputBytes ?? 4 * 1024 * 1024,
    options.signal ? AbortSignal.any([options.signal, timeout]) : timeout,
  );
};
