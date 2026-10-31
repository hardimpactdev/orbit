import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.13";
import {
  behavior,
  convergeWorker,
  due,
  type Native,
  plan,
} from "./rotation.ts";
import type { Http } from "../automatic-deploy/gateway.ts";
const hour = 3600000;
const day = 24 * hour;
Deno.test("persistent rotation refuses before any native token command or full Node converge", async () => {
  for (const node_id of [4, 9, 12, 13, 14, 17, 209]) {
    let nativeCalls = 0;
    let posts = 0;
    const http: Http = async (method) => {
      if (method === "POST") posts++;
      return {
        status: 200,
        body: {
          data: {
            id: node_id,
            user: "orbit",
            status: "active",
            platform: "linux",
            swamp_worker_selected: true,
          },
        },
      };
    };
    await assertRejects(
      () =>
        convergeWorker({ node_id }, http, async () => {
          nativeCalls++;
          return [];
        }),
      Error,
      "Persistent worker rotation requires credential-only delivery",
    );
    assertEquals(nativeCalls, 0);
    assertEquals(posts, 0);
  }
});
Deno.test("rotation is due at hour20 while four hours of native lifetime remain", async () => {
  const now = Date.now();
  const token = {
    name: "orbit-node-9",
    state: "enrolled",
    createdAt: new Date(now - 20 * hour).toISOString(),
    expiresAt: new Date(now + 4 * hour).toISOString(),
  };
  assertEquals(due(token, now), true);
  assertEquals(
    due({
      ...token,
      createdAt: new Date(now - hour).toISOString(),
      expiresAt: new Date(now + 23 * hour).toISOString(),
    }, now),
    false,
  );
  const call: Native = async (args) =>
    args[1] === "list"
      ? { workers: [] }
      : args[0] === "worker"
      ? [token]
      : [{ ...token, name: "orbit-node-9-access" }];
  assertEquals(await plan(call, now), [{ node_id: 9 }]);
});
Deno.test("unselected Nodes refuse before any credential changes", async () => {
  let touched = false;
  const http: Http = async () => ({
    status: 200,
    body: {
      data: {
        id: 9,
        user: "nckrtl",
        status: "active",
        platform: "linux",
        swamp_worker_selected: false,
      },
    },
  });
  await assertRejects(() =>
    convergeWorker({ node_id: 9 }, http, async () => {
      touched = true;
      return [];
    })
  );
  assertEquals(touched, false);
});

Deno.test("planner keeps undelivered generations visible and leaves connected fresh workers alone", async () => {
  const now = Date.now();
  const fresh = {
    name: "orbit-node-9",
    state: "enrolled",
    createdAt: new Date(now - hour).toISOString(),
    expiresAt: new Date(now + 23 * hour).toISOString(),
  };
  let workers: unknown[] = [];
  const call: Native = async (args) =>
    args[1] === "list"
      ? { workers }
      : args[0] === "worker"
      ? [fresh]
      : [{ ...fresh, name: "orbit-node-9-access" }];
  assertEquals(await plan(call, now), [{ node_id: 9 }]);
  workers = [{
    tokenName: fresh.name,
    status: "idle",
    labels: { node: "9" },
    enrolledAt: new Date(now - 2 * day).toISOString(),
  }];
  assertEquals(await plan(call, now), [{ node_id: 9 }]);
  workers = [{
    tokenName: fresh.name,
    status: "idle",
    labels: { node: "9" },
    enrolledAt: new Date(now).toISOString(),
  }];
  assertEquals(await plan(call, now), []);
});

Deno.test("native merged globals reach lifecycle methods without allowing unknown input", () => {
  const globals = {
    gatewayUrl: "https://gateway.orbit",
    caFile: "/public/orbit-ca.pem",
  };
  const converge = { ...globals, node_id: 9 };
  assertEquals(behavior.methods.converge.arguments.parse(converge), converge);
  assertEquals(behavior.methods.plan.arguments.parse(globals), globals);
  assertEquals(
    behavior.methods.converge.arguments.safeParse({
      ...converge,
      injected: true,
    }).success,
    false,
  );
  assertEquals(
    behavior.methods.plan.arguments.safeParse({ ...globals, node_id: 9 })
      .success,
    false,
  );
});

Deno.test("old week-long access tokens and invalid timestamps are due for bounded replacement", () => {
  const now = Date.parse("2026-10-31T07:00:00Z");
  const token = {
    name: "orbit-node-9",
    state: "active",
    createdAt: new Date(now).toISOString(),
    expiresAt: new Date(now + 30 * 24 * hour).toISOString(),
  };
  assertEquals(due(token, now), true);
  assertEquals(due({ ...token, createdAt: "invalid" }, now), true);
});
