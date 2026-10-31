import { fixtureHttp } from "./verification_native_fixture.ts";
export { fixtureHttp };
export async function fixtureLan(
  _source: string,
  input: Record<string, unknown>,
) {
  const state = JSON.parse(await Deno.readTextFile("native-verification.json"));
  state.actions ??= [];
  state.actions.push(input);
  await Deno.writeTextFile("native-verification.json", JSON.stringify(state));
  return state.lanResult ?? { matches: true };
}
