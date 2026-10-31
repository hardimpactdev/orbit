export async function record(name: string) {
  const state = JSON.parse(await Deno.readTextFile("native-cluster.json"));
  state.events.push(name);
  await Deno.writeTextFile("native-cluster.json", JSON.stringify(state));
  if (state.fail === name) throw new Error("cluster.router_failed");
  return name === "route.verify-certificate"
    ? { ready: true }
    : { matches: true };
}
export async function fixtureHttp(
  method: string,
  _path: string,
  raw?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile("native-cluster.json"));
  if (method === "GET") return { status: 200, body: { data: state.operation } };
  if (_path.endsWith("/failure")) {
    if (
      !["succeeded", "failed", "cancelled"].includes(state.operation.status)
    ) state.operation.status = "failed";
    state.failures = (state.failures ?? 0) + 1;
    await Deno.writeTextFile("native-cluster.json", JSON.stringify(state));
    return { status: 200, body: { data: state.operation } };
  }
  const body = raw as {
    status: string;
    started_at?: string;
    finished_at?: string;
    result?: { checkpoint?: string };
  };
  // Match the Gateway's shared status projection request at this HTTP boundary.
  if (
    !body.started_at ||
    (["succeeded", "failed", "cancelled"].includes(body.status) &&
      !body.finished_at)
  ) {
    return { status: 422, body: { error: { code: "validation.failed" } } };
  }
  state.operation.status = body.status;
  if (body.result?.checkpoint) state.events.push(body.result.checkpoint);
  if (body.status === "succeeded") state.events.push("record");
  await Deno.writeTextFile("native-cluster.json", JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
}
export async function invoke(_program: string, call: Record<string, unknown>) {
  const name = call.name ??
    (call.mode === "rules"
      ? "firewall.rules"
      : call.mode === "route"
      ? "firewall.route-lan"
      : "firewall.router-lan");
  return await record(String(name));
}
