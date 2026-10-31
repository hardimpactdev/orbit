const file = "native-firewall-inspection.json";
export async function fixtureHttp(
  method: string,
  path: string,
  body?: unknown,
) {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.calls.push(path);
  if (method === "POST") {
    const data = body as Record<string, unknown>;
    if (path.endsWith("/result")) {
      state.operation.status = data.status;
      state.operation.result = data.result ?? null;
    } else if (path.endsWith("/failure")) {
      if (
        !["failed", "cancelled", "succeeded"].includes(state.operation.status)
      ) {
        state.operation.status = state.operation.cancel_requested
          ? "cancelled"
          : "failed";
      }
    } else throw new Error("No Gateway execution endpoint is permitted");
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
}
export async function fixtureLocal(argv: string[]) {
  const state = JSON.parse(await Deno.readTextFile(file));
  const payload = JSON.parse(argv[6]);
  if (
    payload.name !== "firewall.inspect" ||
    JSON.stringify(payload.input) !==
      JSON.stringify({ expected: [], exclusive_families: [], stored: false })
  ) throw new Error("A fixed native observation is required");
  state.reads++;
  await Deno.writeTextFile(file, JSON.stringify(state));
  return JSON.stringify(state.snapshot);
}
