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
  probe_error?: Partial<Record<Slot, string>>;
  drain_on_probe?: Slot;
  fill_on_probe?: Slot;
  stop_usec?: number;
  journal_error?: Slot;
  starting?: Slot[];
  lose_on_restart?: Slot;
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
    const slot = args[1]?.split("@")[1]?.[0];
    const current = state.workers[slot];
    if (args[0] === "show") {
        console.log("LoadState=loaded\nActiveState=" + (current.status === "disconnected" ? "inactive" : "active") + "\nUnitFileState=" + (slot === "c" ? "disabled" : "enabled"));
    } else if (args[0] === "is-enabled") {
        console.log(slot === "c" ? "disabled" : "enabled");
        Deno.exit(slot === "c" ? 1 : 0);
    } else if (args[0] === "start") {
        event("start-" + slot);
        state.starting = [...(state.starting ?? []), slot]; save();
    } else if (["restart", "stop"].includes(args[0])) {
        const others = Object.entries(state.workers).filter(([key,worker]) => key !== slot && accepting(worker));
        if (others.length !== 2 || others.reduce((total,[,worker]) => total + worker.capacity,0) < 8) {
            throw new Error("renewal reduced original eight-slot accepting capacity");
        }
        event("drain-" + slot);
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
        if (state.lose_on_restart) state.workers[state.lose_on_restart].status = "disconnected";
        if (args[0] === "restart") state.starting = [...(state.starting ?? []), slot];
        save(); event("stopped-" + slot);
    } else throw new Error(JSON.stringify(args));
} else if (name === "swamp") {
    if (args[0] === "worker" && args[1] === "token" && args[2] === "create") {
        event("mint-enrollment-" + args[3]);
        console.log(JSON.stringify({token: "synthetic-enrollment-test-only"}));
    } else if (args[0] === "access" && args[1] === "token" && args[2] === "mint") {
        event("mint-server-" + args[3]);
        console.log(JSON.stringify({token: "synthetic-server-test-only"}));
    } else if (args[1] === "list") {
        for (const slot of state.starting ?? []) {
            if (exists("hold-enrollment-" + slot)) event("waiting-" + slot);
            else {
                const previous = state.workers[slot].instanceUuid;
                Object.assign(state.workers[slot], {status: "idle", instanceUuid: previous.startsWith("new-") ? previous + "-next" : "new-" + slot, enrolledAt: new Date().toISOString()});
                state.starting = state.starting.filter(value => value !== slot);
                event("enrolled-" + slot); save();
            }
        }
        console.log(JSON.stringify({workers: Object.values(state.workers)}));
    } else if (args[1] === "verify") {
        const slot = Object.keys(state.workers).find(slot => state.workers[slot].name === args[2]);
        if (!slot) throw new Error("verification used an unknown worker name: " + args[2]);
        event("verify-" + slot);
        const error = state.probe_error?.[slot] ?? (state.workers[slot].activeDispatchIds.length >= state.workers[slot].capacity ? "at capacity" : undefined);
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
    workers: {
      a: worker("a"),
      b: worker("b"),
      c: { ...worker("c"), status: "disconnected" },
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
    Deno.mkdirSync(`${this.root}/credentials`);
    for (const name of ["enrollment", "server"]) {
      Deno.writeTextFileSync(
        `${this.root}/credentials/${name}.token`,
        "synthetic-test-only",
      );
    }
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
          renewal_credential_owner: identity("-un"),
          renewal_credential_group: identity("-gn"),
          renewal_credentials_dir: `${this.root}/credentials`,
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

test("replacement accepts before old drain; base accepts before overlap retires", async (f) => {
  f.hold("hold-drain-a");
  f.hold("hold-enrollment-a");
  f.start();
  await f.waitFor("drain-a");
  assertEquals(f.read().workers.c.instanceUuid, "new-c");
  assertEquals(f.read().workers.c.status, "idle");
  assertEquals(f.read().workers.b.status, "idle");
  assert(!f.events().includes("drain-c"));
  f.release("hold-drain-a");
  await f.waitFor("waiting-a");
  assert(!f.events().includes("drain-c"));
  f.release("hold-enrollment-a");
  const events = await f.result();
  assert(events.indexOf("enrolled-c") < events.indexOf("verify-c"));
  assert(events.indexOf("verify-c") < events.indexOf("drain-a"));
  assert(events.indexOf("enrolled-a") < events.indexOf("drain-c"));
  assert(events.indexOf("verify-a") < events.indexOf("drain-c"));
  assert(!events.includes("drain-b"));
  assertEquals(f.read().workers.c.status, "disconnected");
});
test("b renews next while fresh a stays persistent", async (f) => {
  Object.assign(f.state.workers.a, {
    instanceUuid: "new-a",
    enrolledAt: fresh(),
  });
  f.start();
  const events = await f.result();
  assert(events.indexOf("verify-c") < events.indexOf("drain-b"));
  assert(events.indexOf("enrolled-b") < events.indexOf("drain-c"));
  assert(!events.includes("drain-a"));
});
test("full original peer keeps eight slots with c during the long drain", async (f) => {
  f.state.workers.b.status = "busy";
  f.state.workers.b.activeDispatchIds = ["1", "2", "3", "4"];
  f.hold("hold-drain-a");
  f.start();
  await f.waitFor("drain-a");
  const live = f.read();
  assertEquals(
    [live.workers.b, live.workers.c].reduce(
      (sum, worker) => sum + worker.capacity,
      0,
    ),
    8,
  );
  const queued = await new Deno.Command("timeout", {
    args: ["5", `${f.root}/queued-dispatch`],
    env: f.env,
  }).output();
  assert(queued.success, decoder.decode(queued.stderr));
  assert(f.events().includes("follow-on-success-c"));
  assertEquals(f.read().workers.a.status, "draining");
  f.release("hold-drain-a");
  const events = await f.result();
  assert(events.includes("drain-c"));
  assert(!events.includes("verify-b")); // Full b stays accepting; fresh a supplies spare slots.
});
test("long dispatch on c finishes while a and b accept", async (f) => {
  f.hold("hold-drain-c");
  f.start();
  await f.waitFor("drain-c");
  const live = f.read();
  assertEquals(live.workers.a.instanceUuid, "new-a");
  assertEquals(live.workers.a.status, "idle");
  assertEquals(live.workers.b.status, "idle");
  assert(!f.events().includes("drain-b"));
  f.release("hold-drain-c");
  const events = await f.result();
  assert(events.includes("dispatch-c-success"));
});
test("unavailable peer prevents all starts and drains", async (f) => {
  f.state.workers.b.status = "draining";
  f.start();
  assertEquals(await f.result(false), []);
});
for (const error of ["empty", "at capacity", "data plane failed"]) {
  test(`replacement probe ${error} prevents base drain`, async (f) => {
    f.state.probe_error = { c: error };
    f.start();
    const events = await f.result(false);
    assert(events.includes("enrolled-c"));
    assert(!events.includes("drain-a"));
    assert(!events.includes("drain-b"));
  });
}
test("replacement filling during probe prevents base drain", async (f) => {
  f.state.fill_on_probe = "c";
  f.start();
  const events = await f.result(false);
  assert(events.includes("verify-c"));
  assert(!events.includes("drain-a"));
});
test("replacement starting to drain during probe prevents base drain", async (f) => {
  f.state.drain_on_probe = "c";
  f.start();
  const events = await f.result(false);
  assert(events.includes("verify-c"));
  assert(!events.includes("drain-a"));
});
test("replacement with fewer slots prevents base drain", async (f) => {
  f.state.workers.c.capacity = 2;
  f.start();
  const events = await f.result(false);
  assert(events.includes("enrolled-c"));
  assert(!events.includes("drain-a"));
});
test("failed replacement enrollment leaves both base workers untouched", async (f) => {
  f.hold("hold-enrollment-c");
  f.start();
  const events = await f.result(false);
  assert(events.includes("waiting-c"));
  assert(!events.some((event) => event.startsWith("drain-")));
});
test("failed base enrollment preserves c and peer capacity", async (f) => {
  f.hold("hold-enrollment-a");
  f.start();
  const events = await f.result(false);
  assert(events.includes("waiting-a"));
  assert(!events.includes("drain-c"));
  assert(!events.includes("drain-b"));
  assertEquals(f.read().workers.c.status, "idle");
  assertEquals(f.read().workers.b.status, "idle");
});
test("failed renewed-base probe leaves c running", async (f) => {
  f.state.probe_error = { a: "data plane failed" };
  f.start();
  const events = await f.result(false);
  assert(events.includes("enrolled-a"));
  assert(!events.includes("drain-c"));
  assert(!events.includes("drain-b"));
});
test("failed peer during blocking drain stops before overlap retirement", async (f) => {
  f.state.lose_on_restart = "b";
  f.start();
  const events = await f.result(false);
  assert(events.includes("drain-a"));
  assert(!events.includes("drain-c"));
  assertStringIncludes(f.output, "detected after restart, no further drain");
});
test("retry retires retained overlap and ends the recovery round", async (f) => {
  Object.assign(f.state.workers.a, {
    instanceUuid: "new-a",
    enrolledAt: fresh(),
  });
  Object.assign(f.state.workers.c, {
    instanceUuid: "new-c",
    status: "idle",
    enrolledAt: fresh(),
  });
  f.start();
  const events = await f.result();
  assert(events.includes("drain-c"));
  assert(!events.includes("start-c"));
  assert(!events.includes("drain-b"));
  assert(!events.includes("drain-a"));
});
test("overlap credentials are minted privately once and reused", async (f) => {
  for (const name of ["enrollment", "server"]) {
    Deno.removeSync(`${f.root}/credentials/${name}.token`);
  }
  f.start();
  const events = await f.result();
  for (const name of ["enrollment", "server"]) {
    const event = name === "enrollment"
      ? "mint-enrollment-swamp-role-209-c"
      : "mint-server-swamp-role-209-c-access";
    assertEquals(events.filter((value) => value === event).length, 1);
    assert(events.indexOf(event) < events.indexOf("start-c"));
    assertEquals(
      Deno.statSync(`${f.root}/credentials/${name}.token`).mode! & 0o777,
      0o640,
    );
  }
  assert(!f.output.includes("synthetic-enrollment-test-only"));
  assert(!f.output.includes("synthetic-server-test-only"));
  f.state = f.read();
  f.state.workers.a.enrolledAt = new Date(Date.now() - 4 * 60 * 60 * 1000)
    .toISOString();
  f.start();
  const repeated = await f.result();
  assertEquals(repeated.filter((event) => event.startsWith("mint-")).length, 2);
});
for (const remint of [false, true]) {
  test(`${remint ? "remint ANSI" : "bootstrap quoted"} base names resolve consistently`, async (f) => {
    f.state.receipts = {};
    for (const slot of ["a", "b"] as const) {
      const name = `orbit-worker-${slot}${remint ? "-1794096000" : ""}`;
      f.state.workers[slot].name = name;
      if (remint) {
        f.state.receipts[slot] = `\x1b[32m✓ Enrolled as ${name}\x1b[0m`;
      }
    }
    f.start();
    const events = await f.result();
    assert(events.indexOf("verify-c") < events.indexOf("drain-a"));
  });
}
for (const readFailure of [false, true]) {
  test(`${readFailure ? "unreadable" : "missing"} receipt logs stopped renewal without drain`, async (f) => {
    if (readFailure) f.state.journal_error = "a";
    else f.state.receipts = { a: "" };
    f.start();
    assertEquals(await f.result(false), []);
    assertStringIncludes(f.output, "Worker renewal stopped");
    assertStringIncludes(
      f.output,
      "eight-hour session cap can still abort active dispatches",
    );
  });
}
test("fresh workers remain persistent without starting c", async (f) => {
  for (const worker of Object.values(f.state.workers)) {
    worker.enrolledAt = fresh();
  }
  f.start();
  assertEquals(await f.result(), []);
});
test("old stop allowance prevents any drain", async (f) => {
  f.state.stop_usec = 300000000;
  f.start();
  assert(!(await f.result(false)).some((event) => event.startsWith("drain-")));
});
