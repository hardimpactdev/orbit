import type { Http } from "../../automatic-deploy/gateway.ts";

const file = "direct-lifecycle-fixture.json";
const read = () => JSON.parse(Deno.readTextFileSync(file));
const save = (state: unknown) =>
  Deno.writeTextFileSync(file, JSON.stringify(state));
export const fixtureHttp: Http = async (method, path, body) => {
  await Promise.resolve();
  const state = read();
  state.calls.push(path);
  const runId = method === "GET"
    ? new URL(`https://fixture${path}`).searchParams.get("run_id")
    : (body as Record<string, unknown>).run_id;
  if (
    !state.bound || (typeof state.bound === "string" && state.bound !== runId)
  ) {
    save(state);
    return { status: 409, body: { error: { code: "operation.run_mismatch" } } };
  }
  // `true` simulates a run already bound by the Gateway start response.
  // The worker fixture never establishes or changes the stored binding.
  if (method !== "GET") {
    const value = body as Record<string, unknown>;
    state.operation.status = value.status;
    state.operation.result = value.result ?? null;
    if (value.status !== "running" && state.loseCompletion) {
      state.loseCompletion = false;
      save(state);
      throw new Error("lost completion reply");
    }
  }
  save(state);
  return { status: 200, body: { data: state.operation } };
};
export async function fixtureInspect(input: unknown) {
  await Promise.resolve();
  const state = read();
  if (JSON.stringify(input) !== JSON.stringify(state.operation.input)) {
    throw new Error("Inspection input changed");
  }
  state.inspections++;
  save(state);
  return state.agent;
}

export async function fixtureDiscard(input: unknown) {
  await Promise.resolve();
  const state = read();
  if (JSON.stringify(input) !== JSON.stringify(state.operation.input)) {
    throw new Error("Discard input changed");
  }
  state.discards = (state.discards ?? 0) + 1;
  save(state);
  return state.agent;
}
