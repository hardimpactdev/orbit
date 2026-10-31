import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { destroyOwnedStep } from "./destroy_owned.ts";
const intent = {
  driver: "mysql",
  container: "orbit-process-42-proof",
  host: "10.44.0.9",
  port: 3306,
  default_open: false,
  databases: ["proof"],
  users: ["proof"],
  test_prefixes: [],
  owners: { proof: "proof" },
};
Deno.test("owned server step consumes only its admitted server password callback", async () => {
  let consumed: unknown;
  const result = await destroyOwnedStep(
    {
      type: "server",
      key: "server-42",
      server: { server_id: 42, node_id: 9, intent },
    },
    (f) =>
      f({ payload: { passwords: { "42": "private-42", "43": "private-43" } } }),
    undefined,
    undefined,
    async (_input, use) => {
      await use((material) => {
        consumed = material;
        return Promise.resolve();
      });
      return { backend: "absent" };
    },
  );
  assertEquals(result, { backend: "absent" });
  assertEquals(consumed, { password: "private-42" });
});
Deno.test("owned SQLite step uses one fixed local removal without secret reads", async () => {
  let call: unknown;
  assertEquals(
    await destroyOwnedStep(
      {
        type: "sqlite",
        key: "sqlite-2",
        sqlite: {
          connection_id: 2,
          node_id: 9,
          checkout: "/apps/proof",
          path: "/apps/proof/database/db.sqlite",
        },
      },
      () => Promise.reject(new Error("Unexpected secret")),
      undefined,
      undefined,
      undefined,
      (raw) => {
        call = raw;
        return Promise.resolve({});
      },
    ),
    { backend: "absent" },
  );
  assertEquals((call as { action: string }).action, "copy");
});
Deno.test("owned SQLite path escape refuses before the Node action", async () => {
  let called = false;
  await assertRejects(
    () =>
      destroyOwnedStep(
        {
          type: "sqlite",
          key: "sqlite-2",
          sqlite: {
            connection_id: 2,
            node_id: 9,
            checkout: "/apps/proof",
            path: "/apps/proof-other/db.sqlite",
          },
        },
        async () => {},
        undefined,
        undefined,
        undefined,
        () => {
          called = true;
          return Promise.resolve({});
        },
      ),
    Error,
    "database.destroy_owned_outcome_unknown",
  );
  assertEquals(called, false);
});
