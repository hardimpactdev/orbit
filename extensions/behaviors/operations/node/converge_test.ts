import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { ExecutorError } from "../../operation-runner/mod.ts";
import { Fixture } from "./test-fixture.ts";

Deno.test("a current footprint performs no Node effects", async () => {
  const f = new Fixture();
  f.artifact("agent", false);
  f.artifact("caddy", false);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), ["node.prepare-footprint", "node.footprint-result"]);
  assertEquals(f.childAdmissions, 0);
  assertEquals(f.completion()?.result, {
    footprint_proven: true,
    artifacts: [{ name: "agent", status: "unchanged", reason: null }, {
      name: "caddy",
      status: "unchanged",
      reason: null,
    }],
    skipped: [],
    digest: "digest",
    changed: false,
  });
});
Deno.test("footprint starts the four assigned shared kinds with Gateway-rendered inputs", async () => {
  for (
    const [artifact, kind, input] of [
      ["caddy", "route.caddy_converge", { reason: "node-footprint:7:caddy" }],
      ["private-dns", "route.private_dns_publish", {
        reason: "node-footprint:7:private-dns",
      }],
      ["route-residue", "route.offline_residue_clean", {
        removed_node_id: null,
      }],
      ["ai-router", "ai_router.collector_converge", {}],
    ] as const
  ) {
    const f = new Fixture();
    f.child(artifact, kind, input);
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(
      f.calls.find((c) => c.path.endsWith("/children") && c.body.key)!.body,
      {
        worker: "worker-1",
        key: `footprint-${artifact}`,
      },
    );
  }
});
Deno.test("a pending child keeps the parent open and resumes the same child", async () => {
  const f = new Fixture();
  f.child("ai-router", "ai_router.collector_converge", {});
  f.children.set(20, { status: "running", result: null, error_code: null });
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.completion(), undefined);
  assertEquals(f.calls.find((c) => c.path.endsWith("/wait"))?.body, {
    worker: "worker-1",
    keys: ["footprint-ai-router"],
  });
  f.children.set(20, { status: "succeeded", result: {}, error_code: null });
  assertEquals((await f.execute("worker-2")).status, "succeeded");
  assertEquals(f.childAdmissions, 1);
});
Deno.test("a lost child admission reply recovers the stamped child from its parent", async () => {
  const f = new Fixture();
  f.child("private-dns", "route.private_dns_publish", {
    reason: "node-footprint:7:private-dns",
  });
  f.lostChild = true;
  await assertRejects(() => f.execute(), Error, "Lost child reply");
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.childAdmissions, 1);
  assertEquals(f.childIntent?.key, "footprint-private-dns");
});
Deno.test("Caddy render refusals stay skipped on the first Node", async () => {
  const f = new Fixture();
  f.child("caddy", "route.caddy_converge", { reason: "test" });
  f.operation.input.first_visit = true;
  f.children.set(20, {
    status: "failed",
    result: { stage: "render", message: "Invalid site" },
    error_code: "caddy.render_failed",
  });
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), ["node.prepare-footprint", "node.footprint-result"]);
  assertEquals(f.calls.some((c) => c.body.name === "footprint-caddy"), false);
});
Deno.test("first Node validation refusal fails changed Orbit code but skips live drift repair", async () => {
  for (const repair of [false, true]) {
    const f = new Fixture();
    f.child("caddy", "route.caddy_converge", { reason: "test" });
    f.operation.input.first_visit = true;
    f.snapshot.artifacts[0].repair = repair;
    f.children.set(20, {
      status: "failed",
      result: { stage: "validate" },
      error_code: "caddy.validate_failed",
    });
    f.replies.set(
      "footprint-result",
      repair ? { footprint_proven: true, artifacts: [], skipped: [] } : {
        error_code: "node.footprint_caddy_failed",
        artifacts: [],
        skipped: [{
          child_id: 20,
          key: "footprint-caddy",
          kind: "route.caddy_converge",
          reason: "caddy_validate_refused",
        }],
      },
    );
    assertEquals((await f.execute()).status, repair ? "succeeded" : "failed");
    if (!repair) {
      assertEquals(f.completion()?.error_code, "node.footprint_caddy_failed");
    }
  }
});
Deno.test("Route residue refusal stays skipped so fleet rollout can continue", async () => {
  const f = new Fixture();
  f.child("route-residue", "route.offline_residue_clean", {
    removed_node_id: null,
  });
  f.children.set(20, {
    status: "failed",
    result: { message: "Broken site" },
    error_code: "route.cleanup_failed",
  });
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), ["node.prepare-footprint", "node.footprint-result"]);
});
Deno.test("a child failure stops before later artifacts", async () => {
  const f = new Fixture();
  f.child("private-dns", "route.private_dns_publish", { reason: "test" });
  f.artifact("tmpfiles");
  f.children.set(20, {
    status: "failed",
    result: {},
    error_code: "dns.publish_failed",
  });
  f.replies.set("footprint-result", {
    error_code: "dns.publish_failed",
    artifacts: [],
    skipped: [],
  });
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.runs(), [
    "node.prepare-footprint",
    "node.install-private-dns-listener",
    "node.footprint-result",
  ]);
  assertEquals(f.completion()?.error_code, "dns.publish_failed");
});
Deno.test("lost direct effect and completion replies reuse stored receipts", async () => {
  const f = new Fixture();
  f.artifact("tmpfiles");
  f.snapshot.payloads.tmpfiles = { rule: "managed rule" };
  f.lostRun = "footprint-tmpfiles";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.lostCompletion = true;
  await assertRejects(() => f.execute(), Error, "Lost completion reply");
  const count = f.calls.length;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.calls.slice(count).map((call) => call.path), [
    "/api/v1/operations/7",
    "/api/v1/operation-executor/7/heartbeat",
    "/api/v1/operation-executor/7/complete",
  ]);
  assertEquals(f.receipts.size, 3);
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.key === "footprint-tmpfiles"
    ).length,
    1,
  );
  assertEquals(
    f.calls.some((c) => c.path.endsWith("/receipts/footprint-tmpfiles")),
    true,
  );
});
Deno.test("a lost lease stops the footprint before further effects", async () => {
  const f = new Fixture();
  f.leaseLost = true;
  await assertRejects(() => f.execute(), ExecutorError, "operation.lease_lost");
  assertEquals(f.runs(), []);
});
Deno.test("unknown cross-family kinds are refused before admission", async () => {
  const f = new Fixture();
  f.child("private-dns", "route.run-anything", { reason: "test" });
  await assertRejects(() => f.execute());
  assertEquals(f.childAdmissions, 0);
});

Deno.test("agent configuration uses the install receipt bound by the Gateway", async () => {
  for (const changed of [false, true]) {
    const f = new Fixture();
    f.artifact("agent");
    f.snapshot.payloads.agent = {
      configuration: "rendered",
      secret_hash: { $secret: "node.agent_secret_hash", id: 2 },
    };
    f.replies.set("footprint-agent-install", { installed: true, changed });
    f.replies.set("prepare-agent-configuration", { prepared: true });
    assertEquals((await f.execute()).status, "succeeded");
    assertEquals(f.runs(), [
      "node.prepare-footprint",
      "agent.install",
      "node.prepare-agent-configuration",
      "agent.configure",
      "node.footprint-result",
    ]);
    assertEquals(
      f.calls.find((c) =>
        c.path.endsWith("/run") && c.body.name === "agent.install"
      )!.body.input,
      { pin: "a".repeat(64) },
    );
    const configure = f.calls.find((c) =>
      c.path.endsWith("/run") && c.body.name === "agent.configure"
    )!.body;
    assertEquals(configure.input, {
      configuration: "rendered",
      secret_hash: { $secret: "node.agent_secret_hash", id: 2 },
      binary_changed: changed,
    });
  }
});

Deno.test("a running target receipt leaves the parent open until it settles", async () => {
  const f = new Fixture();
  f.artifact("tmpfiles");
  f.snapshot.payloads.tmpfiles = { rule: "managed" };
  f.lostRun = "footprint-tmpfiles";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.receiptState = "running";
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.completion(), undefined);
  f.receiptState = null;
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(
    f.calls.filter((c) =>
      c.path.endsWith("/run") && c.body.key === "footprint-tmpfiles"
    ).length,
    1,
  );
});
Deno.test("an unknown target receipt permits only the original keyed invocation", async () => {
  const f = new Fixture();
  f.artifact("tmpfiles");
  f.snapshot.payloads.tmpfiles = { rule: "managed" };
  f.lostRun = "footprint-tmpfiles";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  f.receiptState = "unknown";
  assertEquals((await f.execute()).status, "succeeded");
  const calls = f.calls.filter((c) =>
    c.path.endsWith("/run") && c.body.key === "footprint-tmpfiles"
  );
  assertEquals(calls.length, 2);
  assertEquals(calls[0].body, calls[1].body);
});
Deno.test("PHP empty maps are read as objects while result lists stay lists", async () => {
  const f = new Fixture();
  f.operation.input._footprint = {
    agent_pin: "a".repeat(64),
    artifacts: [],
    payloads: [],
    children: [],
  };
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals((f.completion()?.result as Record<string, unknown>).skipped, []);
});

Deno.test("private DNS installs the listener before publication and recovers a lost setup reply", async () => {
  const f = new Fixture();
  f.child("private-dns", "route.private_dns_publish", { reason: "test" });
  f.lostRun = "footprint-private-dns-listener";
  await assertRejects(() => f.execute(), Error, "Lost run reply");
  assertEquals(f.childAdmissions, 0);
  assertEquals((await f.execute()).status, "succeeded");
  assertEquals(f.runs(), [
    "node.prepare-footprint",
    "node.install-private-dns-listener",
    "node.footprint-result",
  ]);
  const install = f.calls.findIndex((c) =>
    c.body.key === "footprint-private-dns-listener"
  );
  const publish = f.calls.findIndex((c) =>
    c.body.key === "footprint-private-dns"
  );
  assertEquals(install < publish, true);
  assertEquals(f.childAdmissions, 1);
});
Deno.test("failed listener setup prevents publication and drops into Gateway evidence", async () => {
  const f = new Fixture();
  f.child("private-dns", "route.private_dns_publish", { reason: "test" });
  f.replies.set("footprint-private-dns-listener", {
    error_code: "node.private_dns_listener_failed",
  });
  f.replies.set("footprint-result", {
    error_code: "node.private_dns_listener_failed",
    artifacts: [],
    skipped: [],
  });
  assertEquals((await f.execute()).status, "failed");
  assertEquals(f.childAdmissions, 0);
  assertEquals(f.completion()?.error_code, "node.private_dns_listener_failed");
});

Deno.test("a waiting nested child durably parks its footprint parent", async () => {
  const f = new Fixture();
  f.child("ai-router", "ai_router.collector_converge", {});
  f.children.set(20, { status: "waiting", result: null, error_code: null });
  assertEquals((await f.execute()).status, "waiting");
  assertEquals(f.calls.find((c) => c.path.endsWith("/wait"))?.body, {
    worker: "worker-1",
    keys: ["footprint-ai-router"],
  });
  assertEquals(f.completion(), undefined);
});
