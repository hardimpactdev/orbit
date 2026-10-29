import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { Fixture } from "./test-fixture.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

type Step = Record<string, unknown>;
class RoleFixture extends Fixture {
  roleChildren = new Map<string, Record<string, unknown>>();
  admissions = 0;
  compensations: Step[] = [];
  loseRoleChildReply = false;
  constructor(plan: Step[]) {
    super();
    this.operation.kind = "node.role_add";
    this.operation.input = {
      role: "websocket",
      converge: true,
      _role_plan: plan,
    };
    this.replies.set("prepare-role", { prepared: true });
    this.replies.set("role-result", {
      role_proven: true,
      assignment_id: 12,
      created: false,
    });
    const base = this.http;
    this.http = (async (method, path, raw) => {
      if (!path.endsWith("/children")) return await base(method, path, raw);
      const body = (raw ?? {}) as Record<string, unknown>;
      this.calls.push({ path, body });
      if (method === "GET") {
        return { status: 200, body: { data: [...this.roleChildren.values()] } };
      }
      const step = [...plan, ...this.compensations].find((s) =>
        s.key === body.key
      );
      if (!step) throw new Error("Wrong role child");
      let child = this.roleChildren.get(String(body.key));
      if (!child) {
        this.admissions++;
        child = {
          id: 20 + this.admissions,
          key: step.key,
          kind: step.kind,
          node_id: step.node_id,
          status: "succeeded",
          result: {},
          error_code: null,
        };
        this.roleChildren.set(String(body.key), child);
      }
      if (this.loseRoleChildReply) {
        this.loseRoleChildReply = false;
        throw new Error("Lost role child reply");
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
const runtime = (kind = "websocket.role_converge", optional = false): Step => ({
  type: "child",
  key: "role-runtime",
  kind,
  node_id: 2,
  input: {},
  optional,
});
const firewall: Step = {
  type: "run",
  key: "role-firewall",
  transport: "agent",
  name: "firewall.rules",
  input: { desired: [], remove: [], enable: false },
  result: { matches: true },
  optional: false,
};

Deno.test("role convergence starts only the recorded integration child with worker and key", async () => {
  const f = new RoleFixture([runtime()]);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.find((c) => c.path.endsWith("/children") && c.body.key)?.body,
    { worker: "worker-1", key: "role-runtime" },
  );
  assertEquals(f.completion()?.result, {
    role_proven: true,
    assignment_id: 12,
    created: false,
  });
});

Deno.test("role child lost reply recovers the same stamped runtime on restart", async () => {
  const f = new RoleFixture([runtime()]);
  f.loseRoleChildReply = true;
  await assertRejects(() => f.execute(), Error, "Lost role child reply");
  assertEquals((await f.execute("worker-2")).status, "succeeded");
  assertEquals(f.admissions, 1);
});

Deno.test("role parent releases its lease for a waiting runtime and continues after re-entry", async () => {
  const f = new RoleFixture([runtime(), firewall]);
  f.roleChildren.set("role-runtime", {
    id: 21,
    key: "role-runtime",
    kind: "websocket.role_converge",
    node_id: 2,
    status: "waiting",
    result: null,
    error_code: null,
  });
  f.replies.set("role-firewall", { matches: true });
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.calls.find((c) => c.path.endsWith("/wait"))?.body, {
    worker: "worker-1",
    keys: ["role-runtime"],
  });
  assertEquals(f.runs(), ["node.prepare-role"]);
  f.roleChildren.get("role-runtime")!.status = "succeeded";
  assertEquals((await f.execute("worker-2")).status, "succeeded");
  assertEquals(f.runs(), [
    "node.prepare-role",
    "firewall.rules",
    "node.role-result",
  ]);
});

Deno.test("a refused existing runtime stops before later effects and keeps recorded created false", async () => {
  const f = new RoleFixture([runtime(), firewall]);
  f.roleChildren.set("role-runtime", {
    id: 21,
    key: "role-runtime",
    kind: "websocket.role_converge",
    node_id: 2,
    status: "failed",
    result: {},
    error_code: "websocket.publication_failed",
  });
  f.replies.set("role-result", {
    role_proven: true,
    assignment_id: 12,
    created: false,
    failed_step: "role-runtime",
    error_code: "websocket.publication_failed",
  });
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs(), ["node.prepare-role", "node.role-result"]);
  assertEquals(
    (f.completion()?.result as Record<string, unknown>).created,
    false,
  );
});

Deno.test("optional follow-up failure does not fail the role", async () => {
  const f = new RoleFixture([runtime("fleet.cli_converge", true)]);
  f.roleChildren.set("role-runtime", {
    id: 21,
    key: "role-runtime",
    kind: "fleet.cli_converge",
    node_id: 2,
    status: "failed",
    result: {},
    error_code: "fleet.cli_unavailable",
  });
  f.replies.set("role-result", {
    role_proven: true,
    assignment_id: 12,
    created: false,
    follow_up: "role-runtime:fleet.cli_unavailable",
  });
  assertEquals((await f.execute()).status, "succeeded");
});

Deno.test("unchanged VPN PHP omits its conditional private DNS child", async () => {
  const php: Step = {
    type: "run",
    key: "role-php",
    transport: "agent",
    name: "php.install-build",
    input: {},
    result: {},
    optional: false,
  };
  const dns = {
    ...runtime("route.private_dns_publish"),
    key: "role-private-dns",
    input: { reason: "vpn-php" },
    when: { key: "role-php", result: { outcome: "applied" } },
  };
  const f = new RoleFixture([php, dns]);
  f.replies.set("role-php", { outcome: "current" });
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.admissions, 0);
});

Deno.test("role local effect lost reply reuses the target receipt on restart", async () => {
  const f = new RoleFixture([firewall]);
  f.replies.set("role-firewall", { matches: true });
  f.lostRun = "role-firewall";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals((await f.execute("worker-2")).status, "succeeded");
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.key === "role-firewall"
    ).length,
    1,
  );
});

Deno.test("Router candidate convergence consumes assignment intent and leaves activation to the Cluster", async () => {
  const f = new RoleFixture([runtime("route.caddy_converge")]);
  f.operation.kind = "node.role_converge";
  f.operation.input = {
    assignment_id: 12,
    _role_plan: [runtime("route.caddy_converge")],
  };
  assertEquals((await f.execute()).status, "succeeded");
  const prepare = f.calls.find((c) => c.body.name === "node.prepare-role")!;
  assertEquals(prepare.body.input, { assignment_id: 12 });
  assertEquals(f.runs(), ["node.prepare-role", "node.role-result"]);
  assertEquals(f.completion()?.result, {
    role_proven: true,
    assignment_id: 12,
    created: false,
  });
});

Deno.test("Router removal follows its parent plan using the exact removal payload", async () => {
  const caddy = {
    ...runtime("route.caddy_converge"),
    key: "role-caddy",
    input: { reason: "router-remove" },
  };
  const f = new RoleFixture([caddy, firewall]);
  f.operation.kind = "node.role_remove";
  f.operation.input = {
    role: "router",
    purge_data: false,
    offline: false,
    _role_plan: [caddy, firewall],
  };
  f.replies.set("role-firewall", { matches: true });
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.find((c) => c.body.name === "node.prepare-role")!.body.input,
    { role: "router", purge_data: false, offline: false },
  );
  assertEquals(f.runs(), [
    "node.prepare-role",
    "firewall.rules",
    "node.role-result",
  ]);
  assertEquals(f.admissions, 1);
});

Deno.test("last-role SSH recovery refusal prevents successful removal", async () => {
  const recovery = { ...firewall, key: "role-public-ssh" };
  const f = new RoleFixture([runtime("websocket.role_remove"), recovery]);
  f.operation.kind = "node.role_remove";
  f.operation.input = {
    role: "websocket",
    purge_data: false,
    offline: false,
    _role_plan: [runtime("websocket.role_remove"), recovery],
  };
  f.replies.set("role-public-ssh", { error_code: "firewall.recovery_failed" });
  f.replies.set("role-result", {
    role_proven: true,
    assignment_id: 12,
    created: false,
    error_code: "firewall.recovery_failed",
    failed_step: "role-public-ssh",
  });
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.completion()?.error_code, "firewall.recovery_failed");
});

Deno.test("relocation parent admits only its held source stage before finishing", async () => {
  const source = {
    ...runtime("node.role_relocate_source"),
    key: "role-relocation-source",
    node_id: 3,
    input: { assignment_id: 12 },
  };
  const f = new RoleFixture([source]);
  f.operation.kind = "node.role_relocate";
  f.operation.input = {
    role: "websocket",
    source_node_id: 3,
    target_node_id: 2,
    _role_plan: [source],
  };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.find((c) => c.body.name === "node.prepare-role")!.body.input,
    { role: "websocket", converge: true },
  );
  assertEquals(f.runs(), ["node.prepare-role", "node.role-result"]);
  assertEquals(f.admissions, 1);
});

Deno.test("relocation source yields without withdrawal while the Gateway DNS deadline is pending", async () => {
  const target = {
    ...runtime("node.role_relocate_target"),
    key: "role-relocation-target",
    node_id: 3,
    input: { assignment_id: 12 },
  };
  const withdrawal = {
    ...runtime("websocket.role_remove"),
    input: { purge_data: false, offline: false },
  };
  const f = new RoleFixture([target, withdrawal]);
  f.operation.kind = "node.role_relocate_source";
  f.operation.input = { assignment_id: 12, _role_plan: [target, withdrawal] };
  f.operation.execution_context = {
    descriptors: [],
    result: { role_wait_until: Date.now() + 31_000 },
  };
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.admissions, 1);
  assertEquals(f.completion(), undefined);
  f.operation.execution_context = {
    descriptors: [],
    result: { role_wait_until: 0 },
  };
  assertEquals((await f.execute("worker-2")).status, "succeeded");
  assertEquals(f.admissions, 2);
});

Deno.test("relocation destination transfers the assignment before admitting its integration runtime", async () => {
  const transfer = {
    type: "run",
    key: "role-transfer",
    transport: "gateway",
    name: "node.transfer-role",
    input: {},
    result: { transferred: true },
    optional: false,
  };
  const f = new RoleFixture([transfer, runtime()]);
  f.operation.kind = "node.role_relocate_target";
  f.operation.input = { assignment_id: 12, _role_plan: [transfer, runtime()] };
  f.replies.set("role-transfer", { transferred: true });
  assertEquals((await f.execute()).status, "succeeded");
  const effects = f.calls.filter((c) =>
    c.body.name === "node.transfer-role" ||
    c.path.endsWith("/children") && c.body.key
  );
  assertEquals(effects.map((c) => c.body.name ?? c.body.key), [
    "node.transfer-role",
    "role-runtime",
  ]);
});

Deno.test("first-role refusal starts the recorded peer compensation before final failure", async () => {
  const f = new RoleFixture([runtime()]);
  f.operation.execution_context = {
    descriptors: [],
    result: { role_rollback_required: true },
  };
  f.roleChildren.set("role-runtime", {
    id: 21,
    key: "role-runtime",
    kind: "websocket.role_converge",
    node_id: 2,
    status: "failed",
    result: {},
    error_code: "websocket.publication_failed",
  });
  f.compensations = [{
    key: "role-network-rollback",
    kind: "node.network_rollback",
    node_id: 2,
    input: {},
  }];
  f.replies.set("role-result", {
    role_proven: true,
    assignment_id: 12,
    created: false,
    error_code: "websocket.publication_failed",
    failed_step: "role-runtime",
  });

  assertEquals((await f.execute()).status, "failed");

  assertEquals(
    f.roleChildren.get("role-network-rollback")?.kind,
    "node.network_rollback",
  );
  assertEquals(f.runs(), ["node.prepare-role", "node.role-result"]);
});

Deno.test("cancellation restores the peer through a child and runs no further parent effect", async () => {
  const f = new RoleFixture([runtime()]);
  f.operation.cancel_requested = true;
  f.operation.steps = [{ name: "prepare-role", status: "succeeded" }];
  f.operation.step_results["prepare-role"] = { prepared: true };
  f.operation.execution_context = {
    descriptors: [],
    result: { role_rollback_required: true },
  };
  f.compensations = [{
    key: "role-network-rollback",
    kind: "node.network_rollback",
    node_id: 2,
    input: {},
  }];
  const base = f.http;
  f.http = async (method, path, body) => {
    if (
      path.endsWith("/heartbeat") && f.roleChildren.has("role-network-rollback")
    ) {
      f.operation.execution_context = {
        descriptors: [],
        result: {
          role_rollback_required: true,
          role_proven: true,
          assignment_id: 12,
          created: false,
          error_code: "operation.cancelled",
          failed_step: "operation",
        },
      };
    }
    return await base(method, path, body);
  };

  assertEquals((await f.execute()).status, "cancelled");

  assertEquals(f.runs(), []);
  assertEquals(f.roleChildren.size, 1);
  assertEquals(f.completion()?.result, {
    role_proven: true,
    assignment_id: 12,
    created: false,
    error_code: "operation.cancelled",
    failed_step: "operation",
  });
});

Deno.test("CI host refusal stops before the bridge firewall changes", async () => {
  const f = new RoleFixture([
    {
      type: "run",
      key: "role-ci-runner",
      transport: "agent",
      name: "ci-runner.host",
      input: { action: "remove" },
      result: { outcome: "retained" },
      optional: false,
    },
    firewall,
  ]);
  f.operation.input.role = "ci-runner";
  f.replies.set("role-ci-runner", { error_code: "ci-runner.host_failed" });
  f.replies.set("role-result", {
    role_proven: true,
    assignment_id: 12,
    created: false,
    error_code: "ci-runner.host_failed",
    failed_step: "role-ci-runner",
  });

  assertEquals((await f.execute()).status, "failed");

  assertEquals(f.runs(), [
    "node.prepare-role",
    "ci-runner.host",
    "node.role-result",
  ]);
});

Deno.test("CI purge proof precedes firewall removal and is reused after a lost reply", async () => {
  const f = new RoleFixture([
    {
      type: "run",
      key: "role-ci-runner",
      transport: "agent",
      name: "ci-runner.host",
      input: { action: "purge" },
      result: { outcome: "removed" },
      optional: false,
    },
    firewall,
  ]);
  f.operation.input.role = "ci-runner";
  f.replies.set("role-ci-runner", { outcome: "removed" });
  f.replies.set("role-firewall", { matches: true });
  f.lostRun = "role-ci-runner";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals(f.runs().includes("firewall.rules"), false);

  assertEquals((await f.execute()).status, "succeeded");

  assertEquals(f.runs().filter((name) => name === "ci-runner.host").length, 1);
  assertEquals(f.runs().slice(-2), ["firewall.rules", "node.role-result"]);
});
