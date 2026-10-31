import {
  assertEquals,
  assertRejects,
  assertStringIncludes,
} from "jsr:@std/assert@1.0.19";
import { updateAiRouterAccount } from "./accounts.ts";
import { aiRouterAccounts } from "./accounts_direct.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const key = "PRIVATE_MANAGEMENT_SENTINEL";
const plan = {
  origin: "http://127.0.0.1:8317",
  account: "auth-index-42",
  disabled: true,
};
const material = {
  withPrivateMaterial: async (
    use: (raw: { router: { management_key: string } }) => Promise<void>,
  ) => {
    await use({ router: { management_key: key } });
  },
};
function upstream() {
  const f = {
    disabled: false,
    patch: 0,
    read: 0,
    ambiguous: false,
    verify: true,
    denied: false,
    paths: [] as string[],
  };
  const fetcher: typeof fetch = (url, options) =>
    Promise.resolve().then(() => {
      f.paths.push(String(url));
      assertEquals(options?.redirect, "error");
      assertEquals(
        new Headers(options?.headers).get("Authorization"),
        `Bearer ${key}`,
      );
      if (f.denied) return new Response(key, { status: 401 });
      if (options?.method === "PATCH") {
        assertEquals(JSON.parse(String(options.body)), {
          name: "plus.json",
          disabled: true,
        });
        f.patch++;
        if (f.verify) f.disabled = true;
        return new Response("{}");
      }
      f.read++;
      const file = {
        name: "plus.json",
        auth_index: "auth-index-42",
        disabled: f.disabled,
      };
      return Response.json({
        files: f.ambiguous ? [file, { ...file, name: "other.json" }] : [file],
      });
    });
  return { f, fetcher };
}
Deno.test("fresh auth index selects file name; confirms state without leaking material", async () => {
  const { f, fetcher } = upstream();
  let guards = 0;
  assertEquals(
    await updateAiRouterAccount(
      plan,
      material,
      () => {
        guards++;
        return Promise.resolve();
      },
      undefined,
      fetcher,
    ),
    { account: plan.account, disabled: true },
  );
  assertEquals([f.read, f.patch, guards], [2, 1, 1]);
  assertEquals(f.paths, [
    plan.origin + "/v0/management/auth-files",
    plan.origin + "/v0/management/auth-files/status",
    plan.origin + "/v0/management/auth-files",
  ]);
  await updateAiRouterAccount(
    plan,
    material,
    async () => {},
    undefined,
    fetcher,
  );
  assertEquals(f.patch, 1);
});
Deno.test("uses auth file name when no auth index exists", async () => {
  const fetcher: typeof fetch = () =>
    Promise.resolve(
      Response.json({ files: [{ name: "plus.json", disabled: true }] }),
    );
  assertEquals(
    await updateAiRouterAccount(
      { ...plan, account: "plus.json" },
      material,
      async () => {},
      undefined,
      fetcher,
    ),
    { account: "plus.json", disabled: true },
  );
});
for (
  const condition of [
    "denied",
    "ambiguous",
    "verification",
    "cancel-before-write",
    "transport-secret",
  ] as const
) {
  Deno.test(`refuses ${condition} with a fixed nonsecret error`, async () => {
    const { f, fetcher } = upstream();
    f.denied = condition === "denied";
    f.ambiguous = condition === "ambiguous";
    f.verify = condition !== "verification";
    const error = await assertRejects(() =>
      updateAiRouterAccount(
        plan,
        material,
        () => {
          if (condition === "cancel-before-write") throw new Error(key);
          return Promise.resolve();
        },
        undefined,
        condition === "transport-secret"
          ? () => {
            throw new Error(key);
          }
          : fetcher,
      )
    );
    assertEquals((error as Error).message, "ai_router.account_update_failed");
    assertEquals(f.patch, condition === "verification" ? 1 : 0);
  });
}
Deno.test("refuses a missing account, oversize list, or untrusted redirect without a write", async () => {
  for (
    const body of [{ files: [] }, {
      files: [{ name: "other.json", disabled: false }],
    }, "x".repeat(1024 * 1024 + 1)]
  ) {
    const fetcher: typeof fetch = (_url, options) => {
      assertEquals(options?.method, "GET");
      return Promise.resolve(Response.json(body));
    };
    await assertRejects(() =>
      updateAiRouterAccount(plan, material, async () => {}, undefined, fetcher)
    );
  }
});
Deno.test("real HTTP performs one status patch and confirms without calling the collector", async () => {
  let disabled = false;
  let writes = 0;
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    async (request) => {
      if (
        request.headers.get("Authorization") !== `Bearer ${key}`
      ) return new Response("", { status: 401 });
      if (
        new URL(request.url).pathname === "/v0/management/auth-files/status"
      ) {
        const body = await request.json();
        assertEquals(body, { name: "plus.json", disabled: true });
        disabled = body.disabled;
        writes++;
        return Response.json({ status: "ok" });
      }
      return Response.json({
        files: [{ name: "plus.json", auth_index: plan.account, disabled }],
      });
    },
  );
  try {
    const input = { ...plan, origin: `http://127.0.0.1:${server.addr.port}` };
    await updateAiRouterAccount(input, material, async () => {});
    await updateAiRouterAccount(input, material, async () => {});
    assertEquals(writes, 1);
  } finally {
    await server.shutdown();
  }
});
Deno.test("native direct method projects only public result; cancelled root reads no material", async () => {
  const args = {
    operation_id: 71,
    node_id: 17,
    kind: "ai_router.account_update" as const,
    run_id: "15ad76ad-bf85-41a0-a5ee-2f5d4e674c90",
  };
  for (const cancelled of [false, true]) {
    const operation = {
      id: 71,
      ...args,
      input: plan,
      status: "queued",
      result: null,
      cancel_requested: cancelled,
    };
    let privateReads = 0;
    const outputs: unknown[] = [];
    const http: Http = (method, path, body) =>
      Promise.resolve().then(() => {
        if (path.includes("private-material")) {
          privateReads++;
          return {
            status: 200,
            body: { data: { router: { management_key: key } } },
          };
        }
        if (method === "GET") return { status: 200, body: { data: operation } };
        outputs.push(body);
        Object.assign(operation, body);
        return { status: 200, body: { data: operation } };
      });
    const { fetcher } = upstream();
    const behavior = aiRouterAccounts(
      () => http,
      (input, material, guard, signal) =>
        updateAiRouterAccount(input, material, guard, signal, fetcher),
    );
    const result = await behavior.methods.update.execute(args, {
      globalArgs: {
        gatewayUrl: "https://gateway.orbit",
        caFile: "/test/ca.pem",
      },
    });
    assertEquals(result.status, cancelled ? "cancelled" : "succeeded");
    assertEquals(privateReads, cancelled ? 0 : 1);
    assertEquals(JSON.stringify(outputs).includes(key), false);
    if (!cancelled) {
      assertStringIncludes(JSON.stringify(outputs), '"disabled":true');
    }
  }
});

Deno.test("enables an existing disabled account and refuses credential-bearing origins", async () => {
  let disabled = true;
  let writes = 0;
  const fetcher: typeof fetch = (_url, options) =>
    Promise.resolve().then(() => {
      if (options?.method === "PATCH") {
        assertEquals(JSON.parse(String(options.body)), {
          name: "plus.json",
          disabled: false,
        });
        disabled = false;
        writes++;
        return Response.json({ status: "ok" });
      }
      return Response.json({
        files: [{ name: "plus.json", auth_index: plan.account, disabled }],
      });
    });
  const input = { ...plan, disabled: false };
  assertEquals(
    await updateAiRouterAccount(
      input,
      material,
      async () => {},
      undefined,
      fetcher,
    ),
    { account: plan.account, disabled: false },
  );
  assertEquals(writes, 1);
  await assertRejects(() =>
    updateAiRouterAccount(
      { ...input, origin: "http://user:secret@127.0.0.1:8317" },
      material,
      async () => {},
      undefined,
      fetcher,
    )
  );
  assertEquals(writes, 1);
});
