export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-firewall.json"));
  state.calls.push(path);
  if (!state.bound) {
    await Deno.writeTextFile("native-firewall.json", JSON.stringify(state));
    return { status: 409, body: { error: { code: "operation.run_unbound" } } };
  }
  const body = raw as Record<string, unknown>;
  if (method !== "GET") {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
  }
  await Deno.writeTextFile("native-firewall.json", JSON.stringify(state));
  if (state.loseProjection && body?.status === "succeeded") {
    state.loseProjection = false;
    await Deno.writeTextFile("native-firewall.json", JSON.stringify(state));
    throw new Error("lost projection");
  }
  return { status: 200, body: { data: state.operation } };
}
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
