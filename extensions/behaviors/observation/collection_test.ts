import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  collect,
  type CollectionReads,
  Plan,
  type Progress,
} from "./collection.ts";

const collection = "123e4567-e89b-42d3-a456-426614174099";
function fixture() {
  let progress: Progress | null = null;
  const calls: string[] = [];
  const plan = Plan.parse({
    collection,
    enabled: true,
    nodes: [2, 3],
    probes: [
      { node_id: 2, family: "process", kind: "Rows", ids: [1, 2, 3] },
      { node_id: 3, family: "schedule", kind: "Rows", ids: ["schedule-uuid"] },
    ],
  });
  const reads: CollectionReads = {
    plan: (id) => {
      calls.push(`plan:${id}`);
      return Promise.resolve(plan);
    },
    observe: (_id, node) => {
      calls.push(`observe:${node}`);
      return Promise.resolve();
    },
    inspect: (_id, probe) => {
      calls.push(`inspect:${probe}`);
      return Promise.resolve();
    },
    signals: () => {
      calls.push("signals");
      return Promise.resolve();
    },
  };
  const data = {
    read: () =>
      Promise.resolve(progress === null ? null : structuredClone(progress)),
    write: (value: Progress) => {
      progress = structuredClone(value);
      return Promise.resolve();
    },
  };
  return { reads, data, calls, plan, progress: () => progress };
}
Deno.test("collection observes every Node before grouped checks and signals, without filing", async () => {
  const f = fixture();
  const result = await collect(collection, f.reads, f.data);
  assertEquals(f.calls, [
    `plan:${collection}`,
    "observe:2",
    "observe:3",
    "inspect:0",
    "inspect:1",
    "signals",
  ]);
  assertEquals(result.complete, true);
});
Deno.test("disabled Tasks collection makes no probes or signal reads", async () => {
  const f = fixture();
  f.plan.enabled = false;
  await collect(collection, f.reads, f.data);
  assertEquals(f.calls, [`plan:${collection}`]);
});
Deno.test("restart after admission reuses the saved plan", async () => {
  const f = fixture();
  f.reads.observe = () => Promise.reject(new Error("interrupted"));
  await assertRejects(() => collect(collection, f.reads, f.data));
  f.reads.observe = (_id, node) => {
    f.calls.push(`observe:${node}`);
    return Promise.resolve();
  };
  await collect(collection, f.reads, f.data);
  assertEquals(f.calls.filter((call) => call.startsWith("plan:")), [
    `plan:${collection}`,
  ]);
});
Deno.test("lost read reply retries its original collection and probe; successful groups stay saved", async () => {
  const f = fixture();
  let lose = true;
  f.reads.inspect = (id, probe) => {
    f.calls.push(`inspect:${id}:${probe}`);
    if (probe === 1 && lose) {
      lose = false;
      return Promise.reject(new Error("lost reply"));
    }
    return Promise.resolve();
  };
  await assertRejects(() => collect(collection, f.reads, f.data));
  await collect("123e4567-e89b-42d3-a456-426614174098", f.reads, f.data);
  assertEquals(f.calls.filter((call) => call.startsWith("inspect:")), [
    `inspect:${collection}:0`,
    `inspect:${collection}:1`,
    `inspect:${collection}:1`,
  ]);
  assertEquals(f.calls.filter((call) => call.startsWith("observe:")), [
    "observe:2",
    "observe:3",
  ]);
});
Deno.test("lost signals reply retries signals without repeating completed Doctor reads", async () => {
  const f = fixture();
  let lose = true;
  f.reads.signals = () => {
    f.calls.push("signals");
    if (lose) {
      lose = false;
      return Promise.reject(new Error("lost"));
    }
    return Promise.resolve();
  };
  await assertRejects(() => collect(collection, f.reads, f.data));
  await collect(collection, f.reads, f.data);
  assertEquals(f.calls.slice(-2), ["signals", "signals"]);
});
Deno.test("a completed collection receipt repeats no reads", async () => {
  const f = fixture();
  await collect(collection, f.reads, f.data);
  const count = f.calls.length;
  await collect(collection, f.reads, f.data);
  assertEquals(f.calls.length, count);
});
Deno.test("refuse a plan for a changed collection identity or an unselected Node", async () => {
  const f = fixture();
  await assertRejects(
    () => collect("123e4567-e89b-42d3-a456-426614174098", f.reads, f.data),
    Error,
    "Wrong collection receipt",
  );
  assertEquals(Plan.safeParse({ ...f.plan, nodes: [3] }).success, false);
});
