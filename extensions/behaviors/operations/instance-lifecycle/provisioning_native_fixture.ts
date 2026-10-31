import type { Http } from "../../automatic-deploy/gateway.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
const file = "provisioning-fixture.json";
const read = () => JSON.parse(Deno.readTextFileSync(file));
export const fixtureHttp: Http = (method, path, body) => {
  const state = read();
  state.requests.push({ method, path });
  if (method === "POST" && path.endsWith("/failure")) {
    state.operation.status = "failed";
    state.operation.error_code = "operation.native_failed";
    state.operation.result = {};
  }
  if (path.includes("private-material")) {
    Deno.writeTextFileSync(file, JSON.stringify(state));
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          git_read: { github_token: null },
          setup_programs: { steps: [] },
          environment_material: {
            content: "",
            keys: [],
            owned_keys: [],
            testing: null,
          },
        },
      },
    });
  }
  if (method === "POST" && path.endsWith("/result")) {
    const callback = body as { status: string; result: { phase?: string } };
    state.operation.status = callback.status;
    state.operation.result = callback.result;
    if (callback.result?.phase === "source") {
      const plan = state.operation.input.direct_provisioning;
      state.operation.input.environment_plan = {
        instance_id: plan.instance_id,
        node_id: plan.node_id,
        environment: plan.environment,
        user: plan.user,
        base: plan.application,
        agent_sha256: plan.agent_sha256,
        maximum: 1048576,
        required_capacity: 2097152,
        preserve_workload_keys: false,
        runtime: null,
      };
    }
  }
  Deno.writeTextFileSync(file, JSON.stringify(state));
  return Promise.resolve({ status: 200, body: { data: state.operation } });
};
export function fixtureInvoke(
  call: NodeCall,
): Promise<Record<string, unknown>> {
  const state = read();
  state.effects.push({ name: call.name, pin: call.pin, sudo: call.sudo });
  Deno.writeTextFileSync(file, JSON.stringify(state));
  const result = call.name === "account.lookup"
    ? { user: "orbit", group: "developers" }
    : call.name === "instance.inspect-clone-candidate"
    ? { source: "/srv/candidate/default", commit: "a".repeat(40) }
    : call.name.startsWith("instance.resolve-")
    ? { branch: "main", commit: "a".repeat(40) }
    : call.name.endsWith("-profile")
    ? { kind: "none" }
    : call.name === "instance.read-development-environment-template"
    ? { environment_template: "" }
    : call.name === "instance.inspect-environment-sync"
    ? { preflight: { state: "ok" }, existing: { state: "missing" } }
    : call.name === "instance.write-environment"
    ? { state: "changed" }
    : call.name === "tool.read-vp-project"
    ? { package: {}, locks: {}, workspaces: {}, npmrc: "" }
    : {};
  return Promise.resolve(result);
}

export function fixtureEngineRefused(): boolean {
  return read().engineRefused === true;
}
