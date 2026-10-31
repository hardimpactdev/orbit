import {
  assertEquals,
  assertFalse,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import {
  snapshot,
  type SnapshotClient,
  type SnapshotInput,
  snapshotMetric,
} from "./maintenance_snapshot.ts";

class RedisFixture implements SnapshotClient {
  commands: string[][] = [];
  sets = new Map<string, unknown[]>([["h:measured_jobs", []], [
    "h:measured_queues",
    [],
  ]]);
  hashes = new Map<string, Record<string, unknown>>();
  supervisors: { name: string; time: number }[] = [];
  lists = new Map<string, unknown>();
  sorted = new Map<string, { score: number; value: string }[]>();
  strings = new Map<string, string>();
  beforeSnapshot?: (key: string) => void;
  refuseSnapshot = false;
  command(args: string[]): Promise<unknown> {
    return Promise.resolve().then(() => this.reply(args));
  }
  private reply(args: string[]): unknown {
    this.commands.push(args);
    const [command, key, ...rest] = args;
    if (command === "SMEMBERS") return this.sets.get(key) ?? [];
    if (command === "ZREVRANGEBYSCORE") {
      return this.supervisors.filter((v) => v.time >= Number(rest[1])).sort((
        a,
        b,
      ) => b.time - a.time).map((v) => v.name);
    }
    if (command === "HMGET") {
      return rest.map((field) => this.hashes.get(key)?.[field] ?? null);
    }
    if (command === "HGET") return this.hashes.get(key)?.[rest[0]] ?? null;
    if (command === "LLEN") return this.lists.get(key) ?? 0;
    if (command === "SET") {
      this.strings.set(key, rest[0]);
      return "OK";
    }
    if (command === "EVAL") {
      assertEquals(key, snapshotMetric);
      assertEquals(rest[0], "2");
      const [metric, destination, time, trim, wait] = rest.slice(1);
      if (this.refuseSnapshot) throw new Error("Redis command refused");
      this.beforeSnapshot?.(metric);
      const data = this.hashes.get(metric) ?? {};
      const value = JSON.stringify({
        throughput: data.throughput ?? null,
        runtime: data.runtime ?? null,
        ...(wait !== "" ? { wait: Number(wait) } : {}),
        time: Number(time),
      });
      const rows = this.sorted.get(destination) ?? [];
      if (!rows.some((row) => row.value === value)) {
        rows.push({ score: Number(time), value });
      }
      rows.sort((a, b) => a.score - b.score || a.value.localeCompare(b.value));
      const end = -Math.abs(1 + Number(trim));
      const index = end < 0 ? rows.length + end : 0;
      if (index >= 0) rows.splice(0, Math.min(index + 1, rows.length));
      this.sorted.set(destination, rows);
      this.hashes.delete(metric);
      return 1;
    }
    throw new Error("Unexpected Redis fixture command");
  }
  supervisor(
    name: string,
    time: number,
    processes: unknown,
    status = "running",
  ) {
    this.supervisors.push({ name, time });
    this.hashes.set(`h:supervisor:${name}`, {
      name,
      master: "master",
      pid: "123",
      status,
      processes: JSON.stringify(processes),
      options: JSON.stringify({ connection: "gateway" }),
    });
  }
  values(key: string) {
    return (this.sorted.get(key) ?? []).map((row) => JSON.parse(row.value));
  }
}
const input: SnapshotInput = {
  prefix: "h:",
  queue_prefix: "q:",
  trim_job: 2,
  trim_queue: 2,
  now: 100,
};

Deno.test("snapshot preserves Horizon set names, JSON metric scalars and atomic counter resets", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_jobs", ["h:job:App\\Jobs\\B", "job:App\\Jobs\\A"]);
  redis.hashes.set("h:job:App\\Jobs\\A", { throughput: "3", runtime: "12.5" });
  redis.hashes.set("h:job:App\\Jobs\\B", { throughput: "7", runtime: "2" });
  assertEquals(await snapshot(redis, input), { jobs: 2, queues: 0 });
  assertEquals(redis.values("h:snapshot:job:App\\Jobs\\A"), [{
    throughput: "3",
    runtime: "12.5",
    time: 100,
  }]);
  assertFalse(redis.hashes.has("h:job:App\\Jobs\\A"));
  assertEquals(redis.strings.get("h:last_snapshot_at"), "100");
  assertEquals(
    redis.commands.filter((args) => args[0] === "SMEMBERS").map((args) =>
      args[1]
    ),
    ["h:measured_jobs", "h:measured_queues"],
  );
  assertEquals(redis.commands.filter((args) => args[0] === "DEL"), []);
});
Deno.test("queue waits use active supervisor cutoff and sum actual process counts including paused supervisors", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_queues", ["queue:gateway:tasks"]);
  redis.supervisor("current", 100, { "gateway:tasks": 2 });
  redis.supervisor("inclusive-cutoff", 71, { "gateway:tasks": 1 }, "paused");
  redis.supervisor("expired", 70, { "gateway:tasks": 100 });
  redis.hashes.set("h:queue:gateway:tasks", { throughput: "3", runtime: "12" });
  redis.hashes.set("h:queue:tasks", { runtime: "1200" });
  redis.lists.set("q:queues:tasks", 10);
  assertEquals(await snapshot(redis, input), { jobs: 0, queues: 1 });
  assertEquals(redis.values("h:snapshot:queue:gateway:tasks"), [{
    throughput: "3",
    runtime: "12",
    wait: 4,
    time: 100,
  }]);
  assertEquals(
    redis.commands.filter((args) => args[0] === "HMGET").map((args) => args[1]),
    ["h:supervisor:current", "h:supervisor:inclusive-cutoff"],
  );
  assertEquals(redis.commands.filter((args) => args[0] === "LLEN"), [[
    "LLEN",
    "q:queues:tasks",
  ]]);
});
Deno.test("grouped queues sum each ready length times its runtime and zero processes use Horizon divisor semantics", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_queues", ["queue:gateway:builds,builds-optional"]);
  redis.supervisor("shared", 100, { "gateway:builds,builds-optional": 0 });
  redis.hashes.set("h:queue:builds", { runtime: "1000" });
  redis.hashes.set("h:queue:builds-optional", { runtime: "500" });
  redis.lists.set("q:queues:builds", 2);
  redis.lists.set("q:queues:builds-optional", 3);
  await snapshot(redis, input);
  assertEquals(
    redis.values("h:snapshot:queue:gateway:builds,builds-optional")[0].wait,
    4,
  );
});
Deno.test("snapshot matches exact supervisor queue names and does not attribute another pool's capacity", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_queues", ["queue:tasks"]);
  redis.supervisor("current", 100, { "gateway:tasks": 5 });
  redis.hashes.set("h:queue:tasks", { throughput: "1", runtime: "1000" });
  await snapshot(redis, input);
  assertEquals(redis.values("h:snapshot:queue:tasks")[0].wait, 0);
  assertEquals(redis.commands.filter((args) => args[0] === "LLEN"), []);
});
Deno.test("expired supervisor hashes can disappear between enumeration and hash read", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_queues", ["queue:gateway:tasks"]);
  redis.supervisors.push({ name: "vanished", time: 100 });
  await snapshot(redis, input);
  assertEquals(redis.values("h:snapshot:queue:gateway:tasks"), [{
    throughput: null,
    runtime: null,
    wait: 0,
    time: 100,
  }]);
});
Deno.test("unsupported queue backend and malformed supervisor metadata fail before any metric resets", async () => {
  for (
    const bad of [{ "other:tasks": 2 }, ["gateway:tasks"], {
      "gateway:tasks": -1,
    }]
  ) {
    const redis = new RedisFixture();
    const queue = Array.isArray(bad) ? "gateway:tasks" : Object.keys(bad)[0];
    redis.sets.set("h:measured_jobs", ["job:Example"]);
    redis.hashes.set("h:job:Example", { throughput: "4", runtime: "5" });
    redis.sets.set("h:measured_queues", [`queue:${queue}`]);
    redis.supervisor("bad", 100, bad);
    await assertRejects(() => snapshot(redis, input));
    assertEquals(redis.commands.filter((args) => args[0] === "EVAL"), []);
    assertEquals(redis.hashes.get("h:job:Example"), {
      throughput: "4",
      runtime: "5",
    });
    assertFalse(redis.strings.has("h:last_snapshot_at"));
  }
});
Deno.test("malformed ready size or runtime cannot become a fabricated zero wait", async () => {
  for (const invalid of ["invalid", null, -1, {}]) {
    const redis = new RedisFixture();
    redis.sets.set("h:measured_queues", ["queue:gateway:tasks"]);
    redis.supervisor("active", 100, { "gateway:tasks": 1 });
    redis.lists.set("q:queues:tasks", invalid);
    // Null represents a corrupt ready-count reply here, rather than a missing list (LLEN returns integer zero).
    const command = redis.command.bind(redis);
    redis.command = (args) =>
      args[0] === "LLEN" ? Promise.resolve(invalid) : command(args);
    await assertRejects(() => snapshot(redis, input));
    assertEquals(redis.commands.filter((args) => args[0] === "EVAL"), []);
  }
  const redis = new RedisFixture();
  redis.sets.set("h:measured_queues", ["queue:gateway:tasks"]);
  redis.supervisor("active", 100, { "gateway:tasks": 1 });
  redis.hashes.set("h:queue:tasks", { runtime: "private invalid runtime" });
  await assertRejects(() => snapshot(redis, input));
  assertFalse(redis.strings.has("h:last_snapshot_at"));
});
Deno.test("rank trim retains the configured count and zero retains none", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_jobs", ["job:Example"]);
  for (let now = 100; now < 104; now++) {
    redis.hashes.set("h:job:Example", { throughput: "1", runtime: "2" });
    await snapshot(redis, { ...input, now });
  }
  assertEquals(
    redis.values("h:snapshot:job:Example").map((value) => value.time),
    [102, 103],
  );
  await snapshot(redis, { ...input, now: 104, trim_job: 0 });
  assertEquals(redis.values("h:snapshot:job:Example"), []);
});
Deno.test("increments arriving before the atomic snapshot are included and refused writes leave counters intact", async () => {
  const redis = new RedisFixture();
  redis.sets.set("h:measured_jobs", ["job:Example"]);
  redis.hashes.set("h:job:Example", { throughput: "1", runtime: "2" });
  redis.beforeSnapshot = (key) =>
    redis.hashes.set(key, { throughput: "2", runtime: "3" });
  await snapshot(redis, input);
  assertEquals(redis.values("h:snapshot:job:Example")[0].throughput, "2");
  redis.hashes.set("h:job:Example", { throughput: "5", runtime: "7" });
  redis.refuseSnapshot = true;
  await assertRejects(() => snapshot(redis, { ...input, now: 200 }));
  assertEquals(redis.hashes.get("h:job:Example"), {
    throughput: "5",
    runtime: "7",
  });
  assertEquals(redis.strings.get("h:last_snapshot_at"), "100");
});
Deno.test("empty snapshot updates timestamp without supervisor reads, locks or receipts", async () => {
  const redis = new RedisFixture();
  assertEquals(await snapshot(redis, input), { jobs: 0, queues: 0 });
  assertEquals(redis.commands.map((args) => args[0]), [
    "SMEMBERS",
    "SMEMBERS",
    "SET",
  ]);
});
