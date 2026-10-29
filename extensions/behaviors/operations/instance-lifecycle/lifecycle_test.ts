import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Child,
  lifecycle,
  type Receipt,
  type Session,
} from "./lifecycle.ts";

class Fixture implements Session {
  id = 100;
  nodeId = 9;
  kind = "instance.create";
  input: Receipt = {
    instance_id: 501,
    initial_status: "reserved",
    first_setup_pending: true,
    route_id: 30,
    database_server_id: null,
    database_clone_source_id: null,
    setup_digest: "a".repeat(64),
    teardown_digest: "b".repeat(64),
  };
  calls: string[] = [];
  children: Child[] = [];
  results = new Map<string, Receipt>();
  saved = new Map<string, Receipt>();
  effects = new Map<string, number>();
  loseReply: string | null = null;
  run(key: string): Promise<Receipt> {
    return this.effect(
      key,
      this.results.get(key) ??
        (key.endsWith("-runtime-intent")
          ? { process_ids: [], schedule_ids: [] }
          : { instance_id: 501 }),
    );
  }
  child(child: Child): Promise<Receipt> {
    this.children.push(child);
    return this.effect(
      child.key,
      this.results.get(child.key) ??
        (child.kind === "instance.run_steps"
          ? { outcome: "succeeded", error_code: null, failed_step: null }
          : { instance_id: 501 }),
    );
  }
  async effect(key: string, result: Receipt): Promise<Receipt> {
    this.calls.push(key);
    await Promise.resolve();
    if (!this.saved.has(key)) {
      this.effects.set(key, (this.effects.get(key) ?? 0) + 1);
      this.saved.set(key, structuredClone(result));
    }
    if (this.loseReply === key) {
      this.loseReply = null;
      throw new Error("reply lost");
    }
    return this.saved.get(key)!;
  }
}

Deno.test("create activates its Route before its database and ordered setup", async () => {
  const f = new Fixture();
  f.input.database_server_id = 4;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls, [
    "source-prepare",
    "source-inspect",
    "source-resolve",
    "source-profile",
    "source-configuration",
    "environment-sync",
    "vp-environment",
    "route-converge",
    "instance-activate",
    "database-intent",
    "database",
    "database-environment-sync",
    "setup",
    "lifecycle-finish",
  ]);
  assertEquals(f.children.find((c) => c.key === "setup")!.input, {
    instance_id: 501,
    list: "setup",
    source: "project",
    configuration_digest: "a".repeat(64),
  });
  assertEquals(
    f.children.find((c) => c.key === "database")!.kind,
    "database.create",
  );
});

Deno.test("an active completed create retry never runs setup again", async () => {
  const f = new Fixture();
  f.input.initial_status = "active";
  f.input.first_setup_pending = false;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls, ["source-inspect", "lifecycle-finish"]);
});

Deno.test("busy and uncertain setup keep the activated checkout", async () => {
  for (const outcome of ["busy", "unconfirmed", "cancelled"] as const) {
    const f = new Fixture();
    f.results.set("setup", {
      outcome,
      error_code: outcome === "cancelled"
        ? "operation.cancelled"
        : "instance.setup_step_failed",
      failed_step: "install",
    });
    const result = await lifecycle(f);
    assertEquals(
      result.status,
      outcome === "cancelled" ? "cancelled" : "failed",
    );
    assertEquals(f.calls.includes("rollback-teardown"), false);
    assertEquals(f.calls.includes("rollback-removal"), false);
  }
});

Deno.test("confirmed setup failure removes only the Instance this create activated", async () => {
  for (const initialStatus of ["reserved", "active"]) {
    const f = new Fixture();
    f.input.initial_status = initialStatus;
    f.results.set("setup", {
      outcome: "missing",
      error_code: "instance.setup_step_unavailable",
      failed_step: "install",
    });
    const result = await lifecycle(f);
    assertEquals(result.error_code, "instance.setup_step_unavailable");
    assertEquals(
      f.calls.includes("rollback-teardown"),
      initialStatus === "reserved",
    );
    assertEquals(
      f.calls.includes("rollback-removal"),
      initialStatus === "reserved",
    );
  }
});

Deno.test("uncertain rollback teardown keeps the checkout and original setup failure", async () => {
  const f = new Fixture();
  f.results.set("setup", {
    outcome: "failed",
    error_code: "instance.setup_step_failed",
    failed_step: "install",
  });
  f.results.set("rollback-teardown", {
    outcome: "unconfirmed",
    error_code: "instance.teardown_step_failed",
    failed_step: "clean",
  });
  assertEquals((await lifecycle(f)).error_code, "instance.setup_step_failed");
  assertEquals(f.calls.includes("rollback-removal"), false);
});

Deno.test("lost source, child and completion replies reuse keys without running their effects twice", async () => {
  for (const key of ["source-prepare", "setup", "lifecycle-finish"]) {
    const f = new Fixture();
    f.loseReply = key;
    await assertRejects(() => lifecycle(f), Error, "reply lost");
    assertEquals(f.calls.includes("rollback-removal"), false);
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.effects.get(key), 1);
    assertEquals([...f.effects.values()].every((n) => n === 1), true);
  }
});

Deno.test("rename waits for the Route child before it records branch or environment", async () => {
  const f = new Fixture();
  f.kind = "instance.rename";
  f.input.domain = "preview.test";
  f.results.set("route-update", { error_code: "route.domain_conflict" });
  assertEquals((await lifecycle(f)).error_code, "route.domain_conflict");
  assertEquals(f.calls, ["rename-inspect", "route-update"]);
});

Deno.test("clone never runs development setup or teardown", async () => {
  const f = new Fixture();
  f.kind = "instance.clone";
  f.input.candidate_node_id = 2;
  f.input.candidate_id = 100;
  f.input.sqlite_source_path = null;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.children.some((c) => c.kind === "instance.run_steps"), false);
  assertEquals(f.children[0].kind, "instance.sync_environment");
  assertEquals(f.children[0].node_id, f.nodeId);
});

Deno.test("clone attaches and synchronizes only a proven database-family SQLite seed", async () => {
  const f = new Fixture();
  f.kind = "instance.clone";
  f.input.sqlite_source_path = "/srv/apps/source/database.sqlite";
  f.results.set("clone-sqlite-intent", { prepared: true, seed_required: true });
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.children[0].kind, "database.sqlite_seed");
  assertEquals(f.calls.slice(6, 10), [
    "clone-sqlite-intent",
    "clone-sqlite",
    "clone-sqlite-attach",
    "clone-environment-sync",
  ]);
});

Deno.test("clone resumes a retained seed checkpoint without taking another snapshot", async () => {
  const f = new Fixture();
  f.kind = "instance.clone";
  f.input.sqlite_source_path = "/srv/apps/source/database.sqlite";
  f.results.set("clone-sqlite-intent", {
    prepared: true,
    seed_required: false,
  });
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls.includes("clone-sqlite"), false);
  assertEquals(f.calls.includes("clone-sqlite-attach"), true);
});

Deno.test("a refused SQLite seed preserves clone state before attachment or environment writes", async () => {
  const f = new Fixture();
  f.kind = "instance.clone";
  f.input.sqlite_source_path = "/srv/apps/source/database.sqlite";
  f.results.set("clone-sqlite-intent", { prepared: true, seed_required: true });
  f.results.set("clone-sqlite", { error_code: "sqlite.seed_failed" });
  assertEquals((await lifecycle(f)).error_code, "sqlite.seed_failed");
  assertEquals(f.calls.includes("clone-sqlite-attach"), false);
  assertEquals(f.calls.includes("clone-environment-sync"), false);
});

Deno.test("registration runs setup only when it was requested", async () => {
  for (const requested of [false, true]) {
    const f = new Fixture();
    f.kind = "instance.register";
    f.input.members = [{ instance_id: 501, route_node_id: 9, route_id: 30 }];
    f.input.run_setup = requested;
    assertEquals((await lifecycle(f)).status, "succeeded");
    assertEquals(f.calls.includes("setup"), requested);
  }
});

Deno.test("teardown refusal stops removal before acceptance or Route withdrawal", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{
    instance_id: 501,
    route_id: 30,
    route_node_id: 9,
    run_teardown: true,
    teardown_digest: "b".repeat(64),
  }];
  f.results.set("teardown-501", {
    outcome: "failed",
    error_code: "instance.teardown_step_failed",
    failed_step: "cleanup",
  });
  assertEquals(
    (await lifecycle(f)).error_code,
    "instance.teardown_step_failed",
  );
  assertEquals(f.calls, ["removal-inspect", "teardown-501"]);
});

Deno.test("a confirmed registration failure restores its Laravel receipt before reporting the original refusal", async () => {
  const f = new Fixture();
  f.kind = "instance.register";
  f.input.members = [{ instance_id: 501, route_node_id: 9, route_id: 30 }];
  f.results.set("registration-501-configuration", {
    error_code: "app-dev.laravel_url_configuration_failed",
  });
  assertEquals(
    (await lifecycle(f)).error_code,
    "app-dev.laravel_url_configuration_failed",
  );
  assertEquals(f.calls.at(-1), "registration-501-receipt-restore");
  assertEquals(f.calls.includes("registration-501-activate"), false);
});

Deno.test("cancelled, busy and unconfirmed registration effects retain their saved Laravel receipt", async () => {
  for (
    const code of [
      "operation.cancelled",
      "instance.source_busy",
      "operation.execution_uncertain",
    ]
  ) {
    const f = new Fixture();
    f.kind = "instance.register";
    f.input.members = [{ instance_id: 501, route_node_id: 9, route_id: 30 }];
    f.results.set("registration-501-configuration", { error_code: code });
    assertEquals((await lifecycle(f)).error_code, code);
    assertEquals(f.calls.includes("registration-501-receipt-restore"), false);
    assertEquals(f.calls.includes("registration-501-receipt-discard"), false);
  }
});

Deno.test("transfer proves destination completion before cutover and source cleanup", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.input.destination_node_id = 10;
  f.input.transfer_id = "proof-transfer";
  f.results.set("transfer-destination", {
    error_code: "instance.transfer_failed",
  });
  assertEquals((await lifecycle(f)).status, "failed");
  assertEquals(f.calls, [
    "transfer-stop-source",
    "transfer-capture",
    "transfer-sqlite-intent",
    "transfer-destination",
    "transfer-rollback",
  ]);
  assertEquals(
    f.children.find((child) => child.key === "transfer-destination")!.node_id,
    10,
  );
});

Deno.test("cancellation after the stop child admits compensation without a parent rollback run", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.input.destination_node_id = 10;
  f.input.transfer_id = "cancelled-transfer";
  f.results.set("transfer-capture", { error_code: "operation.cancelled" });
  assertEquals((await lifecycle(f)).status, "cancelled");
  assertEquals(f.calls, [
    "transfer-stop-source",
    "transfer-capture",
    "transfer-rollback",
  ]);
  assertEquals(f.children.at(-1)!.kind, "instance.transfer_rollback");
});

Deno.test("pending capture cancellation retains both copies without admitting rollback", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.input.destination_node_id = 10;
  f.input.transfer_id = "pending-transfer";
  f.results.set("transfer-capture", {
    error_code: "operation.cancelled",
    rollback_pending: true,
  });

  assertEquals(await lifecycle(f), {
    status: "cancelled",
    error_code: "operation.cancelled",
    result: { error_code: "operation.cancelled", rollback_pending: true },
  });
  assertEquals(f.calls, ["transfer-stop-source", "transfer-capture"]);
});

Deno.test("rollback restores the source before recorded SQLite seed and destination cleanup", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer_rollback";
  f.results.set("rollback-record", {
    recorded: true,
    seed_abandon_required: true,
    destination_node_id: 10,
    seed_abandon_node_id: 12,
  });
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls, [
    "rollback-record",
    "transfer-restore-source",
    "transfer-abandon-sqlite",
    "transfer-discard-destination",
    "rollback-state",
    "compensation-proof",
  ]);
  assertEquals(f.children.map((child) => child.kind), [
    "process.instance_runtime",
    "database.sqlite_seed_abandon",
    "instance.transfer_discard",
  ]);
  assertEquals(f.children.map((child) => child.node_id), [9, 12, 10]);
});

Deno.test("refused SQLite abandonment preserves the restored source and destination for recovery", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer_rollback";
  f.results.set("rollback-record", {
    recorded: true,
    seed_abandon_required: true,
    destination_node_id: 10,
    seed_abandon_node_id: 12,
  });
  f.results.set("transfer-abandon-sqlite", {
    error_code: "sqlite.seed_failed",
  });
  assertEquals((await lifecycle(f)).error_code, "sqlite.seed_failed");
  assertEquals(f.calls, [
    "rollback-record",
    "transfer-restore-source",
    "transfer-abandon-sqlite",
  ]);
});

Deno.test("confirmed source refusal rolls back a new create, while busy and unknown replies retain it", async () => {
  for (
    const code of [
      "instance.clone_failed",
      "instance.source_busy",
      "operation.cancelled",
    ]
  ) {
    const f = new Fixture();
    f.results.set("source-prepare", { error_code: code });
    assertEquals((await lifecycle(f)).error_code, code);
    assertEquals(
      f.calls.includes("rollback-removal"),
      code === "instance.clone_failed",
    );
    assertEquals(f.calls.includes("rollback-teardown"), false);
  }
  const f = new Fixture();
  f.loseReply = "source-prepare";
  await assertRejects(() => lifecycle(f), Error, "reply lost");
  assertEquals(f.calls, ["source-prepare"]);
});

Deno.test("database failure removes only a create's own activation and does not run teardown", async () => {
  for (const status of ["reserved", "active"]) {
    const f = new Fixture();
    f.input.initial_status = status;
    f.input.database_server_id = 4;
    f.results.set("database", { error_code: "instance.database_clone_failed" });
    assertEquals(
      (await lifecycle(f)).error_code,
      "instance.database_clone_failed",
    );
    assertEquals(f.calls.includes("rollback-removal"), status === "reserved");
    assertEquals(f.calls.includes("rollback-teardown"), false);
    assertEquals(f.calls.includes("setup"), false);
  }
});

Deno.test("busy rollback teardown keeps source and preserves its busy classification", async () => {
  const f = new Fixture();
  f.results.set("setup", {
    outcome: "failed",
    error_code: "instance.setup_step_failed",
    failed_step: "install",
  });
  f.results.set("rollback-teardown", {
    outcome: "busy",
    error_code: "instance.lifecycle_busy",
    failed_step: "clean",
  });
  assertEquals((await lifecycle(f)).error_code, "instance.lifecycle_busy");
  assertEquals(f.calls.includes("rollback-removal"), false);
});

Deno.test("a completed clone retry only returns its final receipt", async () => {
  const f = new Fixture();
  f.kind = "instance.clone";
  f.input.initial_completed = true;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls, ["lifecycle-finish"]);
});

Deno.test("source cleanup follows proven Route retirement after cutover", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.input.destination_node_id = 10;
  f.input.transfer_id = "proof-transfer";
  f.results.set("transfer-retire-source", {
    error_code: "instance.transfer_cleanup_incomplete",
  });
  assertEquals(
    (await lifecycle(f)).error_code,
    "instance.transfer_cleanup_incomplete",
  );
  assertEquals(f.calls.includes("transfer-cutover"), true);
  assertEquals(f.calls.includes("transfer-cleanup-source"), false);
});

Deno.test("an after-cutover retry only moves forward and never copies source again", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.input.destination_node_id = 10;
  f.input.transfer_id = "proof-transfer";
  f.input.initial_cutover = true;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls.includes("transfer-stop-source"), false);
  assertEquals(f.calls.includes("transfer-capture"), false);
  assertEquals(f.calls.includes("transfer-destination"), false);
  assertEquals(f.calls.includes("transfer-rollback-intent"), false);
  assertEquals(f.calls.at(-2), "transfer-finish");
});

Deno.test("an uncertain destination outcome does not discard source or destination", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer";
  f.input.destination_node_id = 10;
  f.input.transfer_id = "proof-transfer";
  f.loseReply = "transfer-destination";
  await assertRejects(() => lifecycle(f), Error, "reply lost");
  assertEquals(f.calls.includes("transfer-rollback-intent"), false);
  assertEquals(f.calls.includes("transfer-cutover"), false);
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.effects.get("transfer-destination"), 1);
});

Deno.test("clone installs captured Process and Schedule definitions stopped before Route projection", async () => {
  const f = new Fixture();
  f.kind = "instance.clone";
  f.input.sqlite_source_path = null;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(
    f.children.filter((c) => c.key.startsWith("clone-")).map((
      c,
    ) => [c.kind, c.input]),
    [
      ["instance.sync_environment", { instance_id: 501 }],
      ["process.install_instance_definitions", {
        instance_id: 501,
      }],
      ["schedule.install_instance_definitions", {
        instance_id: 501,
      }],
    ],
  );
  assertEquals(
    f.calls.indexOf("route-converge") > f.calls.indexOf("clone-schedules"),
    true,
  );
});

Deno.test("removal waits for Process and Schedule removal before shared Route cleanup", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{ instance_id: 501, route_id: null, run_teardown: false }];
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.calls, [
    "removal-inspect",
    "removal-revalidate",
    "removal-accept",
    "removal-501-source-prepare",
    "withdraw-instance-501",
    "removal-501-route-clear",
    "removal-501-source-finalize",
    "removal-501-runtime-intent",
    "cleanup-instance-501",
    "removal-501-database-intent",
    "remove-databases-501",
    "removal-501-row-delete",
    "lifecycle-finish",
  ]);
});

Deno.test("source preparation refusal keeps the Route and source in place", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{
    instance_id: 501,
    route_node_id: 9,
    route_id: 30,
    run_teardown: false,
  }];
  f.results.set("removal-501-source-prepare", {
    error_code: "instance.source_busy",
  });
  assertEquals((await lifecycle(f)).error_code, "instance.source_busy");
  assertEquals(f.calls, [
    "removal-inspect",
    "removal-revalidate",
    "removal-accept",
    "removal-501-source-prepare",
  ]);
});

Deno.test("a refused owned database removal keeps the Instance row", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{ instance_id: 501, route_id: null, run_teardown: false }];
  f.results.set("remove-databases-501", { error_code: "database.in_use" });
  assertEquals((await lifecycle(f)).error_code, "database.in_use");
  assertEquals(f.calls.includes("removal-501-source-finalize"), true);
  assertEquals(f.calls.includes("removal-501-row-delete"), false);
});

Deno.test("database drop waits for workers and schedulers to close their connections", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{ instance_id: 501, route_id: null, run_teardown: false }];
  const scheduleId = "12345678-1234-4234-8234-123456789abc";
  f.results.set("removal-501-runtime-intent", {
    process_ids: [31, 32],
    schedule_ids: [scheduleId],
  });
  let workerConnected = true;
  let schedulerConnected = true;
  const child = f.child.bind(f);
  f.child = (intent) => {
    if (intent.key === "remove-process-501-32") workerConnected = false;
    if (intent.key === `remove-schedule-501-${scheduleId}`) {
      schedulerConnected = false;
    }
    if (
      intent.key === "remove-databases-501" &&
      (workerConnected || schedulerConnected)
    ) {
      return Promise.resolve({ error_code: "database.in_use" });
    }
    return child(intent);
  };
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(
    f.calls.indexOf("cleanup-instance-501") <
      f.calls.indexOf("remove-databases-501"),
    true,
  );
});

Deno.test("database clone attachment is synchronized before setup", async () => {
  const f = new Fixture();
  f.input.database_clone_source_id = 385;
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(
    f.children.find((c) => c.key === "database")!.kind,
    "database.clone",
  );
  assertEquals(
    f.calls.indexOf("database-environment-sync") > f.calls.indexOf("database"),
    true,
  );
  assertEquals(
    f.calls.indexOf("setup") > f.calls.indexOf("database-environment-sync"),
    true,
  );
});

Deno.test("a refused per-record runtime removal stops before Route cleanup and database deletion", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{ instance_id: 501, route_id: null, run_teardown: false }];
  f.results.set("removal-501-runtime-intent", {
    process_ids: [31, 32],
    schedule_ids: [],
  });
  f.results.set("remove-process-501-31", {
    error_code: "process.remove_failed",
  });
  assertEquals((await lifecycle(f)).error_code, "process.remove_failed");
  assertEquals(f.calls.includes("remove-process-501-32"), false);
  assertEquals(f.calls.includes("cleanup-instance-501"), false);
  assertEquals(f.calls.includes("remove-databases-501"), false);
});

Deno.test("a lost per-record child reply reuses its exact key before continuing runtime cleanup", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{ instance_id: 501, route_id: null, run_teardown: false }];
  const scheduleId = "12345678-1234-4234-8234-123456789abc";
  f.results.set("removal-501-runtime-intent", {
    process_ids: [31],
    schedule_ids: [scheduleId],
  });
  f.loseReply = "remove-process-501-31";
  await assertRejects(() => lifecycle(f), Error, "reply lost");
  assertEquals(f.calls.includes("cleanup-instance-501"), false);
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.effects.get("remove-process-501-31"), 1);
  assertEquals(
    f.children.find((entry) => entry.kind === "process.destroy")!.input,
    { process_id: 31 },
  );
  assertEquals(
    f.children.find((entry) => entry.kind === "schedule.destroy")!.input,
    { id: scheduleId },
  );
});

Deno.test("production removal publishes the shared pool on its recorded Nodes before clearing current and removes home after runtime", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input = {
    instance_id: 501,
    members: [{
      instance_id: 501,
      environment: "production",
      route_id: null,
      run_teardown: false,
    }],
    production_routes: [{
      id: 30,
      targets: [501, 502],
      node_id: 4,
      nodes: [9, 10, 4, 5],
    }],
  };
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(
    f.children.filter((c) => c.kind === "route.caddy_converge").map((c) =>
      c.node_id
    ),
    [9, 10, 4, 5],
  );
  assertEquals(
    f.children.some((c) =>
      c.kind === "route.withdraw" || c.kind === "instance.run_steps"
    ),
    false,
  );
  assertEquals(
    f.calls.indexOf("removal-501-route-detach") <
      f.calls.indexOf("publish-production-30-9"),
    true,
  );
  assertEquals(
    f.calls.indexOf("publish-production-30-5") <
      f.calls.indexOf("removal-501-source-finalize"),
    true,
  );
  assertEquals(
    f.calls.indexOf("cleanup-instance-501") <
      f.calls.indexOf("removal-501-home-remove"),
    true,
  );
  assertEquals(
    f.calls.indexOf("removal-501-home-remove") <
      f.calls.indexOf("removal-501-row-delete"),
    true,
  );
});
Deno.test("production final Route withdrawal uses its serving Node and a refused publication keeps the home", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input = {
    instance_id: 501,
    members: [{
      instance_id: 501,
      environment: "production",
      route_id: null,
      run_teardown: false,
    }],
    production_routes: [{ id: 30, targets: [501], node_id: 4, nodes: [9, 4] }],
  };
  f.results.set("withdraw-production-30", {
    error_code: "caddy.activation_failed",
  });
  assertEquals((await lifecycle(f)).status, "failed");
  assertEquals(f.children[0].node_id, 4);
  assertEquals(f.calls.includes("removal-501-home-remove"), false);
  assertEquals(f.calls.includes("removal-501-route-detach"), false);
});

Deno.test("development removal withdraws primary and extra Routes on their recorded serving Nodes", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input = {
    instance_id: 501,
    members: [{
      instance_id: 501,
      environment: "development",
      route_id: 30,
      route_node_id: 4,
      run_teardown: false,
    }],
    additional_route_ids: [40],
    additional_routes: [{ route_id: 40, node_id: 5 }],
  };
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(
    f.children.filter((c) => c.kind === "route.withdraw").map(
      (c) => [c.input.route_id, c.node_id],
    ),
    [[40, 5], [30, 4]],
  );
});

Deno.test("database drop waits for workers and schedulers to close their connections in production", async () => {
  const f = new Fixture();
  f.input.production_routes = [];
  f.kind = "instance.destroy";
  f.input.members = [{
    instance_id: 501,
    environment: "production",
    route_id: null,
    run_teardown: false,
  }];
  const scheduleId = "12345678-1234-4234-8234-123456789abc";
  f.results.set("removal-501-runtime-intent", {
    process_ids: [31],
    schedule_ids: [scheduleId],
  });
  let workerConnected = true;
  let schedulerConnected = true;
  const child = f.child.bind(f);
  f.child = (intent) => {
    if (intent.key === "remove-process-501-31") workerConnected = false;
    if (intent.key === `remove-schedule-501-${scheduleId}`) {
      schedulerConnected = false;
    }
    if (
      intent.key === "remove-databases-501" &&
      (workerConnected || schedulerConnected)
    ) {
      return Promise.resolve({ error_code: "database.in_use" });
    }
    return child(intent);
  };
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(
    f.calls.indexOf("cleanup-instance-501") <
      f.calls.indexOf("remove-databases-501"),
    true,
  );
});

Deno.test("a changed single-target Route stops production removal before withdrawal", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{
    instance_id: 501,
    environment: "production",
    route_id: 30,
    run_teardown: false,
  }];
  f.input.production_routes = [{
    id: 30,
    targets: [501],
    node_id: 4,
    nodes: [9, 4],
  }];
  f.results.set("removal-501-source-prepare", {
    error_code: "instance.removal_conflict",
  });

  assertEquals((await lifecycle(f)).status, "failed");
  assertEquals(f.children, []);
  assertEquals(f.calls.includes("withdraw-production-30"), false);
  assertEquals(f.calls.includes("removal-501-home-remove"), false);
});

Deno.test("rollback without SQLite restores the source before discarding on the destination", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer_rollback";
  f.results.set("rollback-record", {
    recorded: true,
    seed_abandon_required: false,
    destination_node_id: 10,
    seed_abandon_node_id: null,
  });
  assertEquals((await lifecycle(f)).status, "succeeded");
  assertEquals(f.children.map((child) => [child.key, child.node_id]), [[
    "transfer-restore-source",
    9,
  ], ["transfer-discard-destination", 10]]);
});

Deno.test("an offline destination cannot block rollback source restoration", async () => {
  const f = new Fixture();
  f.kind = "instance.transfer_rollback";
  f.results.set("rollback-record", {
    recorded: true,
    seed_abandon_required: false,
    destination_node_id: 10,
    seed_abandon_node_id: null,
  });
  f.results.set("transfer-discard-destination", {
    error_code: "node.unreachable",
  });

  assertEquals((await lifecycle(f)).error_code, "node.unreachable");
  assertEquals(f.calls, [
    "rollback-record",
    "transfer-restore-source",
    "transfer-discard-destination",
  ]);
  assertEquals(f.effects.get("transfer-restore-source"), 1);
});
