import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  directOperation,
  DirectOperationCancelled,
  GatewayOperation,
} from "./direct.ts";

const args = {
  operation_id: 71,
  kind: "sample.apply",
  node_id: null,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
function fixture() {
  const projected: Record<string, unknown>[] = [];
  let effects = 0;
  const gateway = {
    read: () =>
      Promise.resolve({
        id: 71,
        kind: args.kind,
        node_id: null,
        status: "queued",
        result: null,
        cancel_requested: false,
      }),
    project: (data: Record<string, unknown>) => {
      projected.push(data);
      return Promise.resolve(null);
    },
  };
  return {
    gateway,
    projected,
    action: () => {
      effects++;
      return Promise.resolve({ changed: true });
    },
    effects: () => effects,
  };
}
Deno.test("shared template supports a typed local action with no Gateway effect call", async () => {
  const f = fixture();
  assertEquals(await directOperation(args, f.gateway, f.action, (r) => r), {
    status: "succeeded",
  });
  assertEquals(f.effects(), 1);
  assertEquals(f.projected.map((p) => p.status), ["running", "succeeded"]);
  assertEquals(f.projected[1].result, { changed: true });
});
Deno.test("shared template gives a local Node action only the admitted safe input", async () => {
  const f = fixture();
  const read = f.gateway.read;
  const seen: unknown[] = [];
  await directOperation(args, {
    ...f.gateway,
    read: async () => ({ ...await read(), input: { instance_id: 42 } }),
  }, (input) => {
    seen.push(input);
    return Promise.resolve({ ready: true });
  }, (r) => r);
  assertEquals(seen, [{ instance_id: 42 }]);
});
Deno.test("shared template refuses foreign operation identity before action or projection", async () => {
  for (
    const change of [{ id: 72 }, { kind: "foreign.apply" }, { node_id: 9 }]
  ) {
    const f = fixture();
    const read = f.gateway.read;
    const gateway = {
      ...f.gateway,
      read: async () => ({ ...await read(), ...change }),
    };
    await assertRejects(
      () => directOperation(args, gateway, f.action, (r) => r),
      Error,
      "does not match",
    );
    assertEquals(f.effects(), 0);
    assertEquals(f.projected, []);
  }
});
Deno.test("shared template leaves native failure handling in Swamp and never retries an action", async () => {
  const f = fixture();
  let attempts = 0;
  await assertRejects(() =>
    directOperation(args, f.gateway, () => {
      attempts++;
      throw new Error("uncertain external result");
    }, (r) => r)
  );
  assertEquals(attempts, 1);
  assertEquals(f.projected.map((p) => p.status), ["running"]);
});
Deno.test("shared template checks cancellation before a local Node action", async () => {
  const f = fixture();
  const controller = new AbortController();
  controller.abort();
  assertEquals(
    await directOperation(
      args,
      f.gateway,
      f.action,
      (r) => r,
      controller.signal,
    ),
    { status: "cancelled" },
  );
  assertEquals(f.effects(), 0);
});
Deno.test("shared template projects safe family refusals and rejects free-form diagnostics", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      directOperation(
        args,
        f.gateway,
        () => Promise.resolve({ error_code: "sample.refused" }),
        (r) => r,
      ),
    Error,
    "sample.refused",
  );
  assertEquals(f.projected[1].error_code, "sample.refused");
  const g = fixture();
  await assertRejects(() =>
    directOperation(
      args,
      g.gateway,
      () => Promise.resolve({ error_code: "x".repeat(101) }),
      (r) => r,
    )
  );
  assertEquals(g.projected.length, 1);
});
Deno.test("shared gateway client binds every projection to the workflow run and hides remote diagnostics", async () => {
  const calls: unknown[] = [];
  const gateway = new GatewayOperation(
    (_m, path, body) => {
      calls.push({ path, body });
      return Promise.resolve({ status: 200, body: { data: {} } });
    },
    args,
  );
  await gateway.project({ status: "running", run_id: "foreign" });
  assertEquals(calls, [{
    path: "/api/v1/direct-operations/71/result",
    body: { status: "running", run_id: args.run_id },
  }]);
  const bad = new GatewayOperation(
    () =>
      Promise.resolve({ status: 503, body: { error: "private credential" } }),
    args,
  );
  await assertRejects(
    () => bad.read(),
    Error,
    "Direct Operation request did not succeed",
  );
});

Deno.test("native first read waits only for an unbound run before any projection or effect", async () => {
  const f = fixture();
  const calls: string[] = [];
  let bound = false;
  const gateway = new GatewayOperation(
    async (method, path, body) => {
      calls.push(method);
      assertEquals(path.startsWith("/api/v1/direct-operations/71"), true);
      if (!bound) {
        assertEquals(method, "GET");
        assertEquals(f.effects(), 0);
        return {
          status: 409,
          body: { error: { code: "operation.run_unbound" } },
        };
      }
      if (method === "POST") {
        await f.gateway.project(
          body as Record<string, unknown>,
        );
      }
      return { status: 200, body: { data: await f.gateway.read() } };
    },
    args,
    () => {
      bound = true;
      return Promise.resolve();
    },
  );

  await directOperation(args, gateway, f.action, (r) => r);

  assertEquals(calls, ["GET", "GET", "POST", "POST"]);
  assertEquals(f.effects(), 1);
});

Deno.test("native binding wait stops on its deadline or cancellation without effects", async () => {
  for (const cancel of [false, true]) {
    let now = 0;
    let reads = 0;
    const controller = new AbortController();
    const gateway = new GatewayOperation(
      () => {
        reads++;
        return Promise.resolve({
          status: 409,
          body: { error: { code: "operation.run_unbound" } },
        });
      },
      args,
      () => {
        now = 90_000;
        if (cancel) controller.abort(new Error("native cancellation"));
        return Promise.resolve();
      },
      () => now,
    );

    await assertRejects(
      () => gateway.read(controller.signal),
      Error,
      cancel ? "native cancellation" : "deadline expired",
    );
    assertEquals(reads, 1);
  }
});

Deno.test("native first read never waits for other errors and never retries a mutation", async () => {
  for (
    const [status, code] of [[409, "operation.run_mismatch"], [
      403,
      "node.access_denied",
    ], [503, "operation.run_unbound"]] as const
  ) {
    let calls = 0;
    const gateway = new GatewayOperation(
      () => {
        calls++;
        return Promise.resolve({ status, body: { error: { code } } });
      },
      args,
      () => {
        throw new Error("must not wait");
      },
    );
    await assertRejects(() => gateway.read(), Error, "request did not succeed");
    assertEquals(calls, 1);
  }
  let calls = 0;
  const gateway = new GatewayOperation(
    () => {
      calls++;
      return Promise.resolve({
        status: 409,
        body: { error: { code: "operation.run_unbound" } },
      });
    },
    args,
    () => {
      throw new Error("must not wait");
    },
  );
  await assertRejects(() => gateway.primitive(), Error, "not bound");
  assertEquals(calls, 1);
});

Deno.test("native cancellation bounds an unresolved first read without effects", async () => {
  const controller = new AbortController();
  let calls = 0;
  const gateway = new GatewayOperation(() => {
    calls++;
    return new Promise(() => {});
  }, args);
  const pending = gateway.read(controller.signal);
  controller.abort(new Error("cancel pending read"));
  await assertRejects(() => pending, Error, "cancel pending read");
  assertEquals(calls, 1);
});

Deno.test("a read-only pending wait observes Gateway cancellation before accepting an applied result", async () => {
  const f = fixture();
  let cancelRequested = false;
  let status = "pending";
  const gateway = {
    ...f.gateway,
    read: async () => ({
      ...await f.gateway.read(),
      cancel_requested: cancelRequested,
    }),
  };
  const result = await directOperation(args, gateway, async () => {
    assertEquals(status, "pending");
    cancelRequested = true;
    status = "applied";
    const current = await gateway.read();
    if (current.cancel_requested) {
      throw new DirectOperationCancelled({ effectRunning: false });
    }
    return { status };
  }, (r) => r);

  assertEquals(result, { status: "cancelled" });
  assertEquals(f.effects(), 0);
  assertEquals(f.projected.map((p) => p.status), ["running", "cancelled"]);
  assertEquals(f.projected[1].result, {});
  assertEquals(f.projected[1].error_code, null);
});

Deno.test("only explicit no-effect cancellation can end a running direct action as cancelled", async () => {
  for (
    const error of [
      new Error("uncertain root mutation"),
      new DOMException("native abort during mutation", "AbortError"),
    ]
  ) {
    const f = fixture();
    await assertRejects(() =>
      directOperation(args, f.gateway, () => {
        throw error;
      }, (r) => r)
    );
    assertEquals(f.projected.map((p) => p.status), ["running"]);
  }
  const f = fixture();
  const controller = new AbortController();
  assertEquals(
    await directOperation(
      args,
      f.gateway,
      () => {
        controller.abort();
        return Promise.resolve({ applied: true });
      },
      (r) => r,
      controller.signal,
    ),
    { status: "succeeded" },
  );
  assertEquals(f.projected[1].result, { applied: true });
});

function tokenFixture(change: Record<string, unknown> = {}) {
  return {
    github: {
      repository: "hardimpactdev/orbit",
      permissions: { contents: "write", pull_requests: "write" },
      token: "ephemeral-sentinel",
      expires_at: new Date(Date.now() + 3_600_000).toISOString(),
      ...change,
    },
  };
}
const revokeOk: typeof fetch = () =>
  Promise.resolve(new Response(null, { status: 204 }));
Deno.test("shared GitHub mint requests only the bound run and keeps the credential inside the callback", async () => {
  const requests: unknown[] = [];
  const gateway = new GatewayOperation(
    (method, path, body) => {
      requests.push({ method, path, body });
      return Promise.resolve({ status: 200, body: { data: tokenFixture() } });
    },
    args,
    undefined,
    undefined,
    revokeOk,
  );
  const result = await gateway.withGitHubToken((token, grant) => {
    assertEquals(token, "ephemeral-sentinel");
    assertEquals(grant.repository, "hardimpactdev/orbit");
    assertEquals(grant.permissions, {
      contents: "write",
      pull_requests: "write",
    });
    return Promise.resolve({ completed: true });
  });
  assertEquals(result, { completed: true });
  assertEquals(requests, [{
    method: "GET",
    path: `/api/v1/direct-operations/71/private-material?run_id=${args.run_id}`,
    body: undefined,
  }]);
});
Deno.test("shared GitHub mint refuses unsafe scope expiry and transport replies without secret diagnostics", async () => {
  for (
    const data of [
      tokenFixture({ permissions: { administration: "write" } }),
      tokenFixture({ expires_at: new Date(Date.now() - 1).toISOString() }),
      tokenFixture({
        expires_at: new Date(Date.now() + 7_200_000).toISOString(),
      }),
      tokenFixture({ token: "ephemeral-sentinel\n" }),
      { token: "ephemeral-sentinel" },
    ]
  ) {
    let called = false;
    const gateway = new GatewayOperation(
      () => Promise.resolve({ status: 200, body: { data } }),
      args,
      undefined,
      undefined,
      revokeOk,
    );
    const error = await assertRejects(
      () =>
        gateway.withGitHubToken(() => {
          called = true;
          return Promise.resolve({});
        }),
      Error,
      "operation.github_token_unavailable",
    );
    assertEquals(called, false);
    assertEquals(String(error).includes("ephemeral-sentinel"), false);
  }
  const gateway = new GatewayOperation(
    () => {
      throw new Error("ephemeral-sentinel");
    },
    args,
    undefined,
    undefined,
    revokeOk,
  );
  await assertRejects(
    () => gateway.withGitHubToken(() => Promise.resolve({})),
    Error,
    "operation.github_token_unavailable",
  );
});
Deno.test("shared GitHub callback failures and attempted credential outputs are sanitized", async () => {
  const makeGateway = () =>
    new GatewayOperation(
      () => Promise.resolve({ status: 200, body: { data: tokenFixture() } }),
      args,
      undefined,
      undefined,
      revokeOk,
    );
  await assertRejects(
    () =>
      makeGateway().withGitHubToken((token) => {
        throw new Error(token);
      }),
    Error,
    "operation.github_action_failed",
  );
  for (
    const leak of [
      (token: string) => ({ token }),
      (token: string) => ({ nested: { url: "prefix-" + token } }),
    ]
  ) {
    const error = await assertRejects(
      () =>
        makeGateway().withGitHubToken((token) => Promise.resolve(leak(token))),
      Error,
      "operation.github_result_invalid",
    );
    assertEquals(String(error).includes("ephemeral-sentinel"), false);
  }
});

Deno.test("shared GitHub result serialization cannot leak through thrown diagnostics or stateful toJSON", async () => {
  const makeGateway = () =>
    new GatewayOperation(
      () => Promise.resolve({ status: 200, body: { data: tokenFixture() } }),
      args,
      undefined,
      undefined,
      revokeOk,
    );
  for (
    const action of [
      (token: string) => ({
        toJSON() {
          throw new Error(token);
        },
      }),
      (token: string) => ({
        get value() {
          throw new Error(token);
        },
      }),
    ]
  ) {
    const error = await assertRejects(
      () =>
        makeGateway().withGitHubToken((token) =>
          Promise.resolve(action(token))
        ),
      Error,
      "operation.github_result_invalid",
    );
    assertEquals(String(error).includes("ephemeral-sentinel"), false);
  }
  let serializations = 0;
  const result = await makeGateway().withGitHubToken((token) =>
    Promise.resolve({
      toJSON() {
        return ++serializations === 1 ? { ready: true } : { token };
      },
    })
  );
  assertEquals(result, { ready: true });
  assertEquals(JSON.stringify(result), '{"ready":true}');
  assertEquals(serializations, 1);
});

Deno.test("shared GitHub credentials revoke on success action failure and invalid result", async () => {
  for (const mode of ["success", "failure", "leak"]) {
    let calls = 0;
    let callbackEnded = false;
    const github: typeof fetch = (url, init) => {
      calls++;
      assertEquals(callbackEnded, true);
      assertEquals(url, "https://api.github.com/installation/token");
      assertEquals(init?.method, "DELETE");
      assertEquals(init?.redirect, "error");
      assertEquals(
        new Headers(init?.headers).get("Authorization"),
        "Bearer ephemeral-sentinel",
      );
      return Promise.resolve(new Response(null, { status: 204 }));
    };
    const gateway = new GatewayOperation(
      () => Promise.resolve({ status: 200, body: { data: tokenFixture() } }),
      args,
      undefined,
      undefined,
      github,
    );
    const action = (token: string) => {
      callbackEnded = true;
      if (mode === "failure") throw new Error(token);
      return Promise.resolve(mode === "leak" ? { token } : { completed: true });
    };
    if (mode === "success") {
      assertEquals(await gateway.withGitHubToken(action), { completed: true });
    } else {await assertRejects(
        () => gateway.withGitHubToken(action),
        Error,
        mode === "failure"
          ? "operation.github_action_failed"
          : "operation.github_result_invalid",
      );}
    assertEquals(calls, 1);
  }
});

Deno.test("shared GitHub revoke failures preserve a successful write and report only a fixed diagnostic", async () => {
  const originalWarn = console.warn;
  try {
    for (const fail of [false, true]) {
      const warnings: unknown[][] = [];
      console.warn = (...values: unknown[]) => {
        warnings.push(values);
      };
      let writes = 0;
      const gateway = new GatewayOperation(
        () => Promise.resolve({ status: 200, body: { data: tokenFixture() } }),
        args,
        undefined,
        undefined,
        () => {
          if (fail) throw new Error("ephemeral-sentinel");
          return Promise.resolve(
            new Response("ephemeral-sentinel", { status: 503 }),
          );
        },
      );
      const result = await gateway.withGitHubToken(() => {
        writes++;
        return Promise.resolve({ completed: true });
      });
      assertEquals(result, { completed: true });
      assertEquals(writes, 1);
      assertEquals(warnings, [["operation.github_token_revoke_failed"]]);
    }
  } finally {
    console.warn = originalWarn;
  }
});

Deno.test("shared GitHub expiry validation uses the injected clock", async () => {
  const now = Date.parse("2030-01-02T03:04:05Z");
  for (const offset of [-1, 3_600_000, 3_605_001]) {
    let calls = 0;
    const gateway = new GatewayOperation(
      () =>
        Promise.resolve({
          status: 200,
          body: {
            data: tokenFixture({
              expires_at: new Date(now + offset).toISOString(),
            }),
          },
        }),
      args,
      undefined,
      () => now,
      revokeOk,
    );
    const action = () => {
      calls++;
      return Promise.resolve({ completed: true });
    };
    if (offset === 3_600_000) {
      assertEquals(await gateway.withGitHubToken(action), { completed: true });
    } else {await assertRejects(
        () => gateway.withGitHubToken(action),
        Error,
        "operation.github_token_unavailable",
      );}
    assertEquals(calls, offset === 3_600_000 ? 1 : 0);
  }
});

Deno.test("a revoke failure cannot replace the GitHub action refusal", async () => {
  const originalWarn = console.warn;
  const warnings: unknown[][] = [];
  try {
    console.warn = (...values: unknown[]) => {
      warnings.push(values);
    };
    const gateway = new GatewayOperation(
      () => Promise.resolve({ status: 200, body: { data: tokenFixture() } }),
      args,
      undefined,
      undefined,
      () => {
        throw new Error("ephemeral-sentinel");
      },
    );
    await assertRejects(
      () =>
        gateway.withGitHubToken(() => {
          throw new Error("ephemeral-sentinel");
        }),
      Error,
      "operation.github_action_failed",
    );
    assertEquals(warnings, [["operation.github_token_revoke_failed"]]);
  } finally {
    console.warn = originalWarn;
  }
});

Deno.test("shared GitHub mint permits only one attempt per step even when called concurrently", async () => {
  let mints = 0;
  let revokes = 0;
  const gateway = new GatewayOperation(
    () => {
      mints++;
      return Promise.resolve({ status: 200, body: { data: tokenFixture() } });
    },
    args,
    undefined,
    undefined,
    () => {
      revokes++;
      return Promise.resolve(new Response(null, { status: 204 }));
    },
  );
  const first = gateway.withGitHubToken(() =>
    Promise.resolve({ completed: true })
  );
  await assertRejects(
    () => gateway.withGitHubToken(() => Promise.resolve({})),
    Error,
    "operation.github_token_already_used",
  );
  await first;
  await assertRejects(
    () => gateway.withGitHubToken(() => Promise.resolve({})),
    Error,
    "operation.github_token_already_used",
  );
  assertEquals(mints, 1);
  assertEquals(revokes, 1);
});
