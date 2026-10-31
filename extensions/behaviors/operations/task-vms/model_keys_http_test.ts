import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { issueTaskVmModelKey, revokeTaskVmModelKey } from "./model_keys.ts";

const key = "a".repeat(64);
const anchor = "b".repeat(64);
const management = "private-management-http-credential";
const material = {
  withPrivateMaterial: async (
    use: (value: Record<string, unknown>) => Promise<void>,
  ) =>
    await use({
      model_key: key,
      model_router: { management_key: management, anchor_key: anchor },
    }),
};
const guard = async () => {};

Deno.test("native key steps use real HTTP headers and bodies and preserve unrelated keys", async () => {
  const other = "c".repeat(64);
  let keys = [other];
  const requests: {
    url: string;
    method: string;
    auth: string | null;
    body: string;
  }[] = [];
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    async (request) => {
      const auth = request.headers.get("Authorization");
      const body = await request.text();
      requests.push({ url: request.url, method: request.method, auth, body });
      const path = new URL(request.url).pathname;
      if (
        path === "/v0/management/api-keys" && auth === `Bearer ${management}`
      ) {
        if (request.method === "PATCH") {
          const patch = JSON.parse(body);
          const index = keys.indexOf(patch.old);
          if (index === -1) keys.push(patch.new);
          else keys[index] = patch.new;
        }
        if (request.method === "PUT") keys = JSON.parse(body);
        return Response.json({ "api-keys": keys });
      }
      if (path === "/v1/models") {
        return new Response("", {
          status: auth !== null && keys.includes(auth.slice(7)) ? 200 : 401,
        });
      }
      return new Response("private-error-" + management, { status: 403 });
    },
  );
  const input = { origin: `http://127.0.0.1:${server.addr.port}` };
  try {
    assertEquals(await issueTaskVmModelKey(input, guard, material), {
      issued: true,
    });
    assertEquals(keys, [other, anchor, key]);
    assertEquals(await revokeTaskVmModelKey(input, guard, material), {
      revoked: true,
    });
    assertEquals(keys, [other, anchor]);
    assertEquals(keys.includes(""), false);
    const mutations = requests.filter((r) => r.method === "PATCH").map((r) =>
      JSON.parse(r.body)
    );
    assertEquals(mutations, [{ old: anchor, new: anchor }, {
      old: key,
      new: key,
    }]);
    assertEquals(
      requests.filter((r) => r.method === "PUT").map((r) => JSON.parse(r.body)),
      [[other, anchor]],
    );
    for (const request of requests) {
      for (const secret of [key, anchor, management]) {
        assertEquals(request.url.includes(secret), false);
      }
      assertEquals(
        ["/v1/models", "/v0/management/api-keys"].includes(
          new URL(request.url).pathname,
        ),
        true,
      );
    }
  } finally {
    await server.shutdown();
  }
});

Deno.test("model-router redirects never forward credentials to another HTTP endpoint", async () => {
  let leakedRequests = 0;
  const trap = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    () => {
      leakedRequests++;
      return new Response(management);
    },
  );
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    () =>
      new Response(management, {
        status: 307,
        headers: {
          Location: `http://127.0.0.1:${trap.addr.port}/credential-trap`,
        },
      }),
  );
  try {
    const failure = await assertRejects(() =>
      issueTaskVmModelKey(
        { origin: `http://127.0.0.1:${server.addr.port}` },
        guard,
        material,
      )
    );
    assertEquals(
      failure instanceof Error && failure.message,
      "task_vm.model_key_failed",
    );
    assertEquals(leakedRequests, 0);
  } finally {
    await server.shutdown();
    await trap.shutdown();
  }
});

Deno.test("secret-bearing malformed and oversized router replies have bounded redacted failures", async () => {
  for (
    const body of [
      JSON.stringify({ "api-keys": [key, 42], detail: management }),
      management.repeat(40000),
    ]
  ) {
    let requests = 0;
    const server = Deno.serve(
      { hostname: "127.0.0.1", port: 0, onListen() {} },
      () => {
        requests++;
        return new Response(body);
      },
    );
    try {
      const failure = await assertRejects(() =>
        issueTaskVmModelKey(
          { origin: `http://127.0.0.1:${server.addr.port}` },
          guard,
          material,
        )
      );
      assertEquals(
        failure instanceof Error && failure.message,
        "task_vm.model_key_failed",
      );
      assertEquals(requests, 1);
    } finally {
      await server.shutdown();
    }
  }
});
