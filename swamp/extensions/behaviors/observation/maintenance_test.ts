import {
  assert,
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import { maintenance, selectMaintenance } from "./maintenance.ts";
import type { Http } from "../automatic-deploy/gateway.ts";
const now = new Date("2026-10-31T12:00:00Z"), epoch = now.getTime() / 1000;
const input = {
  routine: "maintenance",
  tasks_enabled: true,
  activity_seconds: 900,
  batch_hours: 24,
  failed_hours: 168,
  redis: null,
  activities: [],
  batches: [],
  failed: [],
};
Deno.test("interrupted selection preserves strict cutoff, ID ordering and the 500-row cap", () => {
  const activities = [
    ...Array.from(
      { length: 503 },
      (_, i) => ({ id: 600 - i, created_at: "2026-10-30T00:00:00Z" }),
    ),
    { id: 1, created_at: "2026-10-31T11:45:00Z" },
    { id: 2, created_at: "2026-10-31T11:45:01Z" },
  ];
  const result = selectMaintenance({ ...input, activities }, now);
  assertEquals(result.activity_ids.length, 500);
  assertEquals(result.activity_ids[0], 98);
  assertEquals(result.activity_ids.at(-1), 597);
  assertEquals(result.batch_ids, []);
  assertEquals(result.failed_ids, []);
});
Deno.test("retention uses exact finished, unfinished and cancelled batch predicates with one deletion per row", () => {
  const cutoff = epoch - 86400;
  const batches = [{
    id: "finished",
    created_at: cutoff - 100,
    finished_at: cutoff - 1,
    cancelled_at: null,
  }, {
    id: "boundary",
    created_at: cutoff - 100,
    finished_at: cutoff,
    cancelled_at: null,
  }, {
    id: "unfinished",
    created_at: cutoff - 1,
    finished_at: null,
    cancelled_at: null,
  }, {
    id: "cancelled",
    created_at: cutoff - 1,
    finished_at: epoch,
    cancelled_at: epoch,
  }, {
    id: "fresh-cancelled",
    created_at: cutoff,
    finished_at: null,
    cancelled_at: cutoff - 100,
  }, {
    id: "overlap",
    created_at: cutoff - 1,
    finished_at: cutoff - 1,
    cancelled_at: epoch,
  }];
  const result = selectMaintenance({
    ...input,
    routine: "retention",
    batches,
    failed: [{ id: 7, failed_at: "2026-10-24 11:59:59" }, {
      id: 8,
      failed_at: "2026-10-24 12:00:00",
    }],
  }, now);
  assertEquals(result.batch_ids, [
    "finished",
    "unfinished",
    "cancelled",
    "overlap",
  ]);
  assertEquals(result.failed_ids, [7]);
});
Deno.test("Tasks disabled suppresses batches while preserving failed-job retention and Activity finalization", () => {
  assertEquals(
    selectMaintenance({
      ...input,
      routine: "retention",
      tasks_enabled: false,
      batches: [{
        id: "old",
        created_at: 0,
        finished_at: 1,
        cancelled_at: null,
      }],
      failed: [{ id: 7, failed_at: "2026-01-01T00:00:00Z" }],
    }, now).failed_ids,
    [7],
  );
  assertEquals(
    selectMaintenance({
      ...input,
      routine: "retention",
      tasks_enabled: false,
      batches: [{
        id: "old",
        created_at: 0,
        finished_at: 1,
        cancelled_at: null,
      }],
    }, now).batch_ids,
    [],
  );
  assertEquals(
    selectMaintenance({
      ...input,
      tasks_enabled: false,
      activities: [{ id: 7, created_at: "2026-01-01T00:00:00Z" }],
    }, now).activity_ids,
    [7],
  );
});
Deno.test("invalid timestamps never prove expiry", () => {
  assertThrows(() =>
    selectMaintenance({
      ...input,
      activities: [{ id: 7, created_at: "invalid" }],
    }, now)
  );
});
const args = {
  operation_id: 7,
  node_id: 14,
  kind: "observation.maintain" as const,
  run_id: "123e4567-e89b-42d3-a456-426614174099",
};
function fixture(values: Record<string, unknown> = {}) {
  const projects: Record<string, unknown>[] = [];
  let privateReads = 0;
  let connects = 0;
  const current = {
    id: 7,
    node_id: 14,
    kind: args.kind,
    status: "queued",
    input: {
      ...input,
      activities: [{ id: 7, created_at: "2026-01-01T00:00:00Z" }],
      ...values,
    },
    result: null,
    cancel_requested: false,
  };
  const http: Http = (_method, path, body) => {
    if (path.includes("private-material")) {
      privateReads++;
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            queue_redis: {
              username: "gateway",
              password: "private-fixture-password",
            },
          },
        },
      });
    }
    if (path.endsWith("/result")) {
      projects.push(body as Record<string, unknown>);
      return Promise.resolve({ status: 200, body: { data: {} } });
    }
    return Promise.resolve({ status: 200, body: { data: current } });
  };
  const behavior = maintenance(() => http, () => {
    connects++;
    return Promise.reject(new Error("private-fixture-error"));
  });
  return {
    current,
    projects,
    behavior,
    reads: () => privateReads,
    connects: () => connects,
    context: {
      globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/unused" },
    },
  };
}
Deno.test("queue-free maintenance performs no private or Redis read and returns selected state IDs", async () => {
  const f = fixture();
  assertEquals(await f.behavior.methods.apply.execute(args, f.context), {
    status: "succeeded",
  });
  assertEquals(f.reads(), 0);
  assertEquals(f.connects(), 0);
  assertEquals(
    (f.projects.at(-1)!.result as Record<string, unknown>).activity_ids,
    [7],
  );
});
Deno.test("failed Redis snapshot retains independent Activity work and redacts private failure details", async () => {
  const f = fixture({
    redis: {
      host: "127.0.0.1",
      port: 6379,
      database: 1,
      prefix: "horizon:",
      queue_prefix: "queue:",
      trim_job: 24,
      trim_queue: 24,
    },
  });
  await assertRejects(() => f.behavior.methods.apply.execute(args, f.context));
  assertEquals(f.reads(), 1);
  assertEquals(f.connects(), 1);
  assertEquals(f.projects.at(-1)!.status, "failed");
  assertEquals(
    (f.projects.at(-1)!.result as Record<string, unknown>).activity_ids,
    [7],
  );
  assert(!JSON.stringify(f.projects).includes("private-fixture"));
});
Deno.test("cancellation and settled intents perform no snapshot or state selection", async () => {
  const f = fixture();
  f.current.cancel_requested = true;
  assertEquals(await f.behavior.methods.apply.execute(args, f.context), {
    status: "cancelled",
  });
  assertEquals(f.projects.at(-1)!.result, {});
  assertEquals(f.reads(), 0);
});

Deno.test("a settled maintenance Operation never repeats native snapshot work", async () => {
  const f = fixture();
  f.current.status = "succeeded";
  assertEquals(await f.behavior.methods.apply.execute(args, f.context), {
    status: "succeeded",
  });
  assertEquals(f.projects, []);
  assertEquals(f.reads(), 0);
  assertEquals(f.connects(), 0);
});

Deno.test("database expiry boundaries retain whole-second precision with a subsecond native clock", () => {
  const subsecond = new Date("2026-10-31T12:00:00.999Z");
  assertEquals(
    selectMaintenance({
      ...input,
      activities: [{ id: 1, created_at: "2026-10-31T11:45:00Z" }],
    }, subsecond).activity_ids,
    [],
  );
  assertEquals(
    selectMaintenance({
      ...input,
      routine: "retention",
      failed: [{ id: 1, failed_at: "2026-10-24 12:00:00" }],
    }, subsecond).failed_ids,
    [],
  );
});
