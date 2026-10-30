import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";

/** The Node user's existing passwordless sudo runs the frozen local primitive. */
export function firewallAgent(invoke = runNodeAgent) {
  return async (input: Record<string, unknown>) => {
    const { agent_sha256, ...rule } = input;
    if (
      typeof agent_sha256 !== "string" || !/^[a-f0-9]{64}$/.test(agent_sha256)
    ) {
      throw new Error("Firewall requires its admitted agent digest");
    }
    const call: NodeCall = {
      name: "firewall.rule",
      input: rule,
      pin: agent_sha256,
      sudo: true,
      mutates: true,
      deadlineMs: 120000,
      maxOutputBytes: 16384,
    };
    return await invoke(call);
  };
}
export const localRule = firewallAgent();
