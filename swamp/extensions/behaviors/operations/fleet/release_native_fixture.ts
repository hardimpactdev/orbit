import { directHttp } from "../../../../tests/_support/native_direct.ts";
import type { publishCli, publishObserver } from "./release_local.ts";
export const http = directHttp("fleet-native.json");
export const cli: typeof publishCli = async (node) => {
  const state = JSON.parse(await Deno.readTextFile("fleet-native.json"));
  state.effects.push(`cli:${node.node_id}`);
  await Deno.writeTextFile("fleet-native.json", JSON.stringify(state));
  if (state.failure === node.node_id) {
    if (state.uncertain) throw new Error("unconfirmed bounded effect");
    return { error_code: "cli.checksum_mismatch" };
  }
  return {
    installed: node.release!.version,
    configured: true,
    version: node.release!.version,
  };
};
export const observer: typeof publishObserver = async (node) => {
  const state = JSON.parse(await Deno.readTextFile("fleet-native.json"));
  state.effects.push(`observer:${node.node_id}`);
  await Deno.writeTextFile("fleet-native.json", JSON.stringify(state));
  return { changed: false, version: node.agent!.version };
};
