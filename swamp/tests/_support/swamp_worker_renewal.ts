/** Exercise the production Ansible renewal through real modules and disposable command stubs. */
import { assert, assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";

export const decoder = new TextDecoder();
export const playbook =
  new URL("../../ansible/swamp-worker-renewal.yml", import.meta.url).pathname;
export const hasAnsible = new Deno.Command("sh", {
  args: ["-c", "command -v ansible-playbook"],
}).outputSync().success;
export const identity = (flag: string) =>
  decoder.decode(
    new Deno.Command("id", { args: [flag] }).outputSync().stdout,
  ).trim();
export const slots = ["a", "b", "c"] as const;
export type Slot = typeof slots[number];
export interface Worker {
  name: string;
  status: string;
  instanceUuid: string;
  capacity: number;
  activeDispatchIds: string[];
  enrolledAt: string;
}
export interface State {
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
export const stub = String.raw`
export const root = Deno.env.get("RENEWAL_TEST_DIR");
export const path = root + "/state.json";
let state = JSON.parse(Deno.readTextFileSync(path));
export const [command, ...args] = Deno.args;
export const name = command.split("/").at(-1);
export const accepting = worker => ["idle", "busy"].includes(worker.status);
export const exists = name => { try { Deno.statSync(root + "/" + name); return true; }
    catch (error) { if (error instanceof Deno.errors.NotFound) return false; throw error; } };
export const pause = () => new Promise(resolve => setTimeout(resolve, 20));
export const save = () => {
    Deno.writeTextFileSync(path + ".new", JSON.stringify(state));
    Deno.renameSync(path + ".new", path);
};
export const event = message => Deno.writeTextFileSync(root + "/events", message + "\n", {append: true});
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

export const quote = (value: string) => "'" + value.replaceAll("'", "'\\''") + "'";
export function worker(slot: Slot): Worker {
  return {
    name: `swamp-role-209-${slot}`,
    status: "idle",
    instanceUuid: `old-${slot}`,
    capacity: 4,
    activeDispatchIds: [],
    enrolledAt: new Date(Date.now() - 4 * 60 * 60 * 1000).toISOString(),
  };
}
export class Fixture {
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
export function test(name: string, run: (f: Fixture) => Promise<void>) {
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
export const fresh = () => new Date().toISOString();


export const remintTasks =
  new URL("../../ansible/tasks/gateway-swamp-remint.yml", import.meta.url)
    .pathname;
