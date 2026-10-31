import type { Http } from "../../../automatic-deploy/gateway.ts";
import { instanceActions } from "../../processes-schedules/instance.ts";
import {
  refreshEnvironmentRuntime,
  type RuntimeActions,
  synchronizeEnvironment,
} from "../sync.ts";

const file = "environment-fixture.json";
type State = {
  status: string;
  result: unknown;
  error_code?: string;
  fail?: string;
  events: string[];
};
const read = (): State => JSON.parse(Deno.readTextFileSync(file));
function event(name: string) {
  const state = read();
  state.events.push(name);
  Deno.writeTextFileSync(file, JSON.stringify(state));
}
const plan = {
  instance_id: 31,
  node_id: 9,
  environment: "production",
  base: "/home/orbit-app-31",
  user: "orbit-app-31",
  maximum: 1048576,
  required_capacity: 2097152,
  preserve_workload_keys: true,
  runtime: {
    config: {
      user: "orbit-app-31",
      home: "/home/orbit-app-31",
      version: "8.5",
      application: "/home/orbit-app-31/current",
    },
    php: {
      user: "orbit-app-31",
      home: "/home/orbit-app-31",
      version: "8.5",
      marker: "owned",
      deadline_seconds: 30,
    },
    processes: [{
      id: 4,
      input: {
        process_id: 4,
        agent_sha256: "a".repeat(64),
        runtime: "systemd",
        name: "orbit-process-4-worker.service",
        on_demand: false,
        vite: null,
      },
    }],
  },
};
export const fixtureHttp: Http = (_method, path, body) => {
  const state = read();
  if (path.includes("/private-material")) {
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          environment_material: {
            content: 'TOKEN="' + Deno.env.get("INSTANCE_ENV_SECRET") + '"\n',
            keys: ["TOKEN"],
            owned_keys: [],
            testing: null,
          },
        },
      },
    });
  }
  if (path.endsWith("/result") || path.endsWith("/failure")) {
    Object.assign(state, body);
    Deno.writeTextFileSync(file, JSON.stringify(state));
  }
  return Promise.resolve({
    status: 200,
    body: {
      data: {
        id: 7,
        kind: "instance.sync_environment",
        node_id: 9,
        status: state.status,
        cancel_requested: false,
        input: { instance_id: 31, direct_environment: plan },
        result: state.result,
      },
    },
  });
};
export const fixtureSync: typeof synchronizeEnvironment = (
  raw,
  use,
  _invoke,
  signal,
) =>
  synchronizeEnvironment(raw, use, (call) => {
    if (call.name === "instance.inspect-environment-sync") {
      return Promise.resolve({
        preflight: { state: "ok" },
        existing: { state: "missing" },
      });
    }
    event("environment");
    return Promise.resolve({ state: "changed" });
  }, signal);
const actions: RuntimeActions = {
  config: () => {
    event("config");
    if (read().fail === "config") {
      return Promise.reject(new Error("private application output"));
    }
    return Promise.resolve({ outcome: "config_cached" });
  },
  php: () => {
    event("php");
    return Promise.resolve({ complete: true });
  },
  processes: instanceActions((name) => {
    event(name);
    return Promise.resolve({ state: "active" });
  }),
};
export const fixtureRefresh: typeof refreshEnvironmentRuntime = (
  raw,
  _actions,
  signal,
) => refreshEnvironmentRuntime(raw, actions, signal);
