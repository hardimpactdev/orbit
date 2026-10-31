import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { Fixture } from "./test-fixture.ts";

type Step = Record<string, unknown>;
class LifecycleFixture extends Fixture {
  childRows = new Map<string, Record<string, unknown>>();
  loseAdmission = false;
  compensations: Step[] = [];
  constructor(plan: Step[]) {
    super();
    this.operation.kind = "node.add";
    this.operation.input = { _node_plan: plan };
    this.replies.set("node-lifecycle-result", {
      node_plan_proven: true,
      id: 2,
      name: "dev",
      removed: true,
    });
    const base = this.http;
    this.http = (async (method, path, raw) => {
      if (!path.endsWith("/children")) return await base(method, path, raw);
      const body = (raw ?? {}) as Record<string, unknown>;
      this.calls.push({ path, body });
      if (method === "GET") {
        return {
          status: 200,
          body: {
            data: [...this.childRows.values()].map((child) => ({
              error_code: null,
              result: {},
              ...child,
            })),
          },
        };
      }
      assertEquals(Object.keys(body).sort(), ["key", "worker"]);
      const step = [...plan, ...this.compensations].find((step) =>
        step.key === body.key
      );
      if (!step) throw new Error("Unrecorded lifecycle child");
      let child = this.childRows.get(String(step.key));
      if (!child) {
        this.childAdmissions++;
        child = {
          id: 20 + this.childAdmissions,
          key: step.key,
          kind: step.kind,
          node_id: step.node_id,
          status: "succeeded",
          error_code: null,
          result: {},
        };
        this.childRows.set(String(step.key), child);
      }
      if (this.loseAdmission) {
        this.loseAdmission = false;
        throw new Error("Lost lifecycle admission reply");
      }
      return {
        status: 202,
        body: {
          data: {
            ...child,
            input: step.input,
            cancel_requested: false,
            step_results: {},
          },
        },
      };
    }) satisfies Http;
  }
}
const child = (
  key: string,
  kind: string,
  node_id = 2,
  input: Record<string, unknown> = {},
): Step => ({ type: "child", key, kind, node_id, input, optional: false });
const ssh: Step = {
  type: "run",
  key: "remove-public-ssh",
  transport: "agent",
  name: "firewall.rules",
  input: { desired: [], remove: [], enable: false },
  result: { matches: true },
  optional: false,
};

Deno.test("enrollment waits for generated Route children before beginning public SSH bootstrap", async () => {
  const f = new LifecycleFixture([
    child("add-route-7", "route.update", 4, {
      route_id: 7,
      domain: "dev.changed.test",
    }),
    child("add-begin", "node.enrollment_begin"),
    child("add-bootstrap-probe", "node.bootstrap_probe"),
  ]);
  f.operation.kind = "node.add";
  f.childRows.set("add-route-7", {
    id: 21,
    key: "add-route-7",
    kind: "route.update",
    node_id: 4,
    status: "running",
  });
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.childAdmissions, 0);
  f.childRows.get("add-route-7")!.status = "succeeded";
  f.replies.set("node-lifecycle-result", {
    node_plan_proven: true,
    enrolled: true,
  });

  assertEquals((await f.execute()).status, "succeeded");
  assertEquals([...f.childRows.keys()], [
    "add-route-7",
    "add-begin",
    "add-bootstrap-probe",
  ]);
});

Deno.test("cancelled enrollment runs only its recorded restore child", async () => {
  const f = new LifecycleFixture([
    child("add-begin", "node.enrollment_begin"),
    child("add-activate", "node.enrollment_activate"),
  ]);
  f.operation.kind = "node.add";
  f.operation.cancel_requested = true;
  f.operation.execution_context = {
    descriptors: [],
    result: { node_restore_required: true },
  };
  f.compensations = [child("add-restore", "node.enrollment_restore")];
  const base = f.http;
  f.http = async (method, path, body) => {
    if (path.endsWith("/heartbeat") && f.childRows.has("add-restore")) {
      f.operation.execution_context = {
        descriptors: [],
        result: {
          node_plan_proven: true,
          enrolled: false,
          error_code: "operation.cancelled",
          failed_step: "operation",
        },
      };
    }
    return await base(method, path, body);
  };

  assertEquals((await f.execute()).status, "cancelled");
  assertEquals([...f.childRows.keys()], ["add-restore"]);
  assertEquals(f.runs(), []);
});

Deno.test("a lost bootstrap host reply uses target proof without installing or bootstrapping again", async () => {
  const f = new LifecycleFixture([{
    type: "run",
    key: "bootstrap-host",
    transport: "bootstrap-ssh",
    name: "node.bootstrap",
    input: {
      bootstrap_host: "192.0.2.30",
      bootstrap_user: "root",
      command_input: { user: "orbit" },
    },
    result: {},
    optional: false,
  }]);
  f.operation.kind = "node.bootstrap_host";
  f.lostRun = "bootstrap-host";
  f.replies.set("bootstrap-host", {});
  f.replies.set("node-lifecycle-result", {
    node_plan_proven: true,
    bootstrapped: true,
  });
  await assertRejects(() => f.execute());

  assertEquals((await f.execute("reclaimed-worker")).status, "succeeded");
  assertEquals(f.runs().filter((name) => name === "node.bootstrap"), [
    "node.bootstrap",
  ]);
});

Deno.test("managed bootstrap identity refusal stops before base firewall changes", async () => {
  const f = new LifecycleFixture([{
    type: "run",
    key: "bootstrap-verify",
    transport: "bootstrap-ssh",
    name: "agent.status",
    input: { bootstrap_user: "orbit", command_input: {} },
    result: { architecture: "x86_64" },
    optional: false,
  }, { ...ssh, key: "bootstrap-firewall", transport: "bootstrap-ssh" }]);
  f.operation.kind = "node.bootstrap_verify";
  f.replies.set("bootstrap-verify", {
    error_code: "node.architecture_mismatch",
  });
  f.replies.set("node-lifecycle-result", {
    node_plan_proven: true,
    error_code: "node.architecture_mismatch",
    failed_step: "bootstrap-verify",
  });

  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs(), ["agent.status", "node.lifecycle-result"]);
});

Deno.test("Mac enrollment inspection runs only the recorded read", async () => {
  const f = new LifecycleFixture([{
    type: "run",
    key: "bootstrap-macos",
    transport: "bootstrap-ssh",
    name: "node.inspect-macos-enrollment",
    input: { bootstrap_user: "mini", command_input: { address: "10.44.0.3" } },
    result: { architecture: "arm64" },
    optional: false,
  }]);
  f.operation.kind = "node.macos_inspect";
  f.replies.set("bootstrap-macos", { architecture: "arm64" });
  f.replies.set("node-lifecycle-result", {
    node_plan_proven: true,
    bootstrap_verified: true,
  });

  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), [
    "node.inspect-macos-enrollment",
    "node.lifecycle-result",
  ]);
});

Deno.test("network projection executes only the serving Node plan before proving its result", async () => {
  const f = new LifecycleFixture([{
    type: "run",
    key: "network-router-lan",
    optional: false,
    transport: "agent",
    name: "firewall.router-lan",
    input: { desired: [], expand: false, prune: true },
    result: { matches: true },
  }, {
    type: "run",
    key: "network-peer-dns",
    optional: false,
    transport: "agent",
    name: "wireguard.repair-peer-dns",
    input: {
      dns_server: "10.44.0.1",
      public_key: "public-key",
      domains: ["orbit"],
    },
    result: {},
  }]);
  f.operation.kind = "node.network_projection";
  f.replies.set("network-router-lan", { matches: true });
  f.replies.set("network-peer-dns", {});
  f.replies.set("node-lifecycle-result", {
    node_plan_proven: true,
    projected: true,
  });

  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), [
    "firewall.router-lan",
    "wireguard.repair-peer-dns",
    "node.lifecycle-result",
  ]);
  assertEquals(f.childRows.size, 0);
});

Deno.test("enrollment re-entry restores after a failed child step without requesting a run receipt", async () => {
  const f = new LifecycleFixture([
    child("add-network", "node.network_converge"),
  ]);
  f.operation.kind = "node.add";
  f.operation.steps.push({ name: "add-network", status: "failed" });
  f.childRows.set("add-network", {
    id: 24,
    key: "add-network",
    kind: "node.network_converge",
    node_id: 2,
    status: "failed",
  });
  f.compensations = [child("add-restore", "node.enrollment_restore")];
  f.operation.execution_context = {
    descriptors: [],
    result: { node_restore_required: true },
  };
  f.replies.set("node-lifecycle-result", {
    node_plan_proven: true,
    enrolled: false,
    error_code: "vpn.peer_ssh_failed",
    failed_step: "add-network",
  });
  const base = f.http;
  f.http = async (method, path, body) => {
    if (path.includes("/receipts/")) {
      throw new Error("operation.execution_forbidden");
    }
    return await base(method, path, body);
  };

  assertEquals((await f.execute("reclaimed-worker")).status, "failed");
  assertEquals([...f.childRows.keys()], ["add-network", "add-restore"]);
  assertEquals(f.runs(), ["node.lifecycle-result"]);
});
