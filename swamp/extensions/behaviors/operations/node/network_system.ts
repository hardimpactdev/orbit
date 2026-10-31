import { localSystemCommand, systemArguments } from "./system_command.ts";
import { NETWORK_PROGRAM } from "./network_native_program.ts";
import { convergeFirewall } from "../routes/firewall-local.ts";
import { convergeRouterLan } from "../routes/router-lan.ts";
/** Direct Node system transport. Programs and argv are fixed by the owning behavior. */
export type NetworkSystem = (
  argv: readonly string[],
  stdin?: string,
  signal?: AbortSignal,
) => Promise<{ success: boolean; stdout: string }>;

export const runNetworkSystem: NetworkSystem = systemArguments((
  input,
  signal,
) =>
  localSystemCommand({
    command: "/usr/bin/sudo",
    args: ["-n", "--", input.command, ...input.args],
    stdin: input.stdin,
    timeoutMs: input.timeoutMs,
  }, signal)
);

export async function networkCommand(
  name: string,
  input: Record<string, unknown>,
  execute: NetworkSystem = runNetworkSystem,
  signal?: AbortSignal,
): Promise<unknown> {
  try {
    const program = async (
      source: string,
      data: Record<string, unknown>,
      stageSignal?: AbortSignal,
    ): Promise<Record<string, unknown>> => {
      const reply = await execute(
        ["/usr/bin/python3", "-c", source],
        JSON.stringify(data),
        stageSignal,
      );
      if (!reply.success) throw new Error();
      const value = JSON.parse(reply.stdout);
      if (value === null || typeof value !== "object" || Array.isArray(value)) {
        throw new Error();
      }
      return value;
    };
    if (name === "firewall.rules") {
      return await convergeFirewall(input, program, signal);
    }
    if (name === "firewall.router-lan") {
      return await convergeRouterLan(input, program, signal);
    }
    const result = await execute(
      ["/usr/bin/python3", "-c", NETWORK_PROGRAM],
      JSON.stringify({ name, input }),
      signal,
    );
    if (!result.success) throw new Error();
    return JSON.parse(result.stdout);
  } catch {
    throw new Error("The native network system outcome is uncertain");
  }
}
