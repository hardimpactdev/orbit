/** Exercise the production Ansible renewal through real modules and disposable command stubs. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";

const decoder = new TextDecoder();
const playbook =
  new URL("../ansible/swamp-worker-renewal.yml", import.meta.url).pathname;
const hasAnsible = new Deno.Command("sh", {
  args: ["-c", "command -v ansible-playbook"],
}).outputSync().success;
const identity = (flag: string) =>
  decoder.decode(
    new Deno.Command("id", { args: [flag] }).outputSync().stdout,
  ).trim();
const slots = ["a", "b", "c"] as const;
type Slot = typeof slots[number];
interface Worker {
  name: string;
  status: string;
  instanceUuid: string;
  capacity: number;
  activeDispatchIds: string[];
  enrolledAt: string;
}
interface State {
  workers: Record<Slot, Worker>;
  receipts?: Partial<Record<Slot, string>>;
  connections?: Partial<Record<Slot, string>>;
  probe_error?: Partial<Record<Slot, string>>;
  drain_on_probe?: Slot;
  fill_on_probe?: Slot;
  shrink_on_probe?: Slot;
  stop_usec?: number;
  journal_error?: Slot;
  starting?: Slot[];
  lose_on_restart?: Slot;
  age_peer_on_probe?: boolean;
  elapse_drain_seconds?: number;
  unit_states?: Partial<Record<Slot, string>>;
  start_limited?: Slot[];
}

// All boundary executables use this Deno stub. No Python test or fixture code.
const stub = String.raw`
const root = Deno.env.get("RENEWAL_TEST_DIR");
const path = root + "/state.json";
let state = JSON.parse(Deno.readTextFileSync(path));
const [command, ...args] = Deno.args;
const name = command.split("/").at(-1);
const accepting = worker => ["idle", "busy"].includes(worker.status);
const exists = name => { try { Deno.statSync(root + "/" + name); return true; }
    catch (error) { if (error instanceof Deno.errors.NotFound) return false; throw error; } };
const pause = () => new Promise(resolve => setTimeout(resolve, 20));
const save = () => {
    Deno.writeTextFileSync(path + ".new", JSON.stringify(state));
    Deno.renameSync(path + ".new", path);
};
const event = message => Deno.writeTextFileSync(root + "/events", message + "\n", {append: true});
if (name === "getent") {
    console.log(Deno.env.get("RENEWAL_TEST_PASSWD"));
} else if (name === "journalctl") {
    const slot = args.find(arg => arg.startsWith("--unit=")).split("@")[1][0];
    if (state.journal_error === slot) Deno.exit(124);
    const message = state.receipts?.[slot] ?? '2026-11-08T01:00:00Z [INF] worker: Enrolled as "' + state.workers[slot].name + '"';
    if (args.includes("--output=cat")) console.log(message);
    else {
        if (!args.includes("--boot=0") || !args.includes("--output=json")) throw new Error("connection receipt must use journal JSON from this boot");
        console.log(message ? JSON.stringify({MESSAGE: message, __REALTIME_TIMESTAMP:
            String(Date.parse(state.connections?.[slot] ?? state.workers[slot].enrolledAt)*1000)}) : "");
    }
} else if (name === "queued-dispatch") {
    event("queued-follow-on");
    const deadline = Date.now() + 30_000;
    while (true) {
        state = JSON.parse(Deno.readTextFileSync(path));
        const available = Object.entries(state.workers).find(([,worker]) =>
            accepting(worker) && worker.activeDispatchIds.length < worker.capacity);
        if (available) { event("follow-on-success-" + available[0]); break; }
        if (Date.now() >= deadline) throw new Error("follow-on exceeded queueTimeout: 30");
        await pause();
    }
} else if (name === "busctl") {
    console.log("t " + (state.stop_usec ?? 4500000000));
} else if (name === "systemctl") {
    const slot = args[1]?.split("@")[1]?.[0];
    const current = state.workers[slot];
    if (args[0] === "show") {
        const active = state.unit_states?.[slot] ?? "active";
        console.log(args.includes("--value") ? active : "LoadState=loaded\nActiveState=" + active + "\nUnitFileState=" + "enabled");
    } else if (args[0] === "is-enabled") {
        console.log("enabled");
    } else if (args[0] === "reset-failed") {
        event("reset-failed-" + slot);
        state.start_limited = (state.start_limited ?? []).filter(value => value !== slot); save();
    } else if (args[0] === "start") {
        if (state.start_limited?.includes(slot)) throw new Error("start-limit-hit");
        event("start-" + slot);
        state.unit_states = {...state.unit_states, [slot]: "active"};
        state.starting = [...(state.starting ?? []), slot]; save();
    } else if (["restart", "stop"].includes(args[0])) {
        event("attempt-" + args[0] + "-" + slot);
        const others = Object.entries(state.workers).filter(([key,worker]) => key !== slot && accepting(worker));
        if ((accepting(current) || args[0] === "stop") &&
            (others.length !== 2 || others.reduce((total,[,worker]) => total + worker.capacity,0) < 8)) {
            throw new Error("renewal reduced original eight-slot accepting capacity");
        }
        event((accepting(current) ? "drain-" : "recover-") + slot);
        current.status = "draining"; save();
        if (exists("hold-drain-" + slot)) {
            const deadline = Date.now() + 40_000;
            while (exists("hold-drain-" + slot)) {
                if (Date.now() >= deadline) throw new Error("drain barrier timed out");
                await pause();
            }
            event("dispatch-" + slot + "-success");
        }
        // Publish the parent thread's updates made while a long dispatch was held.
        state = JSON.parse(Deno.readTextFileSync(path));
        state.workers[slot].status = "disconnected";
        for (const [other, worker] of Object.entries(state.workers)) {
            if (other !== slot && state.elapse_drain_seconds) worker.enrolledAt =
                new Date(Date.parse(worker.enrolledAt) - state.elapse_drain_seconds*1000).toISOString();
        }
        if (state.lose_on_restart) state.workers[state.lose_on_restart].status = "disconnected";
        if (args[0] === "restart") state.starting = [...(state.starting ?? []), slot];
        save(); event("stopped-" + slot);
    } else throw new Error(JSON.stringify(args));
} else if (name === "swamp") {
    if (args[0] === "worker" && args[1] === "list") {
        for (const slot of state.starting ?? []) {
            if (exists("hold-enrollment-" + slot)) event("waiting-" + slot);
            else {
                const previous = state.workers[slot].instanceUuid;
                Object.assign(state.workers[slot], {status: "idle", instanceUuid: previous.startsWith("new-") ? previous + "-next" : "new-" + slot, enrolledAt: new Date().toISOString()});
                if (state.connections) state.connections[slot] = new Date().toISOString();
                state.starting = state.starting.filter(value => value !== slot);
                event("enrolled-" + slot); save();
            }
        }
        console.log(JSON.stringify({workers: Object.values(state.workers)}));
    } else if (args[1] === "verify") {
        const slot = Object.keys(state.workers).find(slot => state.workers[slot].name === args[2]);
        if (!slot) throw new Error("verification used an unknown worker name: " + args[2]);
        event("verify-" + slot);
        if (state.age_peer_on_probe) {
            state.connections = {...state.connections, b: new Date(Date.now() - (7*60+50)*60_000).toISOString()}; save();
        }
        const error = state.probe_error?.[slot] ?? (state.workers[slot].activeDispatchIds.length >= state.workers[slot].capacity ? "at capacity" : undefined);
        if (state.drain_on_probe === slot) { state.workers[slot].status = "draining"; save(); }
        if (state.shrink_on_probe === slot) { state.workers[slot].capacity = 2; save(); }
        if (state.fill_on_probe === slot) {
            state.workers[slot].activeDispatchIds = ["one", "two", "three", "four"]; save();
        }
        if (error === "empty") console.log(JSON.stringify({workers: [], total: 0, passed: 0, failed: 0}));
        else if (error) {
            console.log(JSON.stringify({workers: [{name: args[2], status: "error", error}], total: 1, passed: 0, failed: 1}));
            Deno.exit(1);
        } else console.log(JSON.stringify({workers: [{name: args[2], status: "pass"}], total: 1, passed: 1, failed: 0}));
    } else throw new Error(JSON.stringify(args));
} else throw new Error("unknown boundary: " + name);
`;

const quote = (value: string) => "'" + value.replaceAll("'", "'\\''") + "'";
function worker(slot: Slot): Worker {
  return {
    name: `swamp-role-209-${slot}`,
    status: "idle",
    instanceUuid: `old-${slot}`,
    capacity: 4,
    activeDispatchIds: [],
    enrolledAt: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(),
  };
}
class Fixture {
  root = Deno.makeTempDirSync({ prefix: "worker-renewal-" });
  state: State = {
    workers: {
      a: worker("a"),
      b: worker("b"),
      c: worker("c"),
    },
  };
  child?: Deno.ChildProcess;
  done?: Promise<Deno.CommandOutput>;
  completed = false;
  output = "";
  env: Record<string, string>;
  constructor() {
    const user = identity("-un");
    this.env = {
      PATH: `${this.root}:${Deno.env.get("PATH")}`,
      RENEWAL_TEST_DIR: this.root,
      RENEWAL_TEST_PASSWD: `${user}:x:${identity("-u")}:${identity("-g")}::${
        Deno.env.get("HOME")
      }:/bin/sh`,
      ANSIBLE_NOCOLOR: "1",
      ANSIBLE_LOCAL_TEMP: `${this.root}/local`,
      ANSIBLE_CONFIG: `${this.root}/ansible.cfg`,
    };
    Deno.writeTextFileSync(`${this.root}/stub.js`, stub);
    Deno.writeTextFileSync(`${this.root}/ansible.cfg`, "[defaults]\n");
    for (
      const name of [
        "swamp",
        "systemctl",
        "busctl",
        "getent",
        "journalctl",
        "queued-dispatch",
      ]
    ) {
      const path = `${this.root}/${name}`;
      Deno.writeTextFileSync(
        path,
        `#!/bin/sh\nexec ${quote(Deno.execPath())} run -A --no-config --quiet ${
          quote(this.root + "/stub.js")
        } "$0" "$@"\n`,
      );
      Deno.chmodSync(path, 0o755);
    }
  }
  write(state = this.state) {
    Deno.writeTextFileSync(`${this.root}/state.json`, JSON.stringify(state));
  }
  read(): State {
    return JSON.parse(Deno.readTextFileSync(`${this.root}/state.json`));
  }
  hold(name: string) {
    Deno.writeTextFileSync(`${this.root}/${name}`, "");
  }
  release(name: string) {
    try {
      Deno.removeSync(`${this.root}/${name}`);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
  }
  events(): string[] {
    try {
      return Deno.readTextFileSync(`${this.root}/events`).trim().split("\n")
        .filter(Boolean);
    } catch (error) {
      if (error instanceof Deno.errors.NotFound) return [];
      throw error;
    }
  }
  start(testPlaybook = playbook, extraVars: Record<string, unknown> = {}) {
    this.completed = false;
    this.write();
    this.child = new Deno.Command("timeout", {
      args: [
        "90",
        "ansible-playbook",
        "-i",
        "localhost,",
        "-c",
        "local",
        testPlaybook,
        "-e",
        JSON.stringify({
          orbit_user: identity("-un"),
          orbit_node_id: 209,
          renewal_capacity: 4,
          ansible_become: false,
          ansible_python_interpreter: "{{ ansible_playbook_python }}",
          renewal_swamp_binary: `${this.root}/swamp`,
          ...extraVars,
        }),
      ],
      env: this.env,
      stdout: "piped",
      stderr: "piped",
    }).spawn();
    this.done = this.child.output().then((result) => {
      this.completed = true;
      this.output = decoder.decode(result.stdout) +
        decoder.decode(result.stderr);
      return result;
    });
  }
  async waitFor(event: string) {
    const deadline = Date.now() + 35_000;
    while (Date.now() < deadline) {
      if (this.events().includes(event)) return;
      assert(
        !this.completed,
        `Playbook exited before ${event}: ${this.output}`,
      );
      await new Promise((resolve) => setTimeout(resolve, 20));
    }
    throw new Error(`Barrier ${event} not reached; ${this.events()}`);
  }
  async result(success = true) {
    assert(this.done, "fixture must be started");
    assertEquals((await this.done).success, success, this.output);
    return this.events();
  }
  async close() {
    for (const slot of slots) {
      this.release(`hold-drain-${slot}`);
      this.release(`hold-enrollment-${slot}`);
    }
    if (this.child && !this.completed) this.child.kill("SIGTERM");
    await this.done;
    Deno.removeSync(this.root, { recursive: true });
  }
}
function test(name: string, run: (f: Fixture) => Promise<void>) {
  Deno.test({
    name: `worker renewal: ${name}`,
    ignore: !hasAnsible,
    async fn() {
      const f = new Fixture();
      try {
        await run(f);
      } finally {
        await f.close();
      }
    },
  });
}
const fresh = () => new Date().toISOString();

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
test("failed peer during blocking drain logs detection after restart and stops", async (f) => {
  f.state.lose_on_restart = "b";
  f.start();
  const events = await f.result(false);
  assert(events.includes("drain-a"));
  assertStringIncludes(f.output, "detected after restart, no further drain");
  assert(!events.includes("drain-c"));
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

const remintTasks =
  new URL("../ansible/tasks/gateway-swamp-remint.yml", import.meta.url)
    .pathname;
// A c without any token file is made by the remint after serve stops; a c with only part of its files is refused first.
for (const missing of ["enrollment", "server", "empty server"]) {
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
for (const absent of ["directory", "files"]) {
  test(`proof remint preflight passes a c without ${absent}, which the remint makes after serve stops`, async (f) => {
    const config = `${f.root}/.config/swamp`;
    Deno.mkdirSync(config, { recursive: true });
    Deno.writeTextFileSync(`${config}/api-key`, "synthetic-test-only");
    const credentials = `${f.root}/credentials`;
    if (absent === "files") Deno.mkdirSync(`${credentials}/c`, { recursive: true });
    const tasks = parse(Deno.readTextFileSync(remintTasks)) as Array<Record<string, unknown>>;
    const stop = tasks.findIndex((task) => task.name === "Stop the workers");
    const serve = tasks.findIndex((task) => task.name === "Stop serve");
    // The worker set is made whole with serve stopped, before the first rotation.
    assertEquals(tasks[serve + 1]?.["ansible.builtin.import_tasks"], "gateway-swamp-workers.yml");
    // The remint enables the three workers, so a new c starts at boot too, and its last task waits until serve and all three run.
    const start = tasks.find((task) => task.name === "Start the workers")!;
    assertEquals([(start["ansible.builtin.systemd"] as Record<string, unknown>).enabled, start.loop], [true, ["a", "b", "c"]]);
    const wait = tasks.at(-1)!;
    assertEquals(wait.name, "Wait for serve and the workers");
    assertEquals((wait["ansible.builtin.command"] as { argv: string[] }).argv.slice(2),
      ["orbit-swamp-serve.service", "orbit-swamp-worker@a.service", "orbit-swamp-worker@b.service", "orbit-swamp-worker@c.service"]);
    assertEquals(wait.until, "swamp_remint_active.stdout_lines == ['active', 'active', 'active', 'active']");
    const testPlaybook = `${f.root}/preflight.yml`;
    Deno.writeTextFileSync(
      testPlaybook,
      JSON.stringify([{ name: "Exercise the production remint preflight", hosts: "localhost", gather_facts: false, tasks: tasks.slice(0, stop) }]),
    );
    f.start(testPlaybook, {
      control: { user: identity("-un"), repository: f.root },
      swamp_home: f.root,
      swamp_credentials: credentials,
    });
    assertEquals(await f.result(), []);
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
test("does not recover a down unit without two serving peers", async (f) => {
  f.state.workers.c.status = "disconnected";
  f.state.unit_states = { c: "failed" };
  f.state.workers.b.status = "disconnected";
  f.start();
  assertEquals(await f.result(false), []);
});

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

const workersTasks = new URL("../ansible/tasks/gateway-swamp-workers.yml", import.meta.url).pathname;
test("the worker set is made whole: only a worker without token files is minted and placed, with no secret in output", async (f) => {
  const tasks = parse(Deno.readTextFileSync(workersTasks)) as Array<Record<string, unknown>>;
  const named = (name: string) => tasks.find((task) => task.name === name)!;
  // Both control-plane plays use this one file: the first build and the remint.
  for (const file of ["gateway-swamp.yml", "gateway-swamp-remint.yml"]) {
    const imported = (parse(Deno.readTextFileSync(new URL(`../ansible/tasks/${file}`, import.meta.url))) as Array<Record<string, unknown>>)
      .filter((task) => task["ansible.builtin.import_tasks"] === "gateway-swamp-workers.yml");
    assertEquals(imported.length, 1, file);
  }
  assertEquals(named("Mint the worker tokens").loop, "{{ swamp_workers_missing }}");
  assertEquals(named("Place the worker tokens").loop, "{{ swamp_workers_missing | product(['enrollment', 'server']) | list }}");
  // Every staged file is removed, also one a cut run left behind.
  assertEquals(named("Remove the staged worker tokens").loop, "{{ ['a', 'b', 'c'] | product(['enrollment', 'server']) | list }}");
  assert(named("Mint the worker tokens").no_log && named("Place the worker tokens").no_log);
  // The real selection on a folder where a and b have their files and c has none.
  const credentials = `${f.root}/credentials`;
  for (const slot of ["a", "b"]) {
    Deno.mkdirSync(`${credentials}/${slot}`, { recursive: true });
    Deno.writeTextFileSync(`${credentials}/${slot}/server.token`, "orbit-worker-access.synthetic-test-only");
  }
  const look = tasks.findIndex((task) => task.name === "Look for the worker tokens");
  const testPlaybook = `${f.root}/workers.yml`;
  Deno.writeTextFileSync(testPlaybook, JSON.stringify([{
    name: "Select the workers without tokens", hosts: "localhost", gather_facts: false,
    tasks: [...tasks.slice(look, look + 2), { "ansible.builtin.copy": { content: "{{ swamp_workers_missing | to_json }}", dest: `${f.root}/missing.json` } }],
  }]));
  f.start(testPlaybook, { swamp_credentials: credentials });
  assertEquals(await f.result(), []);
  assertEquals(JSON.parse(Deno.readTextFileSync(`${f.root}/missing.json`)), ["c"]);
  assert(!f.output.includes("synthetic-test-only"));
});
