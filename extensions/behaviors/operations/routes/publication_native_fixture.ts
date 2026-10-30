/** Fake Gateway in a disposable native Swamp repository. */
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-routes.json"));
  const id = path.match(/direct-operations\/(\d+)/)?.[1];
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
  if (path.endsWith("/primitive")) {
    if (state.events) {
      state.events.push(`start-${id}`);
      await save();
      await new Promise((resolve) => setTimeout(resolve, 150));
      state.events.push(`end-${id}`);
    }
    state.publications++;
    await save();
    if (state.losePublication) {
      state.losePublication = false;
      await save();
      throw new Error("lost publication reply");
    }
    return {
      status: 200,
      body: { data: { ready: true, result: "published" } },
    };
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
