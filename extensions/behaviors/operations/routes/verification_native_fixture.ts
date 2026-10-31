/** Disposable Gateway/action fixture; no host command or live Node. */
const file = "native-verification.json";
async function read() {
  return JSON.parse(await Deno.readTextFile(file));
}
async function save(state: unknown) {
  await Deno.writeTextFile(file, JSON.stringify(state));
}
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = await read();
  state.calls.push(path);
  if (method === "GET") {
    if (!state.bound) {
      await save(state);
      return {
        status: 409,
        body: { error: { code: "operation.run_mismatch" } },
      };
    }
    await save(state);
    return { status: 200, body: { data: state.operation } };
  }
  if (!path.endsWith("/result")) {
    throw new Error("No Gateway execution relay is allowed");
  }
  const body = raw as Record<string, unknown>;
  Object.assign(state.operation, {
    status: body.status,
    result: body.result ?? null,
  });
  await save(state);
  if (state.loseCompletion && body.status === "succeeded") {
    state.loseCompletion = false;
    await save(state);
    throw new Error("lost terminal projection");
  }
  return { status: 200, body: { data: state.operation } };
}
export async function fixtureVerify() {
  const state = await read();
  state.probes++;
  await save(state);
  return { ready: true };
}
