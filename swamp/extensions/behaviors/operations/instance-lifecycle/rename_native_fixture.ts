export async function fixtureHttp(
  method: string,
  path: string,
  body?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("rename-native.json"));
  state.calls.push({ method, path, body });
  if (path.endsWith("/result")) {
    Object.assign(state.operation, body);
  } else if (path.endsWith("/failure")) {
    state.operation.status = "failed";
    state.operation.result = { error_code: "operation.native_failed" };
  }
  await Deno.writeTextFile("rename-native.json", JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
}
export function fixtureInspect() {
  return Promise.resolve({ branch: "feature" });
}
