const path = "start-native.json";
function read() {
  return JSON.parse(Deno.readTextFileSync(path));
}
function save(state: unknown) {
  Deno.writeTextFileSync(path, JSON.stringify(state));
}
export function http(method: string, url: string, body?: unknown) {
  const state = read();
  state.calls.push({ method, url, body });
  state.events.push(
    method === "GET"
      ? "read"
      : `project:${(body as Record<string, unknown>).status}`,
  );
  if (method === "POST") {
    const report = body as Record<string, unknown>;
    state.operation.status = report.status;
    state.operation.result = report.result ?? null;
  }
  save(state);
  return Promise.resolve({ status: 200, body: { data: state.operation } });
}
export function invoke(
  name: string,
  input: Record<string, unknown>,
  _pin?: string,
  _signal?: AbortSignal,
): Promise<Record<string, unknown>> {
  const state = read();
  state.events.push(`${name}:${input.operation ?? ""}`);
  state.commands.push({ name, input });
  save(state);
  return Promise.resolve(
    name === "process.status"
      ? { state: "active" }
      : name === "vite.check-listener"
      ? { owned: true, ready: true }
      : {},
  );
}
export function caddy(
  command: { name: string; input: unknown },
): Promise<Record<string, unknown>> {
  const state = read();
  state.events.push("caddy.publish");
  state.caddy.push(command);
  save(state);
  return Promise.resolve(
    state.refuseCaddy
      ? { error_code: "caddy.publication_failed" }
      : { result: "published" },
  );
}
