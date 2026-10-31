import { directHttp } from "../../../../tests/_support/native_direct.ts";

export const fixtureHttp = directHttp("native-firewall.json");

export async function fixtureRule(input: Record<string, unknown>) {
  const state = JSON.parse(await Deno.readTextFile("native-firewall.json"));
  state.effects++;
  state.input = input;
  const lost = state.loseNode;
  state.loseNode = false;
  await Deno.writeTextFile("native-firewall.json", JSON.stringify(state));
  if (lost) throw new Error("uncertain Node reply");
  return { backend: input.operation === "remove" ? "absent" : "active" };
}
