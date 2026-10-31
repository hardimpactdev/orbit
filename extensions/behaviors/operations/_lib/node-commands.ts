import type { NodeCommand } from "../../node-worker/command.ts";
import { commandOutput } from "./node-command-process.ts";
export type { NodeCommand } from "../../node-worker/command.ts";
export type CommandResult = Awaited<ReturnType<NodeCommand>>;
export type CommandOptions = NonNullable<Parameters<NodeCommand>[3]>;

/** Code-owned argv and admitted account only; no shell, env or host selector. */
export function commandLaunch(command: readonly string[], as_user: string) {
  if (
    !command.length || !command[0].startsWith("/") ||
    command.some((v) => v.includes("\0")) ||
    command[0].split("/").at(-1) === "orbit-agent" ||
    !/^[a-z_][a-z0-9_-]{0,31}$/.test(as_user)
  ) throw new Error("Invalid direct Node command");
  const args = as_user === "node" ? [...command] : [
    "/usr/bin/sudo",
    "-n",
    ...(as_user === "root" ? [] : ["-u", as_user]),
    "--",
    ...command,
  ];
  return { command: args[0], args: args.slice(1) };
}

/** The local-only runner cannot execute an effect inside a light-Node proxy unit. */
export function requireLocalWorker(cgroup: string) {
  if (
    /\/orbit-node-proxy-worker-[1-9][0-9]*\.service(?:\/|\n|$)/.test(cgroup)
  ) throw new Error("The local runner cannot execute a proxy Node effect");
}

/** Local system execution without proxy keys, SSH, credentials or Gateway HTTP changes. */
export const run: NodeCommand = async (
  command,
  stdin,
  as_user,
  options = {},
) => {
  const launch = commandLaunch(command, as_user);
  const timeout = AbortSignal.timeout(options.deadlineMs ?? 60000);
  const signal = options.signal
    ? AbortSignal.any([options.signal, timeout])
    : timeout;
  if (Deno.build.os === "linux") {
    // The pinned compiled runtime forbids direct /proc filesystem reads.
    const membership = await commandOutput(
      { command: "/usr/bin/cat", args: ["/proc/self/cgroup"] },
      undefined,
      65536,
      AbortSignal.any([signal, AbortSignal.timeout(8000)]),
    );
    if (!membership.success) {
      throw new Error("Node worker membership unavailable");
    }
    requireLocalWorker(new TextDecoder().decode(membership.stdout));
  }
  return await commandOutput(
    launch,
    stdin,
    options.maxOutputBytes ?? 4 * 1024 * 1024,
    signal,
  );
};
