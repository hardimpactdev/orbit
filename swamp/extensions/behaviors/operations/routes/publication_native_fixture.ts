/** Local native fixture substitutes only HTTP and the system-command boundary. */
let activeId = "17";
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-routes.json"));
  const id = path.match(/direct-operations\/(\d+)/)?.[1];
  if (method === "GET" && id) activeId = id;
  const operation = state.operations ? state.operations[id!] : state.operation;
  const body = raw as Record<string, unknown>;
  state.calls.push(path);
  const save = () =>
    Deno.writeTextFile("native-routes.json", JSON.stringify(state));
  if (method === "GET") {
    if (state.bindingDelay) {
      state.bindAt ??= Date.now() + state.bindingDelay;
      if (Date.now() < state.bindAt) {
        await save();
        return {
          status: 409,
          body: { error: { code: "operation.run_unbound" } },
        };
      }
    }
    await save();
    return { status: 200, body: { data: operation } };
  }
  operation.status = body.status;
  operation.result = body.result ?? null;
  await save();
  if (state.loseCompletion && body.status === "succeeded") {
    state.loseCompletion = false;
    await save();
    throw new Error("lost completion reply");
  }
  return { status: 200, body: { data: operation } };
}

export async function fixtureCaddy() {
  const state = JSON.parse(await Deno.readTextFile("native-routes.json"));
  const save = () =>
    Deno.writeTextFile("native-routes.json", JSON.stringify(state));
  if (state.events) {
    state.events.push(`start-${activeId}`);
    await save();
    await new Promise((resolve) => setTimeout(resolve, 150));
    state.events.push(`end-${activeId}`);
  }
  state.publications++;
  if (state.losePublication) {
    state.losePublication = false;
    await save();
    throw new Error("lost publication reply");
  }
  await save();
  return { result: "published" };
}
export async function fixtureDns(input: { digest: string }) {
  await fixtureCaddy();
  return { outcome: "published", data: { ready: true, digest: input.digest } };
}
