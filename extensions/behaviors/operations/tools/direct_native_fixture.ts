export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-tools-direct.json"));
  state.calls.push(path);
  const body = raw as Record<string, unknown>;
  if (state.unbound) {
    await Deno.writeTextFile("native-tools-direct.json", JSON.stringify(state));
    return { status: 409, body: { error: { code: "operation.run_unbound" } } };
  }

  if (method !== "GET") {
    state.operation.status = body.status;
    state.operation.result = body.result ?? null;
  }
  await Deno.writeTextFile("native-tools-direct.json", JSON.stringify(state));
  if (state.loseProjection && body?.status === "succeeded") {
    state.loseProjection = false;
    await Deno.writeTextFile("native-tools-direct.json", JSON.stringify(state));
    throw new Error("lost projection reply");
  }
  return { status: 200, body: { data: state.operation } };
}
export async function fixtureTool(
  name: string,
  input: Record<string, unknown>,
) {
  const state = JSON.parse(await Deno.readTextFile("native-tools-direct.json"));
  let result: Record<string, unknown> = {};
  if (name.endsWith("-version")) result = { version: "3.0.0" };
  else if (name.endsWith("-inventory")) {
    result = {
      packages: state.installed === null
        ? []
        : [{ name: "jq", version: state.installed }],
      scan_complete: true,
    };
  } else if (name.endsWith("-candidate")) result = { version: "2.0.0" };
  else if (name.endsWith("-removal-plan")) {
    result = { packages: state.installed === null ? [] : ["jq"] };
  } else if (/-(install|update|remove)$/.test(name)) {
    state.effects++;
    state.installed = name.endsWith("-remove") ? null : "2.0.0";
  }
  const lost = state.loseNode && /-(install|update|remove)$/.test(name);
  if (lost) state.loseNode = false;
  state.commands.push({ name, input });
  await Deno.writeTextFile("native-tools-direct.json", JSON.stringify(state));
  if (lost) throw new Error("uncertain local Tool response");
  return result;
}
