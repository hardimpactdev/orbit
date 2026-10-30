import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Context,
  Executor,
  Operation,
} from "../../operation-runner/mod.ts";
import { metricsFleet } from "./metrics.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class FleetFixture {
  operation = Operation.parse({
    id: 120,
    kind: "metrics.enable",
    node_id: 2,
    status: "running",
    cancel_requested: false,
    step_results: {},
    input: {
      node_id: 2,
      assignment_id: 4,
      metrics_address: "10.44.0.2",
      gateway_node_id: 3,
      gateway_address: "10.44.0.3",
      dns_node_id: 8,
      remove: false,
      purge_data: false,
      unreachable: false,
      relocated: false,
      fleet: [2, 5].map((id) => ({
        node_id: id,
        name: `node-${id}`,
        address: `10.44.0.${id}`,
        selected: true,
        caddy: true,
        fpm: false,
        hosts: [],
        instance_ids: [],
      })),
    },
  });
  calls: Record<string, unknown>[] = [];
  children = new Map<string, Record<string, unknown>>();
  failures = new Map<string, string>();
  pending: string | null = null;
  lose: string | null = null;
  cancelBefore: string | null = null;
  cancelAfter: string | null = null;
  resources = new Map<string, Record<string, unknown>>();
  id = 200;
  http: Http = (_method, path, raw) => {
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, ...structuredClone(body) });
    let data: unknown;
    if (path.endsWith("/children")) {
      const key = String(body.key);
      if (this.cancelBefore === key) this.operation.cancel_requested = true;
      if (
        this.operation.cancel_requested && !this.children.has(key) &&
        !key.includes("-restore-")
      ) {
        return Promise.resolve({
          status: 422,
          body: { error: { code: "operation.child_forbidden" } },
        });
      }
      assertEquals(Object.keys(body).sort(), ["key", "worker"]);
      const intent = this.childIntent(key);
      if (!this.children.has(key)) {
        const error = this.failures.get(key);
        this.children.set(key, {
          id: ++this.id,
          ...intent,
          status: this.pending === key
            ? "running"
            : error === "operation.cancelled"
            ? "cancelled"
            : error
            ? "failed"
            : "succeeded",
          error_code: error ?? null,
          cancel_requested: false,
          steps: [],
          step_results: {},
          result: {},
        });
      }
      data = this.children.get(key);
      if (this.cancelAfter === key) this.operation.cancel_requested = true;
      if (this.lose === key) {
        this.lose = null;
        throw new Error("Lost child reply");
      }
    } else if (path.endsWith("/run")) {
      if (this.operation.cancel_requested) {
        return Promise.resolve({
          status: 409,
          body: { error: { code: "operation.cancelled" } },
        });
      }
      data = { backend: this.operation.input.remove ? "absent" : "active" };
    } else data = this.operation;
    return Promise.resolve({ status: 200, body: { data } });
  };
  context: Context = {
    globalArgs: {
      gatewayUrl: "https://gateway.example.test",
      caFile: "/unused",
    },
    readResource: (key) => Promise.resolve(this.resources.get(key) ?? null),
    writeResource: (_spec, key, value) => {
      this.resources.set(key, structuredClone(value));
      return Promise.resolve({ name: key });
    },
  };
  run() {
    return metricsFleet(
      this.operation,
      new Executor(this.http),
      "workflow-120",
      this.context,
      () => {},
    );
  }
  // The fixture Gateway resolves recorded keys; Swamp supplies no Node or child input.
  childIntent(
    key: string,
  ): { kind: string; node_id: number; input: Record<string, unknown> } {
    const p = this.operation.input;
    const nodes = p.fleet as {
      node_id: number;
      name: string;
      address: string;
      selected: boolean;
      caddy: boolean;
      fpm: boolean;
      instance_ids: number[];
    }[];
    if (key === "metrics-cancellation-boundary") {
      const node = nodes.find((n) => n.node_id === p.node_id)!;
      return {
        kind: "metrics.node_exporter_snapshot",
        node_id: node.node_id,
        input: { address: node.address, metrics_address: p.metrics_address },
      };
    }
    const snapshotKey = (component: string, id: number) =>
      component === "node_exporter" && id === p.node_id
        ? "metrics-cancellation-boundary"
        : `${component}-snapshot-${id}`;
    const stage =
      /^(node_exporter|cadvisor|service)-(snapshot|apply|restore)-(\d+)$/.exec(
        key,
      );
    if (stage) {
      const [, component, phase, id] = stage;
      const node = nodes.find((n) => n.node_id === Number(id))!;
      const input: Record<string, unknown> = {
        address: node.address,
        metrics_address: p.metrics_address,
      };
      if (component === "service") {
        Object.assign(input, {
          instance_ids: node.instance_ids,
          caddy: node.caddy,
          fpm: node.fpm,
        });
      }
      if (phase === "restore") {
        Object.assign(input, {
          source_operation_id:
            this.children.get(snapshotKey(component, Number(id)))!.id,
          source_key: snapshotKey(component, Number(id)),
          restore_key: key,
        });
      }
      return {
        kind: `metrics.${component}_${
          phase === "apply"
            ? component === "service" || node.selected ? "converge" : "remove"
            : phase
        }`,
        node_id: node.node_id,
        input,
      };
    }
    const caddy = /^service-caddy-(restore-)?(\d+)$/.exec(key);
    if (caddy) {
      return {
        kind: caddy[1]
          ? "metrics.service_caddy_restore"
          : "route.caddy_converge",
        node_id: Number(caddy[2]),
        input: caddy[1]
          ? { restore_key: `service-restore-${caddy[2]}` }
          : { reason: "metrics service projection" },
      };
    }
    if (key === "metrics-runtime") {
      const selected = nodes.filter((n) =>
        n.selected &&
        !["node_exporter", "cadvisor"].some((component) =>
          this.children.get(snapshotKey(component, n.node_id))?.status !==
            "succeeded"
        )
      );
      const services = nodes.filter((n) =>
        this.children.get(`service-apply-${n.node_id}`)?.status ===
          "succeeded" &&
        (!n.caddy ||
          this.children.get(`service-caddy-${n.node_id}`)?.status ===
            "succeeded")
      );
      return {
        kind: p.remove ? "metrics.runtime_remove" : "metrics.runtime_converge",
        node_id: Number(p.node_id),
        input: p.remove ? { purge_data: p.purge_data } : {
          assignment_id: p.assignment_id,
          targets: selected.map((n) => ({ name: n.name, address: n.address }))
            .sort((a, b) => a.name.localeCompare(b.name)),
          services,
          service_node_ids: services.map((n) => n.node_id),
        },
      };
    }
    if (key === "metrics-credentials-verified") {
      return {
        kind: "metrics.runtime_verify",
        node_id: Number(p.node_id),
        input: {},
      };
    }
    const reason = p.remove ? "metrics withdrawal" : "metrics publication";
    if (key === "publication-caddy") {
      return {
        kind: "route.caddy_converge",
        node_id: Number(p.gateway_node_id),
        input: { reason },
      };
    }
    if (key === "publication-dns") {
      return {
        kind: "route.private_dns_publish",
        node_id: Number(p.dns_node_id),
        input: { reason },
      };
    }
    if (key === "publication-certificate") {
      return {
        kind: p.remove
          ? "metrics.certificate_remove"
          : "metrics.certificate_publish",
        node_id: Number(p.gateway_node_id),
        input: {},
      };
    }
    if (key === "publication-firewall") {
      return {
        kind: "metrics.publication_firewall",
        node_id: Number(p.node_id),
        input: {
          action: {
            operation: p.remove ? "remove" : "converge",
            gateway: p.gateway_address,
          },
        },
      };
    }
    if (key === "publication-abandon") {
      return {
        kind: "metrics.publication_firewall",
        node_id: Number(p.node_id),
        input: { action: { operation: "abandon" } },
      };
    }
    throw new Error("Unknown fixture child key");
  }
  keys() {
    return [...this.children.keys()];
  }
  childInput(key: string) {
    return this.children.get(key)!.input as Record<string, unknown>;
  }
}
Deno.test("metrics snapshots each fleet stage, verifies runtime, then publishes on the Gateway and DNS Nodes", async () => {
  const f = new FleetFixture();
  assertEquals((await f.run()).status, "succeeded");
  const keys = f.keys();
  assert(
    keys.indexOf("node_exporter-snapshot-5") <
      keys.indexOf("node_exporter-apply-2"),
  );
  assert(
    keys.indexOf("cadvisor-snapshot-5") < keys.indexOf("cadvisor-apply-2"),
  );
  assert(keys.indexOf("service-snapshot-5") < keys.indexOf("service-apply-2"));
  assert(keys.indexOf("metrics-runtime") < keys.indexOf("publication-caddy"));
  assertEquals(f.children.get("publication-caddy")!.node_id, 3);
  assertEquals(f.children.get("publication-dns")!.node_id, 8);
  assertEquals(f.children.get("metrics-runtime")!.node_id, 2);
  assert(
    f.calls.filter((call) => String(call.path).endsWith("/run")).every((call) =>
      !("node_id" in call)
    ),
  );
});
Deno.test("metrics skips only an unavailable non-Metrics Node and excludes it from the runtime projection", async () => {
  const f = new FleetFixture();
  f.failures.set(
    "node_exporter-snapshot-5",
    "metrics.exporter_firewall_inactive",
  );
  assertEquals((await f.run()).status, "succeeded");
  assertEquals(f.keys().includes("node_exporter-apply-5"), false);
  assertEquals(f.keys().includes("cadvisor-snapshot-5"), false);
  assertEquals(f.childInput("metrics-runtime").targets, [{
    name: "node-2",
    address: "10.44.0.2",
  }]);
});
Deno.test("Metrics Node unavailability and remote ownership drift refuse before fleet mutation", async () => {
  for (
    const [key, code] of [[
      "metrics-cancellation-boundary",
      "metrics.exporter_firewall_inactive",
    ], ["node_exporter-snapshot-5", "metrics.exporter_ownership_drift"]]
  ) {
    const f = new FleetFixture();
    f.failures.set(key, code);
    assertEquals((await f.run()).status, "failed");
    assertEquals(f.keys().some((key) => key.includes("apply")), false);
    assertEquals(f.calls.at(-1)!.error_code, code);
  }
});
Deno.test("a later fleet failure restores the failed member and earlier mutations in reverse order", async () => {
  const f = new FleetFixture();
  f.failures.set("cadvisor-apply-5", "metrics.cadvisor_apply_failed");
  assertEquals((await f.run()).status, "failed");
  assertEquals(f.keys().filter((key) => key.includes("restore")), [
    "cadvisor-restore-5",
    "cadvisor-restore-2",
    "node_exporter-restore-5",
    "node_exporter-restore-2",
  ]);
  const input = f.childInput("cadvisor-restore-5");
  assertEquals(
    input.source_operation_id,
    f.children.get("cadvisor-snapshot-5")!.id,
  );
  assertEquals(input.source_key, "cadvisor-snapshot-5");
  assertEquals(f.keys().includes("metrics-runtime"), false);
});
Deno.test("runtime failure restores service snapshots before cAdvisor and node exporters", async () => {
  const f = new FleetFixture();
  f.failures.set("metrics-runtime", "metrics.runtime_failed");
  assertEquals((await f.run()).status, "failed");
  assertEquals(
    f.keys().filter((key) =>
      /^(service|cadvisor|node_exporter)-restore-/.test(key)
    ),
    [
      "service-restore-5",
      "service-restore-2",
      "cadvisor-restore-5",
      "cadvisor-restore-2",
      "node_exporter-restore-5",
      "node_exporter-restore-2",
    ],
  );
});
Deno.test("publication failure after runtime commit keeps the fleet projection for recovery", async () => {
  const f = new FleetFixture();
  f.failures.set("publication-caddy", "route.caddy_invalid");
  assertEquals((await f.run()).status, "failed");
  assertEquals(f.keys().some((key) => key.includes("restore")), false);
});
Deno.test("failed service mutation restores that Node and continues with healthy service targets", async () => {
  const f = new FleetFixture();
  f.failures.set("service-apply-5", "metrics.service_failed");
  assertEquals((await f.run()).status, "succeeded");
  assert(f.keys().includes("service-restore-5"));
  assertEquals(f.childInput("metrics-runtime").service_node_ids, [2]);
});
Deno.test("lost child reply resumes the same child identity and payload", async () => {
  const f = new FleetFixture();
  f.lose = "node_exporter-apply-2";
  await assertRejects(() => f.run(), Error, "Lost child reply");
  const id = f.children.get("node_exporter-apply-2")!.id;
  assertEquals((await f.run()).status, "succeeded");
  assertEquals(f.children.get("node_exporter-apply-2")!.id, id);
  const calls = f.calls.filter((call) => call.key === "node_exporter-apply-2");
  assertEquals(calls[0], calls[1]);
});
Deno.test("a running child leaves the parent unfinished until a later claim", async () => {
  const f = new FleetFixture();
  f.pending = "cadvisor-snapshot-2";
  assertEquals((await f.run()).status, "waiting");
  assertEquals(f.resources.size, 0);
  f.children.get("cadvisor-snapshot-2")!.status = "succeeded";
  assertEquals((await f.run()).status, "succeeded");
});
Deno.test("cancellation during mutation restores earlier effects and completes cancelled", async () => {
  const f = new FleetFixture();
  f.failures.set("node_exporter-apply-5", "operation.cancelled");
  assertEquals((await f.run()).status, "cancelled");
  assertEquals(f.keys().filter((key) => key.includes("restore")), [
    "node_exporter-restore-5",
    "node_exporter-restore-2",
  ]);
});
Deno.test("disable withdraws publication before removing runtimes and preserves explicit purge intent", async () => {
  const f = new FleetFixture();
  f.operation.kind = "metrics.disable";
  f.operation.input.remove = true;
  f.operation.input.purge_data = true;
  f.operation.input.fleet =
    (f.operation.input.fleet as Record<string, unknown>[]).map((node) => ({
      ...node,
      selected: false,
      caddy: false,
      fpm: false,
    }));
  assertEquals((await f.run()).status, "succeeded");
  assert(
    f.keys().indexOf("publication-caddy") <
      f.keys().indexOf("node_exporter-apply-2"),
  );
  assertEquals(
    f.children.get("node_exporter-apply-2")!.kind,
    "metrics.node_exporter_remove",
  );
  assertEquals(f.childInput("metrics-runtime"), { purge_data: true });
});

Deno.test("Gateway cancellation refusal restores only admitted mutations and survives a pending compensation", async () => {
  const f = new FleetFixture();
  f.cancelBefore = "node_exporter-apply-5";
  f.pending = "node_exporter-restore-2";
  assertEquals((await f.run()).status, "waiting");
  assertEquals(f.keys().filter((key) => key.includes("restore")), [
    "node_exporter-restore-2",
  ]);
  assertEquals(f.keys().includes("node_exporter-apply-5"), false);
  assertEquals(f.resources.size, 0);
  f.children.get("node_exporter-restore-2")!.status = "succeeded";
  assertEquals((await f.run()).status, "cancelled");
  assertEquals(
    f.calls.some((call) => String(call.path).endsWith("/run")),
    false,
  );
});
Deno.test("cancellation after a healthy service mutation restores service and Caddy through the narrow compensation child", async () => {
  const f = new FleetFixture();
  f.cancelAfter = "service-apply-2";
  assertEquals((await f.run()).status, "cancelled");
  assertEquals(
    f.children.get("service-caddy-restore-2")!.kind,
    "metrics.service_caddy_restore",
  );
  assertEquals(f.keys().includes("service-caddy-2"), false);
  assertEquals(f.keys().includes("metrics-runtime"), false);
});
Deno.test("a cancelled service mutation restores its snapshot before earlier fleet effects", async () => {
  const f = new FleetFixture();
  f.failures.set("service-apply-2", "operation.cancelled");
  assertEquals((await f.run()).status, "cancelled");
  assertEquals(f.keys().filter((key) => key.includes("restore")).slice(0, 2), [
    "service-restore-2",
    "service-caddy-restore-2",
  ]);
});

Deno.test("cancellation after runtime commit keeps healthy runtime and never runs the parent receipt", async () => {
  const f = new FleetFixture();
  f.cancelAfter = "metrics-runtime";
  assertEquals((await f.run()).status, "cancelled");
  assertEquals(f.keys().some((key) => key.includes("restore")), false);
  assertEquals(f.keys().includes("metrics-credentials-verified"), false);
  assertEquals(
    f.calls.some((call) => String(call.path).endsWith("/run")),
    false,
  );
});
Deno.test("a Gateway cancellation refusal at the final receipt completes cancelled without rollback", async () => {
  const f = new FleetFixture();
  f.cancelAfter = "publication-dns";
  assertEquals((await f.run()).status, "cancelled");
  assertEquals(f.keys().some((key) => key.includes("restore")), false);
  assertEquals(
    f.calls.some((call) => String(call.path).endsWith("/step")),
    false,
  );
});

Deno.test("Route Metrics reconciliation runs the captured fleet without republishing Metrics DNS or certificates", async () => {
  const f = new FleetFixture();
  f.operation.kind = "metrics.reconcile";
  f.operation.input.gateway_node_id = null;
  f.operation.input.dns_node_id = null;
  assertEquals((await f.run()).status, "succeeded");
  assert(f.children.has("metrics-runtime"));
  assert(f.children.has("metrics-credentials-verified"));
  assert(![...f.children.keys()].some((key) => key.startsWith("publication-")));
  const receipt = f.calls.find((call) => String(call.path).endsWith("/run"));
  assertEquals(receipt?.name, "metrics.receipt");
  assertEquals(
    f.calls.filter((call) => String(call.path).endsWith("/children"))
      .every((call) =>
        Object.keys(call).sort().join(",") === "key,path,worker"
      ),
    true,
  );
});

Deno.test("a Route reconcile child yields and resumes with the same pending Metrics child", async () => {
  const f = new FleetFixture();
  f.operation.kind = "metrics.reconcile";
  f.pending = "metrics-runtime";
  assertEquals((await f.run()).status, "waiting");
  const id = f.children.get("metrics-runtime")?.id;
  f.children.get("metrics-runtime")!.status = "succeeded";
  assertEquals((await f.run()).status, "succeeded");
  assertEquals(f.children.get("metrics-runtime")?.id, id);
  assert(![...f.children.keys()].some((key) => key.startsWith("publication-")));
});

for (
  const error of [
    "node.lock_lost",
    "operation.lease_lost",
    "operation.cancelled",
  ]
) {
  Deno.test(`service mutation ${error} restores the fleet without runtime convergence`, async () => {
    const f = new FleetFixture();
    f.operation.kind = "metrics.reconcile";
    f.failures.set("service-apply-2", error);
    assertEquals(
      (await f.run()).status,
      error === "operation.cancelled" ? "cancelled" : "failed",
    );
    assert(f.children.has("service-restore-2"));
    assert(f.children.has("service-caddy-restore-2"));
    assert(f.children.has("node_exporter-restore-2"));
    assert(!f.children.has("metrics-runtime"));
  });
  for (const key of ["service-restore-2", "service-caddy-restore-2"]) {
    Deno.test(`local ${key} ${error} stops reconciliation and reports rollback failure`, async () => {
      const f = new FleetFixture();
      f.operation.kind = "metrics.reconcile";
      f.failures.set("service-apply-2", "metrics.service_failed");
      f.failures.set(key, error);
      assertEquals((await f.run()).status, "failed");
      assert(!f.children.has("metrics-runtime"));
      const completion = f.calls.find((call) =>
        String(call.path).endsWith("/complete")
      );
      assertEquals(completion?.error_code, "metrics.rollback_failed");
    });
  }
}

for (const key of ["service-restore-2", "service-caddy-restore-2"]) {
  Deno.test(`an ordinary failed ${key} stays in rollback proof after a later runtime failure`, async () => {
    const f = new FleetFixture();
    f.operation.kind = "metrics.reconcile";
    f.failures.set("service-apply-2", "metrics.service_failed");
    f.failures.set(key, "metrics.restore_refused");
    f.failures.set("metrics-runtime", "metrics.runtime_failed");
    assertEquals((await f.run()).status, "failed");
    const completion = f.calls.find((call) =>
      String(call.path).endsWith("/complete")
    );
    assertEquals(completion?.error_code, "metrics.rollback_failed");
    assertEquals(f.calls.filter((call) => call.key === key).length, 2);
  });
}

Deno.test("reconcile publishes service Caddy only on managed Caddy Nodes", async () => {
  const f = new FleetFixture();
  f.operation.kind = "metrics.reconcile";
  const nodes = f.operation.input.fleet as Record<string, unknown>[];
  nodes[1] = {
    ...nodes[1],
    node_id: 209,
    name: "swamp",
    address: "10.44.0.15",
    caddy: false,
  };
  f.failures.set("service-caddy-209", "caddy.publication_failed");

  assertEquals((await f.run()).status, "succeeded");
  assert(f.children.has("service-caddy-2"));
  assert(!f.children.has("service-caddy-209"));
  assertEquals(f.childInput("metrics-runtime").service_node_ids, [2, 209]);
});

Deno.test("service recovery on a Node without managed Caddy never publishes Caddy", async () => {
  const f = new FleetFixture();
  f.operation.kind = "metrics.reconcile";
  (f.operation.input.fleet as Record<string, unknown>[])[1].caddy = false;
  f.failures.set("service-apply-5", "metrics.service_failed");
  f.failures.set("service-caddy-restore-5", "integration.child_failed");

  assertEquals((await f.run()).status, "succeeded");
  assert(f.children.has("service-restore-5"));
  assert(!f.children.has("service-caddy-restore-5"));
  assertEquals(f.childInput("metrics-runtime").service_node_ids, [2]);
});

Deno.test("fleet rollback restores unmanaged Nodes without touching their Caddy", async () => {
  const f = new FleetFixture();
  f.operation.kind = "metrics.reconcile";
  (f.operation.input.fleet as Record<string, unknown>[])[1].caddy = false;
  f.failures.set("metrics-runtime", "metrics.runtime_failed");
  f.failures.set("service-caddy-restore-5", "integration.child_failed");

  assertEquals((await f.run()).status, "failed");
  assert(f.children.has("service-restore-5"));
  assert(!f.children.has("service-caddy-restore-5"));
  assert(f.children.has("service-caddy-restore-2"));
  assert(f.children.has("node_exporter-restore-2"));
  const completion = f.calls.find((call) =>
    String(call.path).endsWith("/complete")
  );
  assertEquals(completion?.error_code, "metrics.runtime_failed");
});
