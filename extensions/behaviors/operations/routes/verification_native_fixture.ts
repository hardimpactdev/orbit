import { directHttp } from "../../../../tests/_support/native_direct.ts";

export const fixtureHttp = directHttp("native-verification.json");

export async function fixtureVerify() {
  const state = JSON.parse(await Deno.readTextFile("native-verification.json"));
  state.probes++;
  await Deno.writeTextFile("native-verification.json", JSON.stringify(state));
  return { ready: true };
}

export async function fixtureAgent(call: unknown) {
  const state = await read();
  state.actions.push(call);
  await save(state);
  return state.agentResult;
}
