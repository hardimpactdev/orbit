import type { BaselineOutcome } from "./role_baseline_system.ts";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { convergeCli } from "../fleet/cli_converge_local.ts";
import type { StageAction } from "./converge_direct.ts";
import { footprintActions } from "./converge_footprint.ts";

const endpoint = Deno.env.get("ORBIT_ROLE_HTTP_FIXTURE");
const privateHttp = endpoint
  ? gatewayHttp(endpoint, "/etc/ssl/certs/ca-certificates.crt")
  : null;
const file = "native-role.json";
const read = async () => JSON.parse(await Deno.readTextFile(file));
const save = async (state: unknown) =>
  await Deno.writeTextFile(file, JSON.stringify(state));
export const fixtureHttp: Http = async (method, path, body) => {
  if (privateHttp) return await privateHttp(method, path, body);
  const state = await read();
  state.calls.push({ method, path, body });
  if (method === "POST") {
    state.operation.status = (body as { status: string }).status;
    state.operation.result = (body as { result?: unknown }).result ?? null;
  }
  await save(state);
  return { status: 200, body: { data: state.operation } };
};
export const fixtureBaseline = async (
  _snapshot: unknown,
  stage: string,
): Promise<BaselineOutcome> => {
  if (!["prerequisites", "ci-host", "firewall"].includes(stage)) {
    return { ok: true };
  }
  const state = await read();
  state.effects.push(stage);
  await save(state);
  if (state.requireInstall && !state.installed) {
    return { ok: false, error_code: "agent.version_mismatch" };
  }
  return state.refusal === stage
    ? { ok: false, error_code: "role.prerequisite_failed" }
    : { ok: true };
};
export const fixtureFootprint: StageAction = async (
  stage,
  snapshot,
  gateway,
  binaryChanged,
  signal,
) => {
  const state = await read();
  state.effects.push(stage);
  await save(state);
  if (state.uncertain === stage) {
    state.uncertain = null;
    await save(state);
    throw new Error("Uncertain role footprint");
  }
  if (state.refusal === stage) {
    return { stage, ok: false, error_code: "node.agent_install_failed" };
  }
  if (privateHttp && stage === "agent") {
    const unexpected = () =>
      Promise.reject(new Error("Unexpected role private fixture action"));
    return await footprintActions({
      system: (command) => {
        const input = JSON.parse(command.stdin ?? "{}");
        const expected = Deno.env.get("ORBIT_ROLE_PRIVATE_HASH");
        if (
          command.command !== "/usr/bin/sudo" ||
          input.allow_secret_rotation !== false ||
          input.secret_hash !== expected ||
          Object.hasOwn(input, "worker_credentials")
        ) {
          throw new Error(
            "Role private fixture received the wrong native configuration",
          );
        }
        if (Deno.env.get("ORBIT_ROLE_PRIVATE_FAILURE") === "true") {
          throw new Error(String(expected));
        }
        return Promise.resolve({
          success: true,
          stdout: JSON.stringify({
            configured: true,
            secret_hash: expected,
            restarted: false,
            unclosed: [],
          }),
        });
      },
      installWorker: (worker, credentials) => {
        if (
          worker?.node_id !== 13 || worker.user !== "nckrtl" ||
          credentials !== null
        ) throw new Error("Role worker intent differs");
        return Promise.resolve({ configured: true, changed: false });
      },
      action: unexpected,
      account: () => Promise.resolve({ home: "/home/nckrtl", group: "nckrtl" }),
      install: unexpected,
      dns: unexpected,
      residue: unexpected,
      network: unexpected,
    })(stage, snapshot, gateway, binaryChanged, signal);
  }
  if (stage === "agent-install") {
    state.installed = true;
    await save(state);
    return { stage, ok: true, binary_changed: false };
  }
  const artifact = snapshot.artifacts.find((a) => a.name === stage);
  return artifact
    ? { stage, ok: true, entry: { name: artifact.name, status: "unchanged" } }
    : { stage, ok: true };
};
export const fixtureCli: typeof convergeCli = (input, options) =>
  convergeCli(input, {
    ...options,
    invoke: async (call) => {
      const state = await read();
      state.effects.push("native-cli-install");
      state.cliInput = call;
      await save(state);
      return { installed: null, configured: true };
    },
  });
