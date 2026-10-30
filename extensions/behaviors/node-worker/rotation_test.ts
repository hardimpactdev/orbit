import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.13";
import {
  behavior,
  convergeWorker,
  due,
  type Native,
  plan,
} from "./rotation.ts";
import type { Http } from "../automatic-deploy/gateway.ts";
const day = 86400000;
Deno.test("rotation is due at day20 while ten days of native lifetime remain", async () => {
  const now = Date.now();
  const token = {
    name: "orbit-node-9",
    state: "enrolled",
    createdAt: new Date(now - 20 * day).toISOString(),
    expiresAt: new Date(now + 10 * day).toISOString(),
  };
  assertEquals(due(token, now), true);
  assertEquals(
    due({
      ...token,
      createdAt: new Date(now - day).toISOString(),
      expiresAt: new Date(now + 29 * day).toISOString(),
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
Deno.test("rotation and failed-delivery recovery mint through native commands and deliver through converge", async () => {
  const now = Date.now();
  let token = {
    name: "orbit-node-9",
    state: "enrolled",
    createdAt: new Date(now - 21 * day).toISOString(),
    expiresAt: new Date(now + 9 * day).toISOString(),
    vaultName: "_token-secrets",
    secretKey: "worker-token-orbit-node-9",
  };
  let access = { ...token, name: "orbit-node-9-access", state: "active" };
  const calls: string[][] = [];
  const call: Native = async (args) => {
    calls.push(args);
    if (args[1] === "token" && args[2] === "list") {
      return args[0] === "worker" ? [token] : [access];
    }
    if (args[0] === "worker" && args[2] === "revoke") {
      token.state = "revoked";
      return {};
    }
    if (args[0] === "worker" && args[2] === "create") {
      token = {
        ...token,
        state: "unused",
        createdAt: new Date(now).toISOString(),
        expiresAt: new Date(now + 30 * day).toISOString(),
      };
      return { token: "orbit-node-9.abcdefghijklmnop" };
    }
    if (args[0] === "access" && args[2] === "rotate") {
      access = {
        ...access,
        createdAt: token.createdAt,
        expiresAt: token.expiresAt,
      };
      return {};
    }
    if (args[0] === "access" && args[2] === "reveal") {
      return { token: "orbit-node-9-access.abcdefghijklmnop" };
    }

    throw new Error("unexpected native command");
  };
  let delivered = 0;
  const http: Http = async (method, path, body) => {
    if (method === "GET") {
      return {
        status: 200,
        body: {
          data: {
            id: 9,
            user: "nckrtl",
            status: "active",
            platform: "linux",
            swamp_worker_selected: true,
          },
        },
      };
    }
    assertEquals(path, "/api/v1/nodes/9/converge");
    assertEquals(body, {
      worker_credentials: {
        enrollment_token: "orbit-node-9.abcdefghijklmnop",
        server_token: "orbit-node-9-access.abcdefghijklmnop",
      },
    });
    delivered++;
    return {
      status: 202,
      body: {
        data: {
          id: 12,
          kind: "node.converge",
          target_type: "node",
          target_id: 9,
          status: "succeeded",
          poll_after_seconds: null,
        },
      },
    };
  };
  assertEquals(await convergeWorker({ node_id: 9 }, http, call), {
    node_id: 9,
    user: "nckrtl",
    operation_id: 12,
  });
  await convergeWorker({ node_id: 9 }, http, call);
  assertEquals(delivered, 2);
  assertEquals(
    calls.filter((c) => c[0] === "worker" && c[2] === "create"),
    Array.from({ length: 2 }, () => [
      "worker",
      "token",
      "create",
      "orbit-node-9",
      "--duration",
      "30d",
    ]),
  );
  assertEquals(
    calls.filter((c) => c[0] === "access" && c[2] === "rotate").length,
    1,
  );
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

Deno.test("rotation recovers fresh undelivered enrollment but leaves connected fresh workers alone", async () => {
  const now = Date.now();
  const fresh = {
    name: "orbit-node-9",
    state: "enrolled",
    createdAt: new Date(now - day).toISOString(),
    expiresAt: new Date(now + 29 * day).toISOString(),
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

Deno.test("public Operation target is enforced on admission and status reads", async () => {
  for (const poll of [false, true]) {
    const native: Native = async (args) => {
      if (args[2] === "create") {
        return { token: "orbit-node-9.abcdefghijklmnop" };
      }
      if (args[2] === "reveal") {
        return { token: "orbit-node-9-access.abcdefghijklmnop" };
      }
      return [];
    };
    const http: Http = async (method, path) => {
      if (path === "/api/v1/nodes/9") {
        return {
          status: 200,
          body: {
            data: {
              id: 9,
              user: "nckrtl",
              status: "active",
              platform: "linux",
              swamp_worker_selected: true,
            },
          },
        };
      }
      return {
        status: method === "POST" ? 202 : 200,
        body: {
          data: {
            id: 12,
            kind: "node.converge",
            target_type: "node",
            target_id: poll && method === "POST" ? 9 : 13,
            node_id: 9,
            status: poll && method === "POST" ? "queued" : "succeeded",
          },
        },
      };
    };
    await assertRejects(
      () => convergeWorker({ node_id: 9 }, http, native),
      Error,
      "Node converge target changed",
    );
  }
});

Deno.test("public queued convergence accepts terminal null polling and retains operation identity", async () => {
  for (const foreign of [false, true]) {
    const native: Native = async (args) => {
      if (args[2] === "create") {
        return { token: "orbit-node-9.abcdefghijklmnop" };
      }
      if (args[2] === "reveal") {
        return { token: "orbit-node-9-access.abcdefghijklmnop" };
      }
      return [];
    };
    const http: Http = async (method, path) => {
      if (path === "/api/v1/nodes/9") {
        return {
          status: 200,
          body: {
            data: {
              id: 9,
              user: "nckrtl",
              status: "active",
              platform: "linux",
              swamp_worker_selected: true,
            },
          },
        };
      }
      if (method === "GET") assertEquals(path, "/api/v1/operations/12");
      return {
        status: method === "POST" ? 202 : 200,
        body: {
          data: {
            id: foreign && method === "GET" ? 13 : 12,
            kind: "node.converge",
            target_type: "node",
            target_id: 9,
            status: method === "POST" ? "queued" : "succeeded",
            poll_after_seconds: method === "POST" ? 0 : null,
          },
        },
      };
    };
    if (foreign) {
      await assertRejects(
        () => convergeWorker({ node_id: 9 }, http, native),
        Error,
        "Node converge target changed",
      );
    } else {assertEquals(await convergeWorker({ node_id: 9 }, http, native), {
        node_id: 9,
        user: "nckrtl",
        operation_id: 12,
      });}
  }
});
