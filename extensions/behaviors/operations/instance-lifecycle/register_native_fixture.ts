export async function fixtureHttp(
  method: string,
  path: string,
  body?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("register-native.json"));
  state.calls.push({ method, path, body });
  if (path.endsWith("/result")) {
    Object.assign(state.operation, body);
  } else if (path.endsWith("/failure")) {
    state.operation.status = "failed";
    state.operation.result = { error_code: "operation.native_failed" };
  }
  await Deno.writeTextFile("register-native.json", JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
}
export function fixtureRegister() {
  return Promise.resolve({
    registered: true,
    branch: "feature",
    commit: "a".repeat(40),
    detached: false,
    digest: "b".repeat(64),
    common: "/srv/apps/proof/feature/.git",
    default_branch: "main",
    inferred_slug: "proof",
  });
}
