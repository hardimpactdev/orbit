import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { issueTaskVmModelKey, revokeTaskVmModelKey } from "./model_keys.ts";
const input = { origin: "http://models.orbit:8317/" };
const key = "a".repeat(64);
const anchor = "b".repeat(64);
const management = "private-management-credential";
const privateValue = {
  model_key: key,
  model_router: { management_key: management, anchor_key: anchor },
};
const material = {
  withPrivateMaterial: async (
    use: (value: Record<string, unknown>) => Promise<void>,
  ) => await use(privateValue),
};
function fixture(initial = [anchor]) {
  let keys = [...initial];
  let anonymous = 401;
  let managementStatus = 200;
  let ignorePatch = false;
  let deleteAnchor = false;
  let deleteOther = false;
  let modelStatus: number | undefined;
  const calls: { path: string; init: RequestInit }[] = [];
  const patches: { old: string; new: string }[] = [];
  const puts: string[][] = [];
  const request: typeof fetch = (url, init = {}) => {
    const path = new URL(String(url)).pathname;
    calls.push({ path, init });
    const token = new Headers(init.headers).get("Authorization")?.slice(7);
    if (path === "/v0/management/api-keys") {
      assertEquals(token, management);
      if (init.method === "PATCH") {
        const body = JSON.parse(String(init.body));
        patches.push(body);
        if (!ignorePatch) {
          const index = keys.indexOf(body.old);
          if (index === -1) keys.push(body.new);
          else keys[index] = body.new;
        }
        if (deleteAnchor) keys = keys.filter((key) => key !== anchor);
      }
      if (init.method === "PUT") {
        const body: string[] = JSON.parse(String(init.body));
        puts.push(body);
        if (!ignorePatch) keys = [...body];
        if (deleteAnchor) keys = keys.filter((key) => key !== anchor);
        if (deleteOther) keys = keys.filter((key) => key === anchor);
      }
      return Promise.resolve(
        new Response(
          JSON.stringify({ "api-keys": keys }),
          { status: managementStatus },
        ),
      );
    }
    if (path === "/v1/models") {
      return Promise.resolve(
        new Response("", {
          status: token === undefined
            ? anonymous
            : modelStatus ?? (keys.includes(token) ? 200 : 401),
        }),
      );
    }
    return Promise.reject(new Error(management));
  };
  return {
    calls,
    patches,
    puts,
    request,
    get keys() {
      return keys;
    },
    set anonymous(value: number) {
      anonymous = value;
    },
    set managementStatus(value: number) {
      managementStatus = value;
    },
    set ignorePatch(value: boolean) {
      ignorePatch = value;
    },
    set deleteAnchor(value: boolean) {
      deleteAnchor = value;
    },
    set deleteOther(value: boolean) {
      deleteOther = value;
    },
    set modelStatus(value: number) {
      modelStatus = value;
    },
  };
}
Deno.test("native issue then revoke proves key 200 then 401 and retains anchor", async () => {
  const f = fixture([]);
  const issued = await issueTaskVmModelKey(
    input,
    async () => {},
    material,
    undefined,
    f.request,
  );
  assertEquals(issued, { issued: true });
  assertEquals(f.keys, [anchor, key]);
  assertEquals(f.patches, [
    { old: anchor, new: anchor },
    { old: key, new: key },
  ]);
  const revoked = await revokeTaskVmModelKey(
    input,
    async () => {},
    material,
    undefined,
    f.request,
  );
  assertEquals(revoked, { revoked: true });
  assertEquals(f.keys, [anchor]);
  assertEquals(f.puts, [[anchor]]);
  assertEquals(f.patches.some((p) => p.new === ""), false);
  assertEquals(
    f.patches.some((p) => p.old === anchor && p.new !== anchor),
    false,
  );
  for (const { init } of f.calls) {
    assertEquals(init.redirect, "error");
    assertEquals(init.signal instanceof AbortSignal, true);
  }
  const output = JSON.stringify({ issued, revoked });
  for (const secret of [key, anchor, management]) {
    assertEquals(output.includes(secret), false);
  }
});
Deno.test("issue of an existing key and revoke of an absent key are idempotent", async () => {
  const f = fixture([anchor, key]);
  await issueTaskVmModelKey(
    input,
    async () => {},
    material,
    undefined,
    f.request,
  );
  assertEquals(f.patches, []);
  await revokeTaskVmModelKey(
    input,
    async () => {},
    material,
    undefined,
    f.request,
  );
  f.patches.length = 0;
  f.puts.length = 0;
  await revokeTaskVmModelKey(
    input,
    async () => {},
    material,
    undefined,
    f.request,
  );
  assertEquals(f.patches, []);
  assertEquals(f.keys, [anchor]);
});
Deno.test("anonymous model access refuses before issuing a group key", async () => {
  const f = fixture();
  f.anonymous = 200;
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        async () => {},
        material,
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.patches, []);
});
Deno.test("a model key equal to the anchor can never revoke the anchor", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      revokeTaskVmModelKey(
        input,
        async () => {},
        {
          withPrivateMaterial: async (use) =>
            await use({ ...privateValue, model_key: anchor }),
        },
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.calls, []);
  assertEquals(f.keys, [anchor]);
});
Deno.test("management refusal and secret-bearing transport failures are redacted", async () => {
  const f = fixture();
  f.managementStatus = 500;
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        async () => {},
        material,
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  const error = await assertRejects(() =>
    issueTaskVmModelKey(
      input,
      async () => {},
      material,
      undefined,
      () => Promise.reject(new Error(`${management}/${key}/${anchor}`)),
    )
  );
  assertEquals(
    error instanceof Error && error.message,
    "task_vm.model_key_failed",
  );
  assertEquals(f.patches, []);
});
Deno.test("successful PATCH acknowledgement alone is insufficient", async () => {
  const f = fixture([]);
  f.ignorePatch = true;
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        async () => {},
        material,
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.patches, [{ old: anchor, new: anchor }]);
});
Deno.test("revoke refuses lost anchor even when the key was removed", async () => {
  const f = fixture([anchor, key]);
  f.deleteAnchor = true;
  await assertRejects(
    () =>
      revokeTaskVmModelKey(
        input,
        async () => {},
        material,
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.patches, []);
  assertEquals(f.puts, [[anchor]]);
});

Deno.test("revoke removes every duplicate of its key without tombstones or unrelated changes", async () => {
  const other = "c".repeat(64);
  const f = fixture([other, key, anchor, key]);
  assertEquals(
    await revokeTaskVmModelKey(
      input,
      async () => {},
      material,
      undefined,
      f.request,
    ),
    { revoked: true },
  );
  assertEquals(f.keys, [other, anchor]);
  assertEquals(f.puts, [[other, anchor]]);
  assertEquals(f.keys.includes(""), false);
  assertEquals(f.patches, []);
});

Deno.test("revoke rejects acknowledged list updates that lose unrelated keys or do not remove its key", async () => {
  for (const fault of ["ignorePatch", "deleteOther"] as const) {
    const f = fixture([anchor, "c".repeat(64), key]);
    f[fault] = true;
    await assertRejects(
      () =>
        revokeTaskVmModelKey(
          input,
          async () => {},
          material,
          undefined,
          f.request,
        ),
      Error,
      "task_vm.model_key_failed",
    );
    assertEquals(f.puts.length, 1);
  }
});
Deno.test("superseded parent refuses immediately before private mutation", async () => {
  const f = fixture([]);
  let guards = 0;
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        () => {
          if (++guards === 3) return Promise.reject(new Error(key));
          return Promise.resolve();
        },
        material,
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.patches, []);
});
Deno.test("model server failure is not retried", async () => {
  const f = fixture([anchor, key]);
  f.modelStatus = 503;
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        async () => {},
        material,
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.calls.filter((c) => c.path === "/v1/models").length, 1);
  assertEquals(f.patches, []);
});
Deno.test("credentialed or non-origin URLs and malformed private keys make no request", async () => {
  const f = fixture();
  for (
    const origin of [
      "http://secret@models.orbit",
      "http://models.orbit/other",
      "http://models.orbit/?key=secret",
      "file:///private",
    ]
  ) {
    await assertRejects(
      () =>
        issueTaskVmModelKey(
          { origin },
          async () => {},
          material,
          undefined,
          f.request,
        ),
      Error,
      "task_vm.model_key_failed",
    );
  }
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        async () => {},
        {
          withPrivateMaterial: async (use) =>
            await use({ ...privateValue, model_key: management }),
        },
        undefined,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.calls, []);
});
Deno.test("cancelled parent performs no model API request", async () => {
  const f = fixture();
  const controller = new AbortController();
  controller.abort(new Error(key));
  await assertRejects(
    () =>
      revokeTaskVmModelKey(
        input,
        async () => {},
        material,
        controller.signal,
        f.request,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.calls, []);
});

function verificationClock() {
  let now = 0;
  const pauses: number[] = [];
  return {
    now: () => now,
    pauses,
    pause: (milliseconds: number) => {
      pauses.push(milliseconds);
      now += milliseconds;
      return Promise.resolve();
    },
  };
}

for (const action of ["issue", "revoke"] as const) {
  Deno.test(`${action} waits for the acknowledged router access reload without repeating the write`, async () => {
    const f = fixture(action === "issue" ? [anchor] : [anchor, key]);
    const time = verificationClock();
    let checks = 0;
    const request: typeof fetch = (url, options) => {
      if (
        String(url).endsWith("/v1/models") &&
        new Headers(options?.headers).get("Authorization") === `Bearer ${key}`
      ) {
        checks++;
        if (checks <= 2) {
          return Promise.resolve(
            new Response("", { status: action === "issue" ? 401 : 200 }),
          );
        }
      }
      return f.request(url, options);
    };
    const call = action === "issue"
      ? issueTaskVmModelKey
      : revokeTaskVmModelKey;
    assertEquals(
      await call(input, async () => {}, material, undefined, request, time),
      action === "issue"
        ? { issued: true as const }
        : { revoked: true as const },
    );
    assertEquals(time.pauses, [100, 100]);
    assertEquals(checks, 3);
    assertEquals(f.patches.length + f.puts.length, 1);
    assertEquals(f.keys, action === "issue" ? [anchor, key] : [anchor]);
  });
}

Deno.test("persistent old access status fails after the two-second budget with no second mutation", async () => {
  for (const action of ["issue", "revoke"] as const) {
    const f = fixture(action === "issue" ? [anchor] : [anchor, key]);
    const time = verificationClock();
    let checks = 0;
    const request: typeof fetch = (url, options) => {
      if (
        String(url).endsWith("/v1/models") &&
        new Headers(options?.headers).get("Authorization") === `Bearer ${key}`
      ) {
        checks++;
        return Promise.resolve(
          new Response("", { status: action === "issue" ? 401 : 200 }),
        );
      }
      return f.request(url, options);
    };
    const call = action === "issue"
      ? issueTaskVmModelKey
      : revokeTaskVmModelKey;
    await assertRejects(
      () => call(input, async () => {}, material, undefined, request, time),
      Error,
      "task_vm.model_key_failed",
    );
    assertEquals(time.now(), 2000);
    assertEquals(checks, 20);
    assertEquals(f.patches.length + f.puts.length, 1);
  }
});

Deno.test("non-reload HTTP responses and transport failures fail immediately", async () => {
  for (const code of [403, 429, 500, 503, null]) {
    const f = fixture();
    const time = verificationClock();
    let checks = 0;
    const request: typeof fetch = (url, options) => {
      if (
        String(url).endsWith("/v1/models") &&
        new Headers(options?.headers).get("Authorization") === `Bearer ${key}`
      ) {
        checks++;
        if (code === null) return Promise.reject(new Error(management));
        return Promise.resolve(new Response("", { status: code }));
      }
      return f.request(url, options);
    };
    await assertRejects(
      () =>
        issueTaskVmModelKey(
          input,
          async () => {},
          material,
          undefined,
          request,
          time,
        ),
      Error,
      "task_vm.model_key_failed",
    );
    assertEquals(time.pauses, []);
    assertEquals(checks, 1);
    assertEquals(f.patches.length, 1);
  }
});

Deno.test("cancellation and a superseded guard stop the next access reload read", async () => {
  for (const cancel of [true, false]) {
    const f = fixture();
    const time = verificationClock();
    const controller = new AbortController();
    let checks = 0, superseded = false;
    const request: typeof fetch = (url, options) => {
      if (
        String(url).endsWith("/v1/models") &&
        new Headers(options?.headers).get("Authorization") === `Bearer ${key}`
      ) {
        checks++;
        return Promise.resolve(new Response("", { status: 401 }));
      }
      return f.request(url, options);
    };
    const clock = {
      now: time.now,
      pause: (ms: number) => {
        if (cancel) controller.abort(new Error(management));
        else superseded = true;
        return time.pause(ms);
      },
    };
    const guard = () =>
      superseded ? Promise.reject(new Error(key)) : Promise.resolve();
    await assertRejects(
      () =>
        issueTaskVmModelKey(
          input,
          guard,
          material,
          controller.signal,
          request,
          clock,
        ),
      Error,
      "task_vm.model_key_failed",
    );
    assertEquals(checks, 1);
    assertEquals(f.patches.length, 1);
  }
});

Deno.test("a newly repaired anchor may settle but anonymous access never gets a grace period", async () => {
  const f = fixture([]);
  const time = verificationClock();
  let checks = 0;
  const request: typeof fetch = (url, options) => {
    if (
      String(url).endsWith("/v1/models") &&
      new Headers(options?.headers).get("Authorization") ===
        `Bearer ${anchor}` &&
      ++checks === 1
    ) {
      return Promise.resolve(new Response("", { status: 401 }));
    }
    return f.request(url, options);
  };
  await issueTaskVmModelKey(
    input,
    async () => {},
    material,
    undefined,
    request,
    time,
  );
  assertEquals(time.pauses, [100]);
  const open = fixture();
  open.anonymous = 200;
  const openTime = verificationClock();
  await assertRejects(
    () =>
      issueTaskVmModelKey(
        input,
        async () => {},
        material,
        undefined,
        open.request,
        openTime,
      ),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(openTime.pauses, []);
  assertEquals(open.patches, []);
});
