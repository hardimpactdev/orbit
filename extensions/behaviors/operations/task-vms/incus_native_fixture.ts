const file = "incus-direct-fixture.json";
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.calls.push(path);
  if (!state.bound) return { status: 409, body: {} };
  if (method === "POST") {
    const body = raw as Record<string, unknown>;
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
    await Deno.writeTextFile(file, JSON.stringify(state));
    if (body.status === "succeeded" && state.loseProjection) {
      state.loseProjection = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("lost projection reply");
    }
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
}
export async function fixtureAction(
  pin: string,
  input: Record<string, unknown>,
) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.actions.push({ pin, input });
  state.applied = input;
  await Deno.writeTextFile(file, JSON.stringify(state));
  if (state.loseNode) {
    state.loseNode = false;
    await Deno.writeTextFile(file, JSON.stringify(state));
    throw new Error("lost convergent Node reply");
  }
  return { ready: true };
}
