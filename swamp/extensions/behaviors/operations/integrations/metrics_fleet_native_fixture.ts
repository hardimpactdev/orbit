import { directHttp } from "../../../../tests/_support/native_direct.ts";
const fixtureHttp = directHttp("native-websocket.json");
import { fleetActions, type Invoke } from "./metrics_fleet_local.ts";
const path = "native-websocket.json";
const load = async () => JSON.parse(await Deno.readTextFile(path));
const save = async (s: unknown) => {
  await Deno.writeTextFile(path + ".tmp", JSON.stringify(s));
  await Deno.rename(path + ".tmp", path);
};
const append = async (path: string, value: unknown) =>
  await Deno.writeTextFile(path, JSON.stringify(value) + "\n", {
    append: true,
  });
export const http: typeof fixtureHttp = async (method, url, body) => {
  if (!url.includes("/private-material")) {
    return await fixtureHttp(method, url, body);
  }
  const password = Deno.env.get("METRICS_FIXTURE_PASSWORD");
  return {
    status: 200,
    body: {
      data: {
        fleet_runtime: {
          runtime: {
            operation: "converge",
            assignment: 3,
            configuration: {
              "/etc/orbit/metrics/grafana/admin-password": password,
            },
          },
          verify: { operation: "verify", password },
        },
      },
    },
  };
};
export const invoke: Invoke = async (call) => {
  const control = await load();
  if (call.name === "caddy.publish") {
    await append("metrics-caddy.jsonl", call.input);
    return { result: "published" };
  }
  if (["metrics.credentials", "metrics.runtime"].includes(call.name)) {
    control.runtimeCalls ??= [];
    control.runtimeCalls.push(call.name);
    await save(control);
    if (control.lost === "runtime" && call.mutates) {
      throw new Error("metrics.fixture_lost");
    }
    if (control.fail === "runtime_validation" && call.mutates) {
      return { error_code: "metrics.prometheus_configuration_invalid" };
    }
    if (control.fail === "cleanup" && call.mutates) {
      return { error_code: "metrics.container_cleanup_failed" };
    }
    return call.mutates
      ? { changed: true }
      : { verified: control.fail !== "credentials" };
  }
  const nodePath = `host-${call.input.address}.json`;
  let host;
  try {
    host = JSON.parse(await Deno.readTextFile(nodePath));
  } catch {
    host = {};
  }
  if (call.name === "php.inspect-production-pool") {
    return { pool: host.pool ?? "", fingerprint: "b".repeat(64) };
  }
  if (call.name === "php.converge-production-runtime") {
    host.pool = (call.input.configuration as { pool: string }).pool;
    await Deno.writeTextFile(nodePath, JSON.stringify(host));
    return { changed: true };
  }
  const action = (call.input.action ?? call.input) as {
    operation: string;
    state: unknown;
  };
  const key = call.name;
  if (action.operation === "snapshot") {
    return host[key] ?? (key === "metrics.service-runtime"
      ? {
        config: null,
        unit: null,
        rules: [],
        active: false,
        enabled: false,
        binary: false,
      }
      : { configuration: null, service_active: false, firewall_source: null });
  }
  if (action.operation === "actual") {
    return {
      actual: control.fail === "verify"
        ? "inactive"
        : host[key]?.service_active
        ? "active"
        : "inactive",
    };
  }
  host[key] = action.operation === "restore" || action.operation === "apply"
    ? action.state
    : {
      configuration: "owned",
      service_active: action.operation === "converge",
      firewall_source: call.input.metrics_address,
    };
  await Deno.writeTextFile(nodePath, JSON.stringify(host));
  if (control.lost === "apply") throw new Error("metrics.fixture_lost");
  if (control.fail === "apply" && action.operation === "converge") {
    return { error_code: "metrics.fixture_refused" };
  }
  return { changed: true };
};
export const local: ReturnType<typeof fleetActions> = async (
  phase,
  node,
  ...args
) => {
  const state = await load();
  await append("metrics-effects.jsonl", { node_id: node.node_id, phase });
  if (phase === "service" && node.node_id === 9 && state.freshCaddy) {
    state.operation.input.caddy_publication = state.freshCaddy;
    await save(state);
  }
  return await fleetActions(
    (call, signal) =>
      invoke(
        { ...call, input: { ...call.input, address: node.address } },
        signal,
      ),
  )(phase, node, ...args);
};

export const runtime = {
  credential: (input: { address: string; action: Record<string, unknown> }) =>
    invoke(
      { name: "metrics.credentials", input, mutates: false } as Parameters<
        Invoke
      >[0],
    ),
  runtime: (input: { address: string; action: Record<string, unknown> }) =>
    invoke(
      { name: "metrics.runtime", input, mutates: true } as Parameters<
        Invoke
      >[0],
    ),
};
