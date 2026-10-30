/** Fake Gateway in a disposable native Swamp repository. */
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(
    await Deno.readTextFile("native-database-cleanup.json"),
  );
  const id = path.match(/direct-operations\/(\d+)/)?.[1];
  const operation = state.operations ? state.operations[id!] : state.operation;
  const body = raw as Record<string, unknown>;
  state.calls.push(path);
  const save = () =>
    Deno.writeTextFile("native-database-cleanup.json", JSON.stringify(state));
  if (method === "GET") {
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

/** Test-only local action; the canary uses the real worker-owner action. */
export async function fixtureLocal(input: unknown) {
  const state = JSON.parse(
    await Deno.readTextFile("native-database-cleanup.json"),
  );
  const value = input as { directory: string };
  const id = value.directory.endsWith("17".padStart(32, "0")) ? "17" : "18";
  const save = () =>
    Deno.writeTextFile("native-database-cleanup.json", JSON.stringify(state));
  if (state.events) {
    state.events.push(`start-${id}`);
    await save();
    await new Promise((resolve) => setTimeout(resolve, 150));
    state.events.push(`end-${id}`);
  }
  state.cleanups++;
  await save();
  if (state.loseCleanup) {
    state.loseCleanup = false;
    await save();
    throw new Error("lost cleanup reply");
  }
  return { backend: "absent" };
}
