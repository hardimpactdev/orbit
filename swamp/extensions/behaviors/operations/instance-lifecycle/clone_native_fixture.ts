import type { Http } from "../../automatic-deploy/gateway.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
import { fixtureHttp, fixtureInvoke } from "./provisioning_native_fixture.ts";

const file = "provisioning-fixture.json";
export const cloneHttp: Http = (method, path, body) => {
  const state = JSON.parse(Deno.readTextFileSync(file));
  state.callbacks.push({ method, path, body });
  Deno.writeTextFileSync(file, JSON.stringify(state));
  // The shared failure endpoint preserves an already recorded terminal result.
  if (
    path.endsWith("/failure") &&
    ["succeeded", "failed", "cancelled"].includes(state.operation.status)
  ) {
    return Promise.resolve({ status: 200, body: { data: state.operation } });
  }
  return fixtureHttp(method, path, body);
};
export function cloneInvoke(call: NodeCall) {
  const state = JSON.parse(Deno.readTextFileSync(file));
  if (state.fail_effect === call.name) {
    throw new Error("instance.clone_candidate_dirty");
  }
  return fixtureInvoke(call);
}
