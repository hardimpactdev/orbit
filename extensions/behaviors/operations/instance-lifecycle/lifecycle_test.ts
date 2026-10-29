import { assertEquals } from "jsr:@std/assert@1.0.19";
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
    return this.effect(key, this.results.get(key) ?? { instance_id: 501 });
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

Deno.test("rename waits for the Route child before it records branch or environment", async () => {
  const f = new Fixture();
  f.kind = "instance.rename";
  f.input.domain = "preview.test";
  f.results.set("route-update", { error_code: "route.domain_conflict" });
  assertEquals((await lifecycle(f)).error_code, "route.domain_conflict");
  assertEquals(f.calls, ["rename-inspect", "route-update"]);
});

Deno.test("teardown refusal stops removal before acceptance or Route withdrawal", async () => {
  const f = new Fixture();
  f.kind = "instance.destroy";
  f.input.members = [{
    instance_id: 501,
    route_id: 30,
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
    "remove-processes-501",
    "remove-schedules-501",
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
  f.input.members = [{ instance_id: 501, route_id: 30, run_teardown: false }];
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
  let workerConnected = true;
  let schedulerConnected = true;
  const child = f.child.bind(f);
  f.child = (intent) => {
    if (intent.key === "remove-processes-501") workerConnected = false;
    if (intent.key === "remove-schedules-501") schedulerConnected = false;
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
