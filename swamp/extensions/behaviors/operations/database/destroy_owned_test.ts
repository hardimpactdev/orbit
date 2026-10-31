import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { databaseDestroyOwned, destroyOwnedStep } from "./destroy_owned.ts";
const intent = {
  driver: "mysql" as const,
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

const args = {
  operation_id: 17,
  node_id: 209,
  kind: "database.destroy_owned" as const,
  run_id: "b52929c1-15ef-4c05-91e4-382867f16fd2",
};
const serverWork = {
  type: "server" as const,
  key: "server-42",
  server: { server_id: 42, node_id: 9, intent },
};
function fixture() {
  const current = {
    id: 17,
    node_id: 209,
    kind: args.kind,
    status: "running",
    cancel_requested: false,
    input: { servers: [serverWork.server], sqlite: [] },
  };
  let effects = 0, privateReads = 0;
  const projections: Record<string, unknown>[] = [];
  const resources: Record<string, Record<string, unknown>> = {};
  const methods = databaseDestroyOwned(
    () => (method, path, body) => {
      if (path.includes("private-material")) {
        privateReads++;
        return Promise.resolve({
          status: 200,
          body: { data: { payload: { passwords: { "42": "test-password" } } } },
        });
      }
      if (method === "POST") projections.push(body as Record<string, unknown>);
      return Promise.resolve({ status: 200, body: { data: current } });
    },
    undefined,
    () => {
      effects++;
      return Promise.resolve({ backend: "absent" as const });
    },
  ).methods;
  const context = {
    globalArgs: { gatewayUrl: "https://gateway.test", caFile: "/test-ca" },
    writeResource: (
      spec: string,
      name: string,
      data: Record<string, unknown>,
    ) => {
      resources[name] = data;
      return Promise.resolve({ name, spec });
    },
  };
  return {
    current,
    methods,
    context,
    resources,
    projections,
    counts: () => ({ effects, privateReads }),
  };
}

Deno.test("owned apply refuses wrong selected Node and changed admitted descriptor before effects", async () => {
  for (const mutation of ["node", "intent", "key", "terminal", "root"]) {
    const f = fixture();
    const work = structuredClone(serverWork);
    if (mutation === "intent") work.server.intent.databases = ["different"];
    if (mutation === "key") work.key = "server-43";
    if (mutation === "terminal") f.current.status = "succeeded";
    if (mutation === "root") f.current.node_id = 10;
    await assertRejects(
      () =>
        f.methods.apply.execute({
          ...args,
          work,
          selected_node_id: mutation === "node" ? 10 : 9,
        }, f.context),
      Error,
      "Wrong admitted removal",
    );
    assertEquals(f.counts(), { effects: 0, privateReads: 0 });
  }
});

Deno.test("owned apply reads fresh cancellation after plan and projects cancellation", async () => {
  const f = fixture();
  await f.methods.plan.execute(args, f.context);
  f.current.cancel_requested = true;
  await f.methods.apply.execute({
    ...args,
    work: serverWork,
    selected_node_id: 9,
  }, f.context);
  const result = f.resources["result-server-42"].result as { cancelled: true };
  assertEquals(result, { cancelled: true });
  await f.methods.project.execute({
    ...args,
    results: [result],
    started_at: new Date().toISOString(),
    cancelled: false,
  }, f.context);
  assertEquals(f.counts(), { effects: 0, privateReads: 0 });
  assertEquals(f.projections.at(-1)?.status, "cancelled");
});

Deno.test("owned plan never reruns a terminal root or exports its work", async () => {
  for (const status of ["succeeded", "failed", "cancelled"]) {
    const f = fixture();
    f.current.status = status;
    await assertRejects(
      () => f.methods.plan.execute(args, f.context),
      Error,
      "terminal removal",
    );
    assertEquals(f.resources, {});
    assertEquals(f.projections, []);
  }
});

Deno.test("owned aggregate retains a refusal when another placed step is cancelled", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      f.methods.project.execute({
        ...args,
        results: [{ cancelled: true }, {
          error_code: "database.ownership_unproven",
        }],
        started_at: new Date().toISOString(),
        cancelled: false,
      }, f.context),
    Error,
    "database.ownership_unproven",
  );
  assertEquals(f.projections.at(-1)?.status, "failed");
  assertEquals(f.projections.at(-1)?.result, {
    error_code: "database.ownership_unproven",
  });
});
