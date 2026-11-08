/** Exercise the production Ansible renewal through real modules and disposable command stubs. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";

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
const slots = ["a", "b"] as const;
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
  probe_error?: Partial<Record<Slot, string>>;
  drain_on_probe?: Slot;
  fill_on_probe?: Slot;
  stop_usec?: number;
  journal_error?: Slot;
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
    console.log(state.receipts?.[slot] ?? '2026-11-08T01:00:00Z [INF] worker: Enrolled as "' + state.workers[slot].name + '"');
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
    if (args[0] === "show") {
        console.log("LoadState=loaded\nActiveState=active\nUnitFileState=enabled");
    } else if (args[0] === "restart") {
        const slot = args[1].split("@")[1][0];
        const peer = slot === "a" ? "b" : "a";
        if (!accepting(state.workers[peer])) throw new Error("both workers unavailable");
        event("drain-" + slot);
        state.workers[slot].status = "draining";
        save();
        if (slot === "a" && exists("hold-drain")) {
            const deadline = Date.now() + 40_000;
            while (exists("hold-drain")) {
                if (Date.now() >= deadline) throw new Error("drain barrier timed out");
                await pause();
            }
            event("dispatch-a-success");
        }
        state.workers[slot].status = "disconnected";
        save();
        event("stopped-" + slot);
    } else throw new Error(JSON.stringify(args));
} else if (name === "swamp") {
    if (args[1] === "list") {
        for (const slot of ["a", "b"]) {
            const worker = state.workers[slot];
            if (worker.status === "disconnected" && worker.instanceUuid === "old-" + slot) {
                if (exists("hold-enrollment")) event("waiting-" + slot);
                else {
                    Object.assign(worker, {status: "idle", instanceUuid: "new-" + slot, enrolledAt: new Date().toISOString()});
                    event("enrolled-" + slot);
                    save();
                }
            }
        }
        console.log(JSON.stringify({workers: Object.values(state.workers)}));
    } else if (args[1] === "verify") {
        const slot = Object.keys(state.workers).find(slot => state.workers[slot].name === args[2]);
        if (!slot) throw new Error("verification used an unknown worker name: " + args[2]);
        event("verify-" + slot);
        const error = state.probe_error?.[slot];
        if (state.drain_on_probe === slot) { state.workers[slot].status = "draining"; save(); }
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
    workers: { a: worker("a"), b: worker("b") },
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
  start() {
    this.write();
    this.child = new Deno.Command("timeout", {
      args: [
        "60",
        "ansible-playbook",
        "-i",
        "localhost,",
        "-c",
        "local",
        playbook,
        "-e",
        JSON.stringify({
          orbit_user: identity("-un"),
          orbit_node_id: 209,
          ansible_become: false,
          ansible_python_interpreter: "{{ ansible_playbook_python }}",
          renewal_swamp_binary: `${this.root}/swamp`,
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
    this.release("hold-drain");
    this.release("hold-enrollment");
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
function terminate(child: Deno.ChildProcess) {
  try {
    child.kill("SIGTERM");
  } catch (error) {
    if (
      !(error instanceof Deno.errors.NotFound) &&
      !(error instanceof TypeError &&
        error.message === "Child process has already terminated")
    ) throw error;
  }
}
const fresh = () => new Date().toISOString();

test("a finishes and re-enrolls before b drains", async (f) => {
  f.hold("hold-drain");
  f.hold("hold-enrollment");
  f.start();
  await f.waitFor("drain-a");
  assert(!f.events().includes("drain-b"));
  assertEquals(f.read().workers.b.status, "idle");
  f.release("hold-drain");
  await f.waitFor("waiting-a");
  assert(!f.events().includes("drain-b"));
  f.release("hold-enrollment");
  assertEquals(
    (await f.result()).filter((e) => !/^(waiting|stopped)-/.test(e)),
    [
      "verify-b",
      "drain-a",
      "dispatch-a-success",
      "enrolled-a",
      "verify-a",
      "verify-a",
      "drain-b",
      "enrolled-b",
      "verify-b",
    ],
  );
});
test("unavailable peer prevents drain", async (f) => {
  f.state.workers.b.status = "draining";
  f.start();
  assertEquals(await f.result(false), []);
});
test("empty live check prevents drain despite idle record", async (f) => {
  f.state.probe_error = { b: "empty" };
  f.start();
  assertEquals(await f.result(false), ["verify-b"]);
});
test("full survivor defers without draining either worker", async (f) => {
  f.state.workers.b.activeDispatchIds = ["one", "two", "three", "four"];
  f.start();
  assertEquals(await f.result(false), []);
});
test("native capacity refusal defers without drain", async (f) => {
  f.state.probe_error = { b: "Worker 'swamp-role-209-b' is at capacity" };
  f.start();
  assertEquals(await f.result(false), ["verify-b"]);
});
test("capacity refusal cannot hide a peer that started draining", async (f) => {
  f.state.probe_error = { b: "Worker 'swamp-role-209-b' is at capacity" };
  f.state.drain_on_probe = "b";
  f.start();
  assertEquals(await f.result(false), ["verify-b"]);
});
test("survivor filling during probe defers without drain", async (f) => {
  f.state.fill_on_probe = "b";
  f.start();
  assertEquals(await f.result(false), ["verify-b"]);
});
test("queued follow-on finishes on survivor while a drains", async (f) => {
  f.hold("hold-drain");
  f.state.workers.b.activeDispatchIds = ["one", "two", "three"];
  f.start();
  await f.waitFor("drain-a");
  const state = f.read();
  state.workers.b.activeDispatchIds.push("four");
  f.write(state);
  const queued = new Deno.Command("timeout", {
    args: ["30", `${f.root}/queued-dispatch`],
    env: f.env,
    stdout: "null",
    stderr: "piped",
  }).spawn();
  try {
    await f.waitFor("queued-follow-on");
    assert(!f.events().includes("follow-on-success-b"));
    state.workers.b.activeDispatchIds.pop();
    f.write(state);
    assert((await queued.status).success);
    assert(f.events().includes("follow-on-success-b"));
    assert(!f.events().includes("drain-b"));
    assertEquals(f.read().workers.a.status, "draining");
  } finally {
    // output() closes the piped stderr and reaps the process even on assertion failure.
    terminate(queued);
    await queued.output();
  }
  f.release("hold-drain");
  await f.result();
});
for (const remint of [false, true]) {
  test(`${remint ? "remint ANSI" : "bootstrap quoted"} names resolve consistently`, async (f) => {
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
    assert(events.indexOf("enrolled-a") < events.indexOf("drain-b"));
  });
}
test("missing receipt logs stopped renewal and eight-hour risk without drain", async (f) => {
  f.state.receipts = { a: "" };
  f.start();
  assertEquals(await f.result(false), []);
  assertStringIncludes(f.output, "Worker renewal stopped");
  assertStringIncludes(
    f.output,
    "eight-hour session cap can still abort active dispatches",
  );
});
test("journal read failure logs the public renewal-stopped error", async (f) => {
  f.state.journal_error = "a";
  f.start();
  assertEquals(await f.result(false), []);
  assertStringIncludes(f.output, "Worker renewal stopped");
  assertStringIncludes(
    f.output,
    "eight-hour session cap can still abort active dispatches",
  );
});
test("other probe errors prevent drain", async (f) => {
  f.state.probe_error = { b: "Worker 'swamp-role-209-b' is draining" };
  f.start();
  assertEquals(await f.result(false), ["verify-b"]);
});
test("new instance must pass live check before b is touched", async (f) => {
  f.state.probe_error = { a: "data plane failed" };
  f.start();
  const events = await f.result(false);
  assert(events.includes("enrolled-a"));
  assert(!events.includes("drain-b"));
});
test("failed enrollment leaves b accepting and untouched", async (f) => {
  f.hold("hold-enrollment");
  f.start();
  const events = await f.result(false);
  assert(events.includes("waiting-a"));
  assert(!events.includes("drain-b"));
  assertEquals(f.read().workers.b.status, "idle");
});
test("retry preserves freshly renewed a", async (f) => {
  Object.assign(f.state.workers.a, {
    instanceUuid: "new-a",
    enrolledAt: fresh(),
  });
  f.start();
  assertEquals(await f.result(), [
    "verify-a",
    "drain-b",
    "stopped-b",
    "enrolled-b",
    "verify-b",
  ]);
});
test("fresh workers remain persistent", async (f) => {
  for (const worker of Object.values(f.state.workers)) {
    worker.enrolledAt = fresh();
  }
  f.start();
  assertEquals(await f.result(), []);
});
test("old stop allowance prevents any restart", async (f) => {
  f.state.stop_usec = 300000000;
  f.start();
  assertEquals(await f.result(false), ["verify-b"]);
});
