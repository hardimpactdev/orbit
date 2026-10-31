import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { githubApp, registerApp, syncApp } from "./mod.ts";
import type { Result } from "../_lib/direct.ts";

const code = "one-time-code-do-not-record";
const pem = "-----BEGIN PRIVATE KEY-----\n" + "x".repeat(64) +
  "\n-----END PRIVATE KEY-----";
const jwt = "header.payload.signature";
const now = Date.parse("2026-10-31T08:00:00Z");
function fixture(material: Result) {
  const outputs: Result[] = [];
  const projections: Result[] = [];
  const gateway = {
    read: () =>
      Promise.resolve({
        id: 5,
        kind: "github.app_register",
        node_id: 14,
        status: "queued",
        input: { registration_id: "a".repeat(64) },
        result: null,
        cancel_requested: false,
      }),
    project: (value: Result) => {
      projections.push(value);
      return Promise.resolve({});
    },
    withPrivateMaterial: async (use: (value: Result) => Promise<void>) =>
      await use(material),
    storeSecretOutput: (value: Result) => {
      outputs.push(value);
      return Promise.resolve();
    },
  };
  return { gateway, outputs, projections };
}
const app = {
  id: 12,
  slug: "orbit-test",
  name: "Orbit test",
  owner: { login: "hardimpactdev", type: "Organization" },
  html_url: "https://github.com/apps/orbit-test",
  pem,
};
const installation = (id: number, appId = 12) => ({
  id,
  app_id: appId,
  account: { login: "hardimpactdev", type: "Organization" },
  repository_selection: "selected",
  suspended_at: null,
});

Deno.test("manifest exchange stores App key only through declared secret output", async () => {
  const { gateway, outputs } = fixture({ registration_code: code });
  const request: typeof fetch = (url, options) => {
    assertEquals(
      String(url),
      `https://api.github.com/app-manifests/${code}/conversions`,
    );
    assertEquals(options?.method, "POST");
    assertEquals(options?.redirect, "error");
    assertEquals(new Headers(options?.headers).has("Authorization"), false);
    return Promise.resolve(Response.json(app));
  };
  const result = await registerApp(
    { registration_id: "a".repeat(64) },
    gateway,
    request,
  );
  assertEquals(result, { registered: true });
  assertEquals(outputs, [{
    app_credentials: {
      app_id: 12,
      slug: "orbit-test",
      name: "Orbit test",
      owner: "hardimpactdev",
      owner_type: "organization",
      url: app.html_url,
      private_key: pem,
    },
  }]);
  assertEquals(JSON.stringify(result).includes(pem), false);
  assertEquals(JSON.stringify(result).includes(code), false);
});

Deno.test("registration refusal stores no credential and no upstream text in terminal result", async () => {
  const { gateway, outputs, projections } = fixture({
    registration_code: code,
  });
  const behavior = githubApp(
    () => gateway,
    () => Promise.reject(new Error(pem + code)),
  );
  await assertRejects(() =>
    behavior.methods.register.execute({
      operation_id: 5,
      node_id: 14,
      kind: "github.app_register",
      run_id: "bad6eb15-bb98-4e58-a116-d0192c02af87",
    }, {
      globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
    })
  );
  assertEquals(outputs, []);
  assertEquals(projections.at(-1)?.result, {
    error_code: "operation.native_outcome_unknown",
  });
  const text = JSON.stringify(projections);
  assertEquals(text.includes(pem), false);
  assertEquals(text.includes(code), false);
});

Deno.test("malformed conversion and redirect refusal never store a key", async () => {
  for (
    const reply of [
      Response.json({ ...app, pem: "" }),
      new Response(null, {
        status: 302,
        headers: { Location: "https://foreign.test" },
      }),
    ]
  ) {
    const { gateway, outputs } = fixture({ registration_code: code });
    await assertRejects(() =>
      registerApp(
        { registration_id: "a".repeat(64) },
        gateway,
        () => Promise.resolve(reply),
      )
    );
    assertEquals(outputs, []);
  }
});

function syncFixture(appId = 12, expiry = now + 300_000) {
  return fixture({
    app_token: {
      app_id: appId,
      token: jwt,
      expires_at: new Date(expiry).toISOString(),
    },
  });
}

Deno.test("sync sends JWT only to fixed endpoint and projects public observations", async () => {
  const { gateway, outputs } = syncFixture();
  const request: typeof fetch = (url, options) => {
    assertEquals(
      String(url),
      "https://api.github.com/app/installations?per_page=100&page=1",
    );
    assertEquals(options?.redirect, "error");
    assertEquals(
      new Headers(options?.headers).get("Authorization"),
      `Bearer ${jwt}`,
    );
    return Promise.resolve(Response.json([installation(99)]));
  };
  const result = await syncApp({ app_id: 12 }, gateway, request, () => now);
  assertEquals(result, {
    app_id: 12,
    observed_at: new Date(now).toISOString(),
    installations: [
      {
        id: 99,
        account: "hardimpactdev",
        type: "organization",
        repositories: "selected",
        suspended: false,
      },
    ],
  });
  assertEquals(outputs, []);
  assertEquals(JSON.stringify(result).includes(jwt), false);
});

Deno.test("expired foreign and overlong JWT lifetime refuse before a network call", async () => {
  for (
    const [id, expiry] of [[13, now + 300_000], [12, now], [12, now + 606_000]]
  ) {
    const { gateway } = syncFixture(id, expiry);
    let calls = 0;
    await assertRejects(() =>
      syncApp({ app_id: 12 }, gateway, () => {
        calls++;
        return Promise.resolve(Response.json([]));
      }, () => now)
    );
    assertEquals(calls, 0);
  }
});

Deno.test("foreign and duplicate installation identities fail closed", async () => {
  for (
    const rows of [[installation(1, 13)], [installation(1), installation(1)]]
  ) {
    const { gateway } = syncFixture();
    await assertRejects(() =>
      syncApp(
        { app_id: 12 },
        gateway,
        () => Promise.resolve(Response.json(rows)),
        () => now,
      )
    );
  }
});

Deno.test("sync paginates exact App observations and refuses truncated history", async () => {
  const { gateway } = syncFixture();
  let calls = 0;
  const result = await syncApp({ app_id: 12 }, gateway, () => {
    calls++;
    return Promise.resolve(
      Response.json(
        calls === 1
          ? Array.from({ length: 100 }, (_, i) => installation(i + 1))
          : [installation(101)],
      ),
    );
  }, () => now);
  assertEquals(calls, 2);
  assertEquals((result.installations as unknown[]).length, 101);
  calls = 0;
  await assertRejects(() =>
    syncApp({ app_id: 12 }, gateway, () => {
      const offset = calls++ * 100;
      return Promise.resolve(Response.json(Array.from({ length: 100 }, (_, i) =>
        installation(offset + i + 1))));
    }, () =>
      now)
  );
  assertEquals(calls, 10);
});

Deno.test("oversized GitHub replies are refused before material storage", async () => {
  const { gateway, outputs } = fixture({ registration_code: code });
  await assertRejects(() =>
    registerApp(
      { registration_id: "a".repeat(64) },
      gateway,
      () => Promise.resolve(new Response("x".repeat(2_097_153))),
    )
  );
  assertEquals(outputs, []);
});
