import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { decoder, playbook, hasAnsible, identity, slots, Slot, Worker, State, stub, root, path, name, accepting, exists, pause, save, event, quote, worker, Fixture, test, fresh, remintTasks } from "./_support/swamp_worker_renewal.ts";

test("two permanent peers accept before a drains and c stays running", async (f) => {
  f.hold("hold-drain-a");
  f.hold("hold-enrollment-a");
  f.start();
  await f.waitFor("drain-a");
  assertEquals(f.read().workers.c.instanceUuid, "old-c");
  assertEquals(f.read().workers.c.status, "idle");
  assertEquals(f.read().workers.b.status, "idle");
  f.release("hold-drain-a");
  await f.waitFor("waiting-a");
  assert(!f.events().includes("drain-b"));
  assert(!f.events().includes("drain-c"));
  f.release("hold-enrollment-a");
  const events = await f.result();
  assert(events.indexOf("verify-b") < events.indexOf("drain-a"));
  assert(events.indexOf("verify-c") < events.indexOf("drain-a"));
  assert(events.indexOf("enrolled-a") < events.indexOf("verify-a"));
  assert(
    !events.some((event) =>
      event.startsWith("start-") || event.startsWith("attempt-stop-")
    ),
  );
});
test("b renews next while fresh a and permanent c stay connected", async (f) => {
  f.state.workers.a.enrolledAt = fresh();
  f.start();
  const events = await f.result();
  assert(events.indexOf("verify-c") < events.indexOf("drain-b"));
  assert(events.includes("enrolled-b"));
  assert(!events.includes("drain-a"));
  assert(!events.includes("drain-c"));
});
test("c renews as an equal permanent worker when it is oldest", async (f) => {
  f.state.workers.a.enrolledAt = fresh();
  f.state.workers.b.enrolledAt = fresh();
  f.start();
  const events = await f.result();
  assert(events.indexOf("verify-a") < events.indexOf("drain-c"));
  assert(events.indexOf("verify-b") < events.indexOf("drain-c"));
  assert(events.includes("enrolled-c"));
  assert(!events.includes("drain-a"));
  assert(!events.includes("drain-b"));
});
test("three due workers finish sequential 75 minute drains before their session caps", async (f) => {
  for (const worker of Object.values(f.state.workers)) {
    worker.enrolledAt = new Date(Date.now() - 3 * 3600_000 - 1000)
      .toISOString();
  }
  f.state.elapse_drain_seconds = 75 * 60;
  for (const slot of slots) {
    f.start();
    await f.result();
    f.state = f.read();
    assertEquals(f.state.workers[slot].instanceUuid, `new-${slot}`);
    // Advance the five-minute timer between real playbook rounds.
    for (const worker of Object.values(f.state.workers)) {
      worker.enrolledAt = new Date(Date.parse(worker.enrolledAt) - 5 * 60_000)
        .toISOString();
    }
  }
  assertEquals(f.events().filter((event) => event.startsWith("drain-")), [
    "drain-a",
    "drain-b",
    "drain-c",
  ]);
});
test("two 4h45 bases renew beside fresh c without a second retirement drain", async (f) => {
  for (const slot of ["a", "b"] as const) {
    f.state.workers[slot].enrolledAt = new Date(
      Date.now() - (4 * 60 + 45) * 60_000,
    ).toISOString();
  }
  f.state.workers.c.enrolledAt = fresh();
  f.state.elapse_drain_seconds = 75 * 60;
  f.start();
  await f.result();
  f.state = f.read();
  f.start();
  await f.result();
  assertEquals(f.events().filter((event) => event.startsWith("drain-")), [
    "drain-a",
    "drain-b",
  ]);
  assertEquals(f.read().workers.c.status, "idle");
});
test("full b keeps eight accepting slots with c during a long drain and queued work succeeds", async (f) => {
  f.state.workers.b.status = "busy";
  f.state.workers.b.activeDispatchIds = ["1", "2", "3", "4"];
  f.hold("hold-drain-a");
  f.start();
  await f.waitFor("drain-a");
  const live = f.read();
  assertEquals(live.workers.b.capacity + live.workers.c.capacity, 8);
  const queued = await new Deno.Command("timeout", {
    args: ["5", `${f.root}/queued-dispatch`],
    env: f.env,
  }).output();
  assert(queued.success, decoder.decode(queued.stderr));
  assert(f.events().includes("follow-on-success-c"));
  assertEquals(f.read().workers.a.status, "draining");
  f.release("hold-drain-a");
  const events = await f.result();
  assert(!events.includes("verify-b")); // Pinned verify rejects at_capacity.
  assert(!events.includes("drain-c"));
});
