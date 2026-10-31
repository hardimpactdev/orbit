const path = "schedule-native.json";
function read() {
  return JSON.parse(Deno.readTextFileSync(path));
}
function save(state: unknown) {
  Deno.writeTextFileSync(path, JSON.stringify(state));
}
export function http(method: string, url: string, body?: unknown) {
  const state = read();
  state.calls.push({ method, url, body });
  if (state.unbound) {
    save(state);
    return Promise.resolve({ status: 409, body: {} });
  }
  if (method === "POST") {
    const report = body as Record<string, unknown>;
    state.operation.status = report.status;
    state.operation.result = report.result ?? null;
    if (state.loseProjection && report.status === "succeeded") {
      state.loseProjection = false;
      save(state);
      throw new Error("lost committed projection reply");
    }
  }
  save(state);
  return Promise.resolve({ status: 200, body: { data: state.operation } });
}
export function action(kind: string, input: unknown) {
  const state = read();
  state.seen = { kind, input };
  state.actions++;
  save(state);
  return Promise.resolve(
    state.refuse
      ? { error_code: "process.stop_failed" }
      : { ready: true, state: "inactive" },
  );
}
