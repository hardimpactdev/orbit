import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { decoder, playbook, hasAnsible, identity, slots, Slot, Worker, State, stub, quote, worker, Fixture, test, fresh, remintTasks } from "./_support/swamp_worker_renewal.ts";

test("both full survivors defer without draining anyone", async (f) => {
  for (const slot of ["b", "c"] as const) {
    f.state.workers[slot].status = "busy";
    f.state.workers[slot].activeDispatchIds = ["1", "2", "3", "4"];
  }
  f.start();
  assertEquals(await f.result(false), []);
  assertStringIncludes(f.output, "both survivors are full");
});
for (const status of ["disconnected", "draining"]) {
  test(`${status} peer blocks all maintenance`, async (f) => {
    f.state.workers.c.status = status;
    f.start();
    assertEquals(await f.result(false), []);
    assertStringIncludes(
      f.output,
      "all three permanent workers must be connected",
    );
  });
}
for (const error of ["empty", "at capacity", "data plane failed"]) {
  test(`peer probe ${error} prevents any drain`, async (f) => {
    f.state.probe_error = { c: error };
    f.start();
    const events = await f.result(false);
    assert(events.includes("verify-c"));
    assert(!events.some((event) => event.startsWith("attempt-restart-")));
  });
}
for (const change of ["fill", "drain", "shrink"]) {
  test(`peer ${change} during probe cannot reduce the accepting pool`, async (f) => {
    if (change === "fill") {
      f.state.fill_on_probe = "c";
      f.state.workers.b.activeDispatchIds = ["1", "2", "3", "4"];
    } else if (change === "drain") f.state.drain_on_probe = "c";
    else f.state.shrink_on_probe = "c";
    f.start();
    const events = await f.result(false);
    assert(events.includes("verify-c"));
    assert(!events.includes("attempt-restart-a"));
  });
}
test("failed peer during blocking drain logs detection after restart and stops", async (f) => {
  f.state.lose_on_restart = "b";
  f.start();
  const events = await f.result(false);
  assert(events.includes("drain-a"));
  assertStringIncludes(f.output, "detected after restart, no further drain");
  assert(!events.includes("drain-c"));
});
test("peer approaching eight-hour cap during probe blocks drain", async (f) => {
  f.state.age_peer_on_probe = true;
  f.start();
  const events = await f.result(false);
  assert(events.includes("verify-c"));
  assert(!events.includes("attempt-restart-a"));
  assertStringIncludes(f.output, "session headroom changed");
});

test("same-process reconnect clears old enrolledAt lockout and renews on connection age", async (f) => {
  // Swamp retains the process UUID and enrolledAt when it reconnects after the
  // hard cap. A real journal receipt now records the successful new socket.
  f.state.workers.a.enrolledAt = new Date(Date.now() - 12 * 3600_000)
    .toISOString();
  f.state.workers.b.enrolledAt = fresh();
  f.state.workers.c.enrolledAt = fresh();
  f.state.connections = { a: fresh() };
  f.start();
  assertEquals(await f.result(), []);
  assertEquals(f.read().workers.a.instanceUuid, "old-a");
  // Once this socket is due, the same stale durable metadata must not block it.
  f.state = f.read();
  f.state.connections!.a = new Date(Date.now() - 3 * 3600_000 - 1000)
    .toISOString();
  f.start();
  const events = await f.result();
  assert(events.includes("drain-a"));
  assert(events.includes("enrolled-a"));
  assertEquals(f.read().workers.a.instanceUuid, "new-a");
  assert(!events.includes("drain-b"));
  assert(!events.includes("drain-c"));
});

test("does not recover a down unit without two serving peers", async (f) => {
  f.state.workers.c.status = "disconnected";
  f.state.unit_states = { c: "failed" };
  f.state.workers.b.status = "disconnected";
  f.start();
  assertEquals(await f.result(false), []);
});
