import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { decoder, playbook, hasAnsible, identity, slots, Slot, Worker, State, stub, root, path, name, accepting, exists, pause, save, event, quote, worker, Fixture, test, fresh, remintTasks } from "./_support/swamp_worker_renewal.ts";

test("failed re-enrollment leaves both survivors running and blocks the next round", async (f) => {
  f.hold("hold-enrollment-a");
  f.start();
  await f.result(false);
  assertEquals(f.read().workers.b.status, "idle");
  assertEquals(f.read().workers.c.status, "idle");
  f.state = f.read();
  f.state.starting = []; // Persistent failure; real systemd retains its crash backoff.
  f.start();
  await f.result(false);
  assertEquals(f.events().filter((event) => event.startsWith("drain-")), [
    "drain-a",
  ]);
});
for (const remint of [false, true]) {
  test(`${remint ? "remint ANSI" : "bootstrap quoted"} names resolve for all three units`, async (f) => {
    f.state.receipts = {};
    for (const slot of slots) {
      const name = `orbit-worker-${slot}${remint ? "-1794096000" : ""}`;
      f.state.workers[slot].name = name;
      if (remint) {
        f.state.receipts[slot] = `\x1b[32m✓ Enrolled as ${name}\x1b[0m`;
      }
    }
    f.start();
    const events = await f.result();
    assert(events.indexOf("verify-c") < events.indexOf("drain-a"));
    assert(events.includes("verify-a"));
  });
}
for (const readFailure of [false, true]) {
  test(`${readFailure ? "unreadable" : "missing"} c receipt logs stopped renewal without drain`, async (f) => {
    if (readFailure) f.state.journal_error = "c";
    else f.state.receipts = { c: "" };
    f.start();
    assertEquals(await f.result(false), []);
    assertStringIncludes(f.output, "Worker renewal stopped");
    assertStringIncludes(
      f.output,
      "eight-hour session cap can still abort active dispatches",
    );
  });
}
test("fresh workers stay persistent and no credential API exists at renewal time", async (f) => {
  for (const worker of Object.values(f.state.workers)) {
    worker.enrolledAt = fresh();
  }
  f.start();
  assertEquals(await f.result(), []);
});
test("old stop allowance prevents any drain", async (f) => {
  f.state.stop_usec = 300000000;
  f.start();
  assertEquals(await f.result(false), []);
});
for (const missing of ["directory", "enrollment", "server", "empty server"]) {
  test(`proof remint with missing c ${missing} fails before any service stops`, async (f) => {
    const config = `${f.root}/.config/swamp`;
    Deno.mkdirSync(config, { recursive: true });
    Deno.writeTextFileSync(`${config}/api-key`, "synthetic-test-only");
    const credentials = `${f.root}/credentials`;
    if (missing !== "directory") {
      Deno.mkdirSync(`${credentials}/c`, { recursive: true });
      for (const kind of ["enrollment", "server"]) {
        if (missing !== kind) {
          Deno.writeTextFileSync(
            `${credentials}/c/${kind}.token`,
            missing === "empty server" && kind === "server"
              ? ""
              : "synthetic-test-only",
          );
        }
      }
    }
    const testPlaybook = `${f.root}/remint.yml`;
    Deno.writeTextFileSync(
      testPlaybook,
      JSON.stringify([{
        name: "Exercise the real proof fleet remint",
        hosts: "localhost",
        gather_facts: false,
        tasks: [{ "ansible.builtin.import_tasks": remintTasks }],
      }]),
    );
    f.start(testPlaybook, {
      control: { user: identity("-un"), repository: f.root },
      swamp_home: f.root,
      swamp_credentials: credentials,
    });
    assertEquals(await f.result(false), []);
    assertStringIncludes(
      f.output,
      "Swamp remint stopped before stopping any service",
    );
  });
}
for (const suffix of ["", "-1794127800"]) {
  test(`proof remint preflight accepts c${suffix} without exposing tokens`, async (f) => {
    const config = `${f.root}/.config/swamp`;
    Deno.mkdirSync(config, { recursive: true });
    Deno.writeTextFileSync(`${config}/api-key`, "synthetic-test-only");
    const credentials = `${f.root}/credentials`;
    Deno.mkdirSync(`${credentials}/c`, { recursive: true });
    for (const kind of ["enrollment", "server"]) {
      Deno.writeTextFileSync(
        `${credentials}/c/${kind}.token`,
        `orbit-worker-c${
          kind === "server" ? "-access" : suffix
        }.synthetic-test-only`,
      );
    }
    const tasks = parse(Deno.readTextFileSync(remintTasks)) as Array<
      Record<string, unknown>
    >;
    const stop = tasks.findIndex((task) => task.name === "Stop the workers");
    assert(stop > 0);
    const testPlaybook = `${f.root}/preflight.yml`;
    // Execute the actual production prefix, stopping before its first mutation.
    Deno.writeTextFileSync(
      testPlaybook,
      JSON.stringify([{
        name: "Exercise the production remint preflight",
        hosts: "localhost",
        gather_facts: false,
        tasks: tasks.slice(0, stop),
      }]),
    );
    f.start(testPlaybook, {
      control: { user: identity("-un"), repository: f.root },
      swamp_home: f.root,
      swamp_credentials: credentials,
    });
    assertEquals(await f.result(), []);
    assert(!f.output.includes("synthetic-test-only"));
  });
}

for (const state of ["failed", "inactive"]) {
  test(`recovers a ${state} unit past its restart limit before voluntary renewal`, async (f) => {
    f.state.workers.c.status = "disconnected";
    f.state.unit_states = { c: state };
    f.state.start_limited = ["c"];
    f.state.receipts = { c: "" };
    f.start();
    const events = await f.result();
    assert(events.indexOf("reset-failed-c") >= 0);
    assert(events.indexOf("start-c") > events.indexOf("reset-failed-c"));
    assert(!events.some((event) => event.startsWith("drain-")));
    assertStringIncludes(f.output, "connection will be checked next round");
  });
}
for (
  const invalid of [
    "bare enrollment",
    "wrong enrollment name",
    "bare server",
    "wrong server name",
    "empty secret",
  ]
) {
  test(`proof remint rejects c ${invalid} before any service stops`, async (f) => {
    const config = `${f.root}/.config/swamp`;
    Deno.mkdirSync(config, { recursive: true });
    Deno.writeTextFileSync(`${config}/api-key`, "synthetic-test-only");
    const credentials = `${f.root}/credentials`;
    Deno.mkdirSync(`${credentials}/c`, { recursive: true });
    Deno.writeTextFileSync(
      `${credentials}/c/enrollment.token`,
      invalid === "bare enrollment"
        ? "synthetic-test-only"
        : invalid === "wrong enrollment name"
        ? "orbit-worker-b.synthetic-test-only"
        : "orbit-worker-c.synthetic-test-only",
    );
    Deno.writeTextFileSync(
      `${credentials}/c/server.token`,
      invalid === "bare server"
        ? "synthetic-test-only"
        : invalid === "wrong server name"
        ? "orbit-worker-b-access.synthetic-test-only"
        : invalid === "empty secret"
        ? "orbit-worker-c-access."
        : "orbit-worker-c-access.synthetic-test-only",
    );
    const testPlaybook = `${f.root}/remint.yml`;
    Deno.writeTextFileSync(
      testPlaybook,
      JSON.stringify([{
        name: "Exercise production remint with malformed c credentials",
        hosts: "localhost",
        gather_facts: false,
        tasks: [{ "ansible.builtin.import_tasks": remintTasks }],
      }]),
    );
    f.start(testPlaybook, {
      control: { user: identity("-un"), repository: f.root },
      swamp_home: f.root,
      swamp_credentials: credentials,
    });
    assertEquals(await f.result(false), []);
    assertStringIncludes(
      f.output,
      "Swamp remint stopped before stopping any service",
    );
    assert(!f.output.includes("synthetic-test-only"));
  });
}
