import type { Http } from "../../automatic-deploy/gateway.ts";
const path = "native-websocket.json";
const load = async () => JSON.parse(await Deno.readTextFile(path));
const save = async (value: unknown) =>
  await Deno.writeTextFile(path, JSON.stringify(value));
export const fixtureHttp: Http = async (method, path, body) => {
  const state = await load();
  state.calls.push(path);
  if (!state.bound) {
    await save(state);
    return { status: 403, body: { error: { code: "operation.run_mismatch" } } };
  }
  if (path.startsWith("/api/v1/websocket-operations/")) {
    state.registryReads++;
    await save(state);
    return {
      status: 200,
      body: {
        operation: {
          ...state.operation.input,
          id: state.operation.input.registry_operation_id,
          state: "applied",
        },
      },
    };
  }
  if (method === "GET") {
    await save(state);
    return { status: 200, body: { data: state.operation } };
  }
  if (!path.endsWith("/result")) {
    throw new Error("No Gateway execution relay is allowed");
  }
  Object.assign(state.operation, body);
  await save(state);
  if (state.loseCompletion && state.operation.status === "succeeded") {
    state.loseCompletion = false;
    await save(state);
    throw new Error("lost completion response");
  }
  return { status: 200, body: { data: state.operation } };
};
