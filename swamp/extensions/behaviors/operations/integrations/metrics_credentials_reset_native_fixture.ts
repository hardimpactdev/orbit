import type { Http } from "../../automatic-deploy/gateway.ts";
import { directHttp } from "../../../../tests/_support/native_direct.ts";
const fixtureHttp = directHttp("native-websocket.json");
const path = "native-websocket.json";
export const http: Http = async (method, url, body) => {
  if (!url.includes("/private-material")) {
    return await fixtureHttp(method, url, body);
  }
  return {
    status: 200,
    body: {
      data: {
        credential: {
          active_password: Deno.env.get("METRICS_RESET_ACTIVE"),
          pending_password: Deno.env.get("METRICS_RESET_PENDING"),
        },
      },
    },
  };
};
export async function invoke(
  call: { address: string; action: Record<string, unknown> },
) {
  const state = JSON.parse(await Deno.readTextFile(path));
  const action = call.action as {
    operation: string;
    active_password?: string;
    pending_password?: string;
    password?: string;
  };
  if (call.address !== "10.44.0.17") throw new Error("fixture.invalid_effect");
  state.actions ??= [];
  state.actions.push(action.operation);
  if (action.operation === "apply") {
    if (
      action.active_password !== Deno.env.get("METRICS_RESET_ACTIVE") ||
      action.pending_password !== Deno.env.get("METRICS_RESET_PENDING")
    ) throw new Error("fixture.invalid_credential");
    if (state.uncertain) throw new Error(String(action.pending_password));
    state.applied = true;
  } else if (
    action.operation !== "verify" ||
    action.password !== Deno.env.get("METRICS_RESET_PENDING")
  ) throw new Error("fixture.invalid_verification");
  await Deno.writeTextFile(path, JSON.stringify(state));
  return action.operation === "apply"
    ? { changed: true }
    : { verified: state.applied === true && !state.unverified };
}
