import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { TransferSqlite, transferSqliteActions } from "./transfer_sqlite.ts";
import type { FileContext, Snapshot } from "../database/sqlite_actions.ts";
const pin = "a".repeat(64);
const plan = TransferSqlite.parse({
  owner_instance_id: 11,
  source_instance_id: 11,
  participant_node_ids: [9, 17],
  source: {
    variant: "seed",
    node_id: 9,
    target_instance_id: 11,
    base: "/srv/source",
    source_path: "/srv/source/db.sqlite",
    source_user: "orbit",
    transport_user: "orbit",
    operation: pin,
    state_dir:
      `/var/lib/orbit/app-instance-sqlite-seeds/source-11-target-11-${pin}`,
    snapshot_path: `/tmp/orbit-sqlite-${pin}.sqlite`,
  },
  target: {
    node_id: 17,
    target_instance_id: 11,
    base: "/srv/destination",
    target_user: "orbit",
    transport_user: "orbit",
    operation: pin,
    state_dir: `/var/lib/orbit/app-instance-sqlite-seeds/target-11-${pin}`,
    environment: "development",
    relative_path: "db.sqlite",
  },
});
const snapshot: Snapshot = {
  model_id: "1995a0ac-99da-4860-abf0-8b09260710fa",
  name: "snapshot-43a17cb2-f652-48ad-91a4-3ba2d921caae",
  version: 1,
  bytes: 4096,
  sha256: pin,
};
const ctx = {} as FileContext;
Deno.test("SQLite parent refreshes after preparation and receive, then installs the exact snapshot", async () => {
  const calls: string[] = [];
  const actions = transferSqliteActions(
    undefined,
    (target, mode, ref) => {
      assertEquals(target, plan.target);
      assertEquals(ref, snapshot);
      calls.push(mode);
      return Promise.resolve(
        mode === "prepare"
          ? {
            state: "ready",
            incoming: "/tmp/orbit-sqlite-aaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb",
          }
          : { state: "changed" },
      );
    },
    (target, ref) => {
      assertEquals(ref, snapshot);
      assertEquals(target.base, plan.target.base);
      calls.push("receive");
      return Promise.resolve();
    },
  );
  await actions.install(plan, snapshot, ctx, () => {
    calls.push("refresh");
    return Promise.resolve();
  });
  assertEquals(calls, [
    "refresh",
    "prepare",
    "refresh",
    "receive",
    "refresh",
    "install",
  ]);
});
for (const cancelAt of [2, 3]) {
  Deno.test(`SQLite cancellation at refresh ${cancelAt} prevents install`, async () => {
    const calls: string[] = [];
    let reads = 0;
    const actions = transferSqliteActions(undefined, (_target, mode) => {
      calls.push(mode);
      return Promise.resolve({
        state: "ready",
        incoming: "/tmp/orbit-sqlite-aaaaaaaaaaaaaaaa-bbbbbbbbbbbbbbbb",
      });
    }, () => {
      calls.push("receive");
      return Promise.resolve();
    });
    await assertRejects(
      () =>
        actions.install(plan, snapshot, ctx, () => {
          if (++reads === cancelAt) throw new Error("operation.cancelled");
          return Promise.resolve();
        }),
      Error,
      "operation.cancelled",
    );
    assertEquals(calls.includes("install"), false);
  });
}
Deno.test("SQLite already installed skips receive and reinstallation", async () => {
  const calls: string[] = [];
  const actions = transferSqliteActions(undefined, (_target, mode) => {
    calls.push(mode);
    return Promise.resolve({ state: "complete" });
  }, () => {
    throw new Error("unexpected receive");
  });
  await actions.install(plan, snapshot, ctx, () => Promise.resolve());
  assertEquals(calls, ["prepare"]);
});
Deno.test("SQLite environment merge contains only the two admitted DB paths", async () => {
  const actions = transferSqliteActions(
    undefined,
    undefined,
    undefined,
    undefined,
    (action) => {
      assertEquals(action.input.managed_keys, ["DB_CONNECTION", "DB_DATABASE"]);
      assertEquals(action.input.base, "/srv/destination/subapp");
      assertEquals(
        action.input.content,
        "DB_CONNECTION=sqlite\nDB_DATABASE='/srv/destination/db.sqlite'\n",
      );
      return Promise.resolve({ state: "changed" });
    },
  );
  await actions.environment(plan, "/srv/destination/subapp/.env");
  await assertRejects(() => actions.environment(plan, "/srv/other/.env"));
  await assertRejects(() =>
    actions.environment({
      ...plan,
      target: { ...plan.target, relative_path: "evil'path" },
    }, "/srv/destination/.env")
  );
});
Deno.test("SQLite descriptor refuses a foreign source/target identity or relative path", () => {
  for (
    const wrong of [
      { ...plan, source_instance_id: 99 },
      { ...plan, target: { ...plan.target, operation: "b".repeat(64) } },
      { ...plan, target: { ...plan.target, relative_path: "other.sqlite" } },
      { ...plan, participant_node_ids: [9, 99] },
    ]
  ) assertEquals(TransferSqlite.safeParse(wrong).success, false);
});
