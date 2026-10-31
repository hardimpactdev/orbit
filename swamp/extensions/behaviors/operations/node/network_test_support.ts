import type { NetworkSystem } from "./network_system.ts";
import { FirewallProgram } from "../routes/firewall-program.ts";
import { RouterLanProgram } from "../routes/router-lan.ts";
export type SystemCall = { name: string; input: Record<string, unknown> };
export function fakeNetworkSystem(
  use: (call: SystemCall) => unknown | Promise<unknown>,
): NetworkSystem {
  return async (argv, stdin) => {
    if (
      argv[0] !== "/usr/bin/python3" || argv[1] !== "-c" || argv.length !== 3
    ) throw new Error("Unexpected native program");
    const input = JSON.parse(stdin ?? "");
    const call: SystemCall = argv[2] === FirewallProgram
      ? { name: "firewall.rules", input }
      : argv[2] === RouterLanProgram
      ? { name: "firewall.router-lan", input }
      : input;
    return { success: true, stdout: JSON.stringify(await use(call)) };
  };
}
