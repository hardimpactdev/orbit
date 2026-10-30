import { z } from "npm:zod@4.4.3";
import type { ToolIntentInput } from "./direct-contract.ts";
import { runNodeAgent } from "../_lib/node-agent.ts";

/** Policy supplies fixed commands; Linux mutations enter native agent supervision via sudo. */
export function toolAgent(invoke = runNodeAgent) {
  return async (
    name: string,
    input: Record<string, unknown>,
    node: z.infer<typeof ToolIntentInput>,
    signal?: AbortSignal,
  ): Promise<Record<string, unknown>> => {
    const prefix = node.manager === "brew"
      ? "tool.homebrew-formula-"
      : node.manager === "brew-cask"
      ? "tool.homebrew-cask-"
      : `tool.${node.manager}-`;
    const materializer = {
      composer: "tool.materialize-composer",
      vp: "tool.materialize-vp",
      brew: "tool.materialize-homebrew",
    }[node.manager as "composer" | "vp" | "brew"];
    const suffix = name.startsWith(prefix) ? name.slice(prefix.length) : "";
    const materialize = name === materializer;
    if (
      !(materialize ||
        (node.manager === "composer" &&
          name === "tool.inspect-composer-scope") ||
        (node.manager.startsWith("brew") && name === "tool.homebrew-version") ||
        [
          "version",
          "inventory",
          "candidate",
          "removal-plan",
          "install",
          "update",
          "remove",
          "adoption",
        ].includes(suffix)) ||
      (node.platform === "macos" &&
        (materialize || ["apt", "composer"].includes(node.manager)))
    ) throw new Error("The Tool command differs from its admitted manager");
    const mutates = materialize ||
      ["install", "update", "remove"].includes(suffix);
    return await invoke({
      name,
      input,
      pin: node.agent_sha256,
      sudo: node.platform === "linux" && mutates,
      mutates,
      deadlineMs: name === "tool.inspect-composer-scope" ? 30000 : 900000,
    }, signal);
  };
}
export const localTool = toolAgent();
