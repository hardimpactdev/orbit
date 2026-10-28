/** Read-only fake Gateway, used only by the disposable native workflow test. */
export async function fixtureHttp(_method: string, path: string) {
  const state = JSON.parse(await Deno.readTextFile("native-observation.json"));
  state.calls.push(path);
  let data: unknown = { status: "recorded" };
  if (path.endsWith("/plan")) {
    data = {
      collection: path.split("/")[5],
      enabled: true,
      nodes: [2],
      probes: [{ node_id: 2, family: "node", kind: "Rows", ids: [2] }],
    };
  }
  if (!state.receipts[path]) {
    state.receipts[path] = data;
    state.reads.push(path);
  }
  if (state.interrupt && path.endsWith("/nodes/2")) {
    state.interrupt = false;
    await Deno.writeTextFile("native-observation.json", JSON.stringify(state));
    await Deno.writeTextFile("native-observation-boundary", "ready");
    await new Promise((resolve) => setTimeout(resolve, 300_000));
  }
  await Deno.writeTextFile("native-observation.json", JSON.stringify(state));
  return { status: 200, body: { data: state.receipts[path] } };
}
