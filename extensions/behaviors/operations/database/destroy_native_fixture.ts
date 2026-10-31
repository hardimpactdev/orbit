import type { Http } from "../../automatic-deploy/gateway.ts";
import type { DatabaseSqlCall as NodeCall } from "./local_sql.ts";
const file = "native-destroy.json";
const load = () => JSON.parse(Deno.readTextFileSync(file));
const save = (state: unknown) =>
  Deno.writeTextFileSync(file, JSON.stringify(state));
export const fixtureHttp: Http = (method, path, body) => {
  const state = load();
  state.paths.push(path);
  if (path.endsWith("/failure")) {
    state.operation.status = "failed";
    state.operation.error_code = "operation.native_failed";
    state.operation.result = null;
    save(state);
    return Promise.resolve({ status: 200, body: { data: state.operation } });
  }
  if (method === "GET" && path.includes("private-material")) {
    save(state);
    const password = Deno.env.get("DESTROY_PRIVATE_SENTINEL") ?? "";
    return Promise.resolve({
      status: 200,
      body: {
        data: state.operation.kind === "database.destroy"
          ? { password }
          : { payload: { passwords: { "42": password, "43": password } } },
      },
    });
  }
  if (method === "GET") {
    state.reads = (state.reads ?? 0) + 1;
    if (state.cancelOnRead === state.reads) {
      state.operation.cancel_requested = true;
    }
    save(state);
    return Promise.resolve({ status: 200, body: { data: state.operation } });
  }
  if (state.loseProject && body?.status === "succeeded") {
    state.loseProject = false;
    save(state);
    return Promise.resolve({ status: 503, body: {} });
  }
  Object.assign(state.operation, body);
  save(state);
  return Promise.resolve({ status: 200, body: { data: state.operation } });
};
export function fixtureInvoke(call: NodeCall) {
  const state = load();
  state.effects++;
  save(state);
  if (call.name === "instance.copy-sqlite") return Promise.resolve({});
  return Promise.resolve({
    output: state.refuse && String(call.input.sql).includes("FROM mysql.db")
      ? "foreign\n"
      : "",
  });
}
