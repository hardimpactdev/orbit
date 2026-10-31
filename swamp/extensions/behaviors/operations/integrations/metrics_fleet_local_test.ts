import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  fleetActions,
  type Invoke,
  type NodePlan,
  Refused,
} from "./metrics_fleet_local.ts";
import { PhpRuntimeError } from "../php/runtime_local.ts";

const empty = {
  config: null,
  unit: null,
  rules: [],
  active: false,
  enabled: false,
  binary: false,
};
const disabled = {
  configuration: null,
  service_active: false,
  firewall_source: null,
};
const enabledPool =
  "[pool]\npm.status_path = /orbit-fpm-status\npm.status_listen = 10.44.0.13:9201\n";
const plan: NodePlan = {
  node_id: 13,
  address: "10.44.0.13",
  agent_sha256: "a".repeat(64),
  worker: true,
  selected: true,
  caddy_managed: false,
  fpm: true,
  service: empty,
  pools: [{
    instance_id: 41,
    inspect: { user: "orbit-app-41" },
    enabled: { configuration: { pool: enabledPool } },
    disabled: { configuration: { pool: "[pool]\n" } },
  }],
};
function fixture() {
  const calls: string[] = [];
  let pool = "[pool]\n";
  const host: Record<string, Record<string, unknown>> = {
    "metrics.node-exporter": structuredClone(disabled),
    "metrics.cadvisor": structuredClone(disabled),
    "metrics.service-runtime": structuredClone(empty),
  };
  const invoke: Invoke = ({ name, input }) =>
    Promise.resolve((() => {
      const action = (input.action ?? input) as {
        operation: string;
        state?: Record<string, unknown>;
      };
      calls.push(name + ":" + (action.operation ?? "pool"));
      if (name === "php.inspect-production-pool") {
        return { pool, fingerprint: "b".repeat(64) };
      }
      if (name === "php.converge-production-runtime") {
        pool = (input.configuration as { pool: string }).pool;
        return { published: true };
      }
      if (action.operation === "snapshot") return structuredClone(host[name]);
      if (action.operation === "actual") {
        return { actual: host[name].service_active ? "active" : "inactive" };
      }
      if (action.operation === "restore" || action.operation === "apply") {
        host[name] = structuredClone(action.state!);
      } else {host[name] = {
          configuration: action.operation === "converge" ? "managed" : null,
          service_active: action.operation === "converge",
          firewall_source: action.operation === "converge"
            ? input.metrics_address
            : null,
        };}
      return {};
    })());
  return { invoke, calls, host, pool: () => pool };
}
Deno.test("fleet local phases converge and verify exporters, FPM and service, then restore exact snapshots", async () => {
  const f = fixture(), action = fleetActions(f.invoke);
  const before = await action("snapshot", plan, "10.44.0.9");
  const initial = structuredClone(f.host);
  assertEquals(before.pools, [false]);
  assertEquals(
    f.calls.every((c) => /:snapshot$|inspect-production-pool:pool$/.test(c)),
    true,
  );
  await action("apply", plan, "10.44.0.9");
  await action("service", plan, "10.44.0.9");
  await action("verify", plan, "10.44.0.9");
  assertEquals(f.pool(), enabledPool);
  await action(
    "restore",
    plan,
    "10.44.0.9",
    before as Parameters<typeof action>[3],
  );
  assertEquals(f.host, initial);
  assertEquals(f.pool(), "[pool]\n");
});
Deno.test("unselected Node removes exporters and FPM monitoring without dropping the Node", async () => {
  const f = fixture(),
    action = fleetActions(f.invoke),
    node = { ...plan, selected: false, fpm: false };
  await action("apply", node, "10.44.0.9");
  await action("service", node, "10.44.0.9");
  await action("verify", node, "10.44.0.9");
  assertEquals(f.calls.filter((c) => c.endsWith(":remove")).length, 2);
  assertEquals(f.pool(), "[pool]\n");
});
Deno.test("confirmed lower action refusal stops before the next exporter", async () => {
  const f = fixture();
  const action = fleetActions((call, signal) =>
    call.name === "metrics.node-exporter"
      ? Promise.resolve({ error_code: "metrics.exporter_ownership_drift" })
      : f.invoke(call, signal)
  );
  await assertRejects(
    () => action("apply", plan, "10.44.0.9"),
    Refused,
    "metrics.exporter_ownership_drift",
  );
  assertEquals(f.calls, []);
});
Deno.test("uncertain native command propagates without manufacturing a confirmed failure", async () => {
  const uncertain = new Error("system command outcome unknown");
  const action = fleetActions(() => Promise.reject(uncertain));
  assertEquals(
    await action("apply", plan, "10.44.0.9").catch((e) => e),
    uncertain,
  );
});
Deno.test("FPM refused mutation is bounded; transport uncertainty stays uncertain", async () => {
  for (const code of ["php.runtime_invalid", "php.runtime_execution_failed"]) {
    const failure = new PhpRuntimeError(code);
    const action = fleetActions(() => Promise.reject(failure));
    const error = await action("service", plan, "10.44.0.9").catch((e) => e);
    assertEquals(
      error instanceof Refused,
      code !== "php.runtime_execution_failed",
    );
    assertEquals(error.code, code);
  }
});
Deno.test("verification and rollback refuse mismatched pool state", async () => {
  const f = fixture(), action = fleetActions(f.invoke);
  await assertRejects(
    () => action("verify", plan, "10.44.0.9"),
    Refused,
    "metrics.pool_unverified",
  );
  const before = await action("snapshot", plan, "10.44.0.9");
  const noChange = fleetActions((call, signal) =>
    call.name === "php.converge-production-runtime"
      ? Promise.resolve({ published: false })
      : f.invoke(call, signal)
  );
  await assertRejects(
    () =>
      noChange(
        "restore",
        plan,
        "10.44.0.9",
        { ...before, pools: [true] } as Parameters<typeof noChange>[3],
      ),
    Refused,
    "metrics.rollback_failed",
  );
});
