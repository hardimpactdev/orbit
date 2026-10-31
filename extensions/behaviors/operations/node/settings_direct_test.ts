import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { Prepare, prepareStorage, settings } from "./settings.ts";

const input = {
  agent_pin: "a".repeat(64),
  storage: {
    root: "/srv/apps",
    user: "orbit",
    create_missing: true,
    protected_roots: ["/etc", "/var/lib/orbit"],
    managed_checkouts: ["/srv/checkout"],
  },
};
const args = {
  operation_id: 7,
  node_id: 209 as const,
  kind: "node.settings" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const context = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
};
function fixture() {
  const operation = {
    id: 7,
    node_id: 209,
    kind: "node.settings",
    status: "queued",
    input,
    result: null,
    cancel_requested: false,
  };
  const calls: { method: string; path: string; body: unknown }[] = [];
  const http = (method: string, path: string, body?: unknown) => {
    calls.push({ method, path, body });
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  return { operation, calls, http };
}
Deno.test("shared direct writer reads admitted input, calls one local action and projects success", async () => {
  const f = fixture();
  let actions = 0;
  await settings((actual) => {
    assertEquals(actual, input);
    actions++;
    return Promise.resolve(true);
  }, () => f.http).methods.execute.execute(args, context);
  assertEquals(actions, 1);
  assertEquals(f.calls.map((c) => c.path), [
    "/api/v1/direct-operations/7?run_id=" + args.run_id,
    "/api/v1/direct-operations/7/result",
    "/api/v1/direct-operations/7/result",
  ]);
  assertEquals((f.calls.at(-1)!.body as { result: unknown }).result, {
    prepared: true,
  });
});
Deno.test("typed local refusal projects its bounded error", async () => {
  const f = fixture();
  await assertRejects(() =>
    settings(() => Promise.resolve(false), () => f.http).methods.execute
      .execute(args, context)
  );
  assertEquals(
    (f.calls.at(-1)!.body as { error_code: string }).error_code,
    "node.settings_root_failed",
  );
});
Deno.test("an unbound first read never starts the local action", async () => {
  let actions = 0;
  await assertRejects(() =>
    settings(() => {
      actions++;
      return Promise.resolve(true);
    }, () => () => Promise.resolve({ status: 409, body: {} })).methods.execute
      .execute(args, context)
  );
  assertEquals(actions, 0);
});
Deno.test("another Node is refused before the local action", async () => {
  const f = fixture();
  f.operation.node_id = 10;
  let actions = 0;
  await assertRejects(() =>
    settings(() => {
      actions++;
      return Promise.resolve(true);
    }, () => f.http).methods.execute.execute(args, context)
  );
  assertEquals(actions, 0);
});
Deno.test("cancellation before the effect projects cancellation without calling root", async () => {
  const f = fixture();
  f.operation.cancel_requested = true;
  let actions = 0;
  await settings(() => {
    actions++;
    return Promise.resolve(true);
  }, () => f.http).methods.execute.execute(args, context);
  assertEquals(actions, 0);
  assertEquals(
    (f.calls.at(-1)!.body as { status: string }).status,
    "cancelled",
  );
});
Deno.test("uncertain local result fails natively without projecting false success", async () => {
  const f = fixture();
  await assertRejects(() =>
    settings(() => Promise.reject(new Error("uncertain")), () => f.http).methods
      .execute.execute(args, context)
  );
  assertEquals(f.calls.length, 2);
  assertEquals((f.calls.at(-1)!.body as { status: string }).status, "running");
});

Deno.test("settings arguments accept positive Node identities and refuse invalid identities", () => {
  const schema = settings().methods.execute.arguments;
  assertEquals(schema.safeParse(args).success, true);
  assertEquals(schema.safeParse({ ...args, node_id: 9 }).success, true);
  assertEquals(schema.safeParse({ ...args, node_id: 0 }).success, false);
});

Deno.test("admitted storage rejects path traversal, controls, and command-shaped fields", () => {
  for (
    const root of [
      "relative",
      "/",
      "/srv//apps",
      "/srv/../apps",
      "/srv/./apps",
      "/srv/apps\n",
    ]
  ) {
    assertEquals(
      Prepare.safeParse({ ...input, storage: { ...input.storage, root } })
        .success,
      false,
    );
  }
  assertEquals(
    Prepare.safeParse({ ...input, command: "foreign" }).success,
    false,
  );
  assertEquals(
    Prepare.safeParse({
      ...input,
      storage: { ...input.storage, user: "orbit;id" },
    }).success,
    false,
  );
});

Deno.test("a non-canary Node uses only its matching admitted local input", async () => {
  const f = fixture();
  f.operation.node_id = 9;
  let actions = 0;
  await settings((actual) => {
    assertEquals(actual, input);
    actions++;
    return Promise.resolve(true);
  }, () => f.http).methods.execute.execute({ ...args, node_id: 9 }, context);
  assertEquals(actions, 1);
});
Deno.test("a mismatched admitted Node never executes the local action", async () => {
  const f = fixture();
  let actions = 0;
  await assertRejects(() =>
    settings(() => {
      actions++;
      return Promise.resolve(true);
    }, () => f.http).methods.execute.execute({ ...args, node_id: 9 }, context)
  );
  assertEquals(actions, 0);
});

Deno.test("local account lookup selects the actual custom home and primary group", async () => {
  const calls: import("../_lib/node-agent.ts").NodeCall[] = [];
  const result = await prepareStorage(
    { ...input, storage: { ...input.storage, root: null } },
    undefined,
    (call) => {
      calls.push(call);
      return Promise.resolve(
        call.name === "account.lookup"
          ? { user: "orbit", group: "staff", home: "/srv/custom-home" }
          : {},
      );
    },
  );
  assertEquals(result, true);
  assertEquals(calls.map((call) => [call.name, call.sudo, call.mutates]), [[
    "account.lookup",
    false,
    false,
  ], ["node.prepare-storage-root", true, true]]);
  assertEquals(calls[1].input, {
    root: "/srv/custom-home/apps",
    user: "orbit",
    group: "staff",
    create_missing: true,
  });
});
Deno.test("home and admitted excluded paths refuse before filesystem mutation", async () => {
  for (
    const root of [
      "/srv/custom-home",
      "/srv",
      "/srv/custom-home/.ssh/apps",
      "/etc/apps",
      "/var/lib",
      "/srv/checkout/apps",
    ]
  ) {
    const calls: string[] = [];
    const result = await prepareStorage(
      { ...input, storage: { ...input.storage, root } },
      undefined,
      (call) => {
        calls.push(call.name);
        return Promise.resolve({
          user: "orbit",
          group: "staff",
          home: "/srv/custom-home",
        });
      },
    );
    assertEquals(result, false);
    assertEquals(calls, ["account.lookup"]);
  }
});
Deno.test("missing account refuses and a mismatched account never starts root", async () => {
  assertEquals(
    await prepareStorage(
      input,
      undefined,
      () => Promise.resolve({ error_code: "account.missing" }),
    ),
    false,
  );
  await assertRejects(() =>
    prepareStorage(
      input,
      undefined,
      () =>
        Promise.resolve({
          user: "foreign",
          group: "staff",
          home: "/srv/custom-home",
        }),
    )
  );
});
