import type { Http } from "../../automatic-deploy/gateway.ts";
import { agentReply, type NodeCall, runNodeAgent } from "../_lib/node-agent.ts";

async function read() {
  return JSON.parse(await Deno.readTextFile("peer-native.json"));
}
async function save(state: unknown) {
  await Deno.writeTextFile("peer-native.json", JSON.stringify(state));
}

export const fixtureHttp: Http = async (method, _path, raw) => {
  const state = await read();
  if (state.deny) {
    return { status: 403, body: { error: { code: "operation.run_mismatch" } } };
  }
  if (method === "GET") return { status: 200, body: { data: state.operation } };
  const body = raw as Record<string, unknown>;
  state.projections.push(body);
  if (state.lose && body.status === "succeeded") {
    state.lose = false;
    await save(state);
    throw new Error("Lost projection reply");
  }
  state.operation.status = body.status;
  state.operation.result = body.result ?? null;
  await save(state);
  return { status: 200, body: { data: state.operation } };
};

export async function fixtureAgent(call: NodeCall, signal?: AbortSignal) {
  const state = await read();
  state.calls.push({ name: call.name, mutates: call.mutates });
  if (state.real) {
    await save(state);
    return await runNodeAgent(call, signal);
  }
  if (state.refusal?.name === call.name) {
    await save(state);
    return agentReply(
      new TextEncoder().encode(JSON.stringify({
        ok: false,
        error: {
          code: state.refusal.code,
          message: "private native diagnostic",
        },
      })),
      false,
    );
  }
  if (call.mutates) {
    state.observed = state.healthy;
    await save(state);
    return {};
  }
  await save(state);
  return state.observed;
}
