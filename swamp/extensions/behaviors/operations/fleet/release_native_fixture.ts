import { directHttp } from "../../../../tests/_support/native_direct.ts";
import type { publishObserver } from "./release_local.ts";
export const http = directHttp("fleet-native.json");
export const observer: typeof publishObserver = async (node) => {
  const state = JSON.parse(await Deno.readTextFile("fleet-native.json"));
  state.effects.push(`observer:${node.node_id}`);
  await Deno.writeTextFile("fleet-native.json", JSON.stringify(state));
  if (state.failure === node.node_id && state.uncertain) {
    throw new Error("unconfirmed bounded effect");
  }
  return { changed: false, version: node.agent!.version };
};
