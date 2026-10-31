import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import type { StageAction } from "./converge_direct.ts";
import { footprintActions } from "./converge_footprint.ts";

const file = "native-converge.json";
const endpoint = Deno.env.get("ORBIT_CONVERGE_HTTP_FIXTURE");
const privateHttp = endpoint
  ? gatewayHttp(endpoint, "/etc/ssl/certs/ca-certificates.crt")
  : null;
export const fixtureHttp: Http = async (method, path, body) => {
  if (privateHttp) return await privateHttp(method, path, body);
  const state = JSON.parse(await Deno.readTextFile(file));
  state.calls.push({ method, path, body });
  if (state.unbound) {
    state.unbound = false;
    await Deno.writeTextFile(file, JSON.stringify(state));
    return { status: 409, body: { error: { code: "operation.run_unbound" } } };
  }
  if (method === "GET" && state.foreign) state.operation.node_id = 210;
  if (method === "POST") {
    const projection = body as Record<string, unknown>;
    state.operation.status = projection.status;
    state.operation.result = projection.result ?? null;
    state.operation.error_code = projection.error_code ?? null;
    if (state.loseProjection && projection.status === "succeeded") {
      state.loseProjection = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost projection reply");
    }
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data: state.operation } };
};
export const fixtureAction: StageAction = async (
  stage,
  snapshot,
  gateway,
  binaryChanged,
  signal,
) => {
  const state = JSON.parse(await Deno.readTextFile(file));
  state.effects.push(stage);
  if (state.cancelAfter === stage) state.operation.cancel_requested = true;
  if (state.uncertain === stage) {
    state.uncertain = null;
    await Deno.writeTextFile(file, JSON.stringify(state));
    throw new Error("Uncertain Node outcome");
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  if (privateHttp && stage === "agent") {
    return await footprintActions({
      agent: (call) => {
        const expected = Deno.env.get("ORBIT_CONVERGE_PRIVATE_HASH");
        const enrollment = Deno.env.get("ORBIT_CONVERGE_PRIVATE_ENROLLMENT");
        const access = Deno.env.get("ORBIT_CONVERGE_PRIVATE_ACCESS");
        const credentials = call.input.worker_credentials as
          | Record<string, unknown>
          | undefined;
        if (
          call.name !== "agent.configure" ||
          call.input.allow_secret_rotation !== false ||
          call.input.secret_hash !== expected ||
          credentials?.enrollment_token !== enrollment ||
          credentials?.server_token !== access
        ) throw new Error("Native private fixture received the wrong intent");
        if (Deno.env.get("ORBIT_CONVERGE_PRIVATE_FAILURE") === "true") {
          throw new Error(`${expected}:${enrollment}:${access}`);
        }
        return Promise.resolve({
          secret_hash: expected,
          restarted: false,
          unclosed: [],
          diagnostic: enrollment,
        });
      },
      account: () => Promise.resolve({ home: "/home/orbit", group: "orbit" }),
      install: () => Promise.reject(new Error("Unexpected private install")),
      dns: () => Promise.reject(new Error("Unexpected private DNS")),
      residue: () => Promise.reject(new Error("Unexpected private residue")),
      network: () => Promise.reject(new Error("Unexpected private network")),
    })(stage, snapshot, gateway, binaryChanged, signal);
  }
  if (state.refusal === stage) {
    return {
      stage,
      ok: false,
      error_code: "node.tmpfiles_failed",
      failed_artifact: "tmpfiles",
    };
  }
  if (stage === "agent-install") {
    return { stage, ok: true, binary_changed: false };
  }
  const artifact = snapshot.artifacts.find((a) => a.name === stage);
  return artifact
    ? { stage, ok: true, entry: { name: artifact.name, status: "unchanged" } }
    : { stage, ok: true };
};
