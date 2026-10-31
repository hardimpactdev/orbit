import { assertEquals, assertRejects, assertThrows } from "jsr:@std/assert@1";
import {
  DestroyDatabase,
  destroyDatabaseWork,
  removeDestroyDatabase,
} from "./destroy_database.ts";

function fixture() {
  return {
    owner_instance_id: 775,
    intent_id: 4321,
    connection_ids: [11, 12],
    servers: [{
      server_id: 5,
      node_id: 4,
      intent: {
        driver: "mysql" as const,
        container: "orbit-process-5-mysql",
        host: "127.0.0.1",
        port: 3306,
        default_open: false,
        databases: ["own775"],
        users: ["own775"],
        test_prefixes: [],
        owners: { own775: "own775" },
      },
    }],
    sqlite: [{
      connection_id: 12,
      node_id: 9,
      checkout: "/srv/apps/proof/main",
      path: "/srv/apps/proof/main/database.sqlite",
    }],
  };
}

Deno.test("aggregate database work preserves SQL and SQLite placement and keys", () => {
  const input = fixture();
  assertEquals(destroyDatabaseWork(input), [{
    type: "server",
    key: "server-5",
    node_id: 4,
    server: input.servers[0],
  }, {
    type: "sqlite",
    key: "sqlite-12",
    node_id: 9,
    sqlite: input.sqlite[0],
  }]);
  assertThrows(() =>
    DestroyDatabase.parse({
      ...input,
      servers: [...input.servers, ...input.servers],
    })
  );
  assertThrows(() => DestroyDatabase.parse({ ...input, connection_ids: [11] }));
});

Deno.test("foreign or changed database work refuses before reading private material", async () => {
  const input = fixture(), work = destroyDatabaseWork(input)[0];
  let reads = 0, effects = 0;
  const gateway = {
    withPrivateMaterial: () => {
      reads++;
      return Promise.resolve();
    },
  };
  const action = () => {
    effects++;
    return Promise.resolve({ backend: "absent" as const });
  };
  await assertRejects(
    () => removeDestroyDatabase(work, 9, input, gateway, undefined, action),
    Error,
    "database.removal_conflict",
  );
  await assertRejects(
    () =>
      removeDestroyDatabase(
        { ...work, key: "server-99" },
        4,
        input,
        gateway,
        undefined,
        action,
      ),
    Error,
    "database.removal_conflict",
  );
  assertEquals({ reads, effects }, { reads: 0, effects: 0 });
});

Deno.test("SQL lower consumes only the selected parent database field inside the awaited callback", async () => {
  const input = fixture(), work = destroyDatabaseWork(input)[0];
  let inside = false;
  const material = { passwords: { "5": "callback-only-secret" } };
  const gateway = {
    withPrivateMaterial: async (
      use: (data: Record<string, unknown>) => Promise<void>,
    ) => {
      inside = true;
      try {
        await use({
          database_material: material,
          git_read: { github_token: "unrelated" },
        });
      } finally {
        inside = false;
      }
    },
  };
  const result = await removeDestroyDatabase(
    work,
    4,
    input,
    gateway,
    undefined,
    async (_work, use) => {
      await use((raw) => {
        assertEquals(inside, true);
        assertEquals(raw, { payload: material });
        return Promise.resolve();
      });
      return { backend: "absent" };
    },
  );
  assertEquals(result, { backend: "absent" });
  assertEquals(inside, false);
});

Deno.test("native database refusal blocks source finalization instead of becoming absent", async () => {
  const input = fixture(), work = destroyDatabaseWork(input)[0];
  await assertRejects(
    () =>
      removeDestroyDatabase(
        work,
        4,
        input,
        { withPrivateMaterial: () => Promise.resolve() },
        undefined,
        () => Promise.resolve({ error_code: "database.in_use" }),
      ),
    Error,
    "database.in_use",
  );
});

Deno.test("cancellation before placed database work performs no private read or effect", async () => {
  const input = fixture(), work = destroyDatabaseWork(input)[0];
  let calls = 0;
  await assertRejects(() =>
    removeDestroyDatabase(
      work,
      4,
      input,
      {
        withPrivateMaterial: () => {
          calls++;
          return Promise.resolve();
        },
      },
      AbortSignal.abort(),
      () => {
        calls++;
        return Promise.resolve({ backend: "absent" });
      },
    )
  );
  assertEquals(calls, 0);
});

Deno.test("foreign SQL participant consumes its selected view without connection_ids or another Node descriptors", async () => {
  const input = fixture(), work = destroyDatabaseWork(input)[0];
  const selected = {
    owner_instance_id: input.owner_instance_id,
    intent_id: input.intent_id,
    servers: input.servers,
    sqlite: [],
  };
  let ran = false;
  const result = await removeDestroyDatabase(
    work,
    4,
    selected,
    { withPrivateMaterial: () => Promise.resolve() },
    undefined,
    () => {
      ran = true;
      return Promise.resolve({ backend: "absent" });
    },
  );
  assertEquals(result, { backend: "absent" });
  assertEquals(ran, true);
});
