/** Shared disposable HTTP fixture; never connects to a Gateway or executes work. */
export function directHttp(file: string) {
  return async (method: string, path: string, raw?: unknown) => {
    const state = JSON.parse(await Deno.readTextFile(file));
    state.calls.push(path);
    const save = () => Deno.writeTextFile(file, JSON.stringify(state));
    if (!state.bound) {
      await save();
      return {
        status: 409,
        body: { error: { code: "operation.run_mismatch" } },
      };
    }
    const body = raw as Record<string, unknown> | undefined;
    if (method !== "GET") {
      if (!path.endsWith("/result")) {
        throw new Error("No Gateway execution relay is allowed");
      }
      state.operation.status = body!.status;
      state.operation.result = body!.result ?? null;
    }
    await save();
    if (state.loseProjection && body?.status === "succeeded") {
      state.loseProjection = false;
      await save();
      throw new Error("lost projection reply");
    }
    return { status: 200, body: { data: state.operation } };
  };
}
