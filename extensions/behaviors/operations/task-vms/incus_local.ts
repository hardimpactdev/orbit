import { type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";

/** One fixed primitive through the shared Node-user sudo launcher. */
export function incusAgent(invoke = runNodeAgent) {
  return async (pin: string, input: Record<string, unknown>) => {
    const call: NodeCall = {
      name: "incus.vm",
      input,
      pin,
      sudo: true,
      mutates: true,
      deadlineMs: 900000, // Existing incus.vm command deadline.
      maxOutputBytes: 16384,
    };
    return await invoke(call);
  };
}

export const localIncus = incusAgent();
