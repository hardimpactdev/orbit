import {
  assertEquals,
  assertFalse,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import { executeVault, localCollection, vaultDirect } from "./mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
const identity = {
  operation_id: 17,
  node_id: 9,
  kind: "vault.sync" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
for (
  const [kind, backend] of [["vault.sync", "synced"], [
    "vault.setup",
    "configured",
  ]] as const
) {
  Deno.test(`${kind} fetches private input and projects only safe metadata`, async () => {
    const projections: unknown[] = [];
    const calls: string[] = [];
    const http: Http = (method, path, body) => {
      calls.push(path);
      if (path.includes("/private-material")) {
        return Promise.resolve({
          status: 200,
          body: {
            data: {
              configuration: {
                client_secret: "secret-sentinel",
                ca_file: "/foreign/path",
              },
            },
          },
        });
      }
      if (method === "POST") projections.push(body);
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            node_id: 9,
            kind,
            input: {},
            status: "queued",
            cancel_requested: false,
            result: null,
          },
        },
      });
    };
    const ctx = {
      globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/local/ca" },
      readResource: () => Promise.resolve(null),
      writeResource: () =>
        Promise.reject(new Error("No Vault input may be persisted")),
    };
    const behavior = vaultDirect(() => http, (action, config, ca) => {
      assertEquals(action, kind.slice(6));
      assertEquals(config.client_secret, "secret-sentinel");
      assertEquals(ca, "/local/ca");
      return Promise.resolve({ outcome: "succeeded", data: { backend } });
    });
    assertEquals(
      await behavior.methods.apply.execute({ ...identity, kind }, ctx),
      { status: "succeeded" },
    );
    assertFalse(JSON.stringify(projections).includes("secret-sentinel"));
    assertFalse(calls.some((p) => p.includes("operation-executor")));
  });
}
Deno.test("Vault secret-bearing exceptions and malformed output become bounded refusals", async () => {
  const http: Http = () =>
    Promise.resolve({
      status: 200,
      body: { data: { configuration: { master_password: "secret-sentinel" } } },
    });
  for (
    const [invoke, code] of [
      [() => Promise.reject(new Error("secret-sentinel")), "vault.unreachable"],
      [() =>
        Promise.resolve({
          outcome: "succeeded",
          data: { backend: "synced", master_password: "secret-sentinel" },
        }), "vault.result_invalid"],
    ] as const
  ) {
    assertEquals(await executeVault(identity, http, "/local/ca", invoke), {
      error_code: code,
    });
  }
});
Deno.test("Vault sync cannot accept a setup backend and a failed read cannot execute", async () => {
  const http: Http = () =>
    Promise.resolve({ status: 200, body: { data: { configuration: {} } } });
  assertEquals(
    await executeVault(
      identity,
      http,
      "/local/ca",
      () =>
        Promise.resolve({
          outcome: "succeeded",
          data: { backend: "configured" },
        }),
    ),
    { error_code: "vault.result_invalid" },
  );
  assertEquals(
    await executeVault(
      identity,
      () => Promise.resolve({ status: 403, body: {} }),
      "/local/ca",
      () => Promise.reject(new Error("must not run")),
    ),
    { error_code: "vault.unreachable" },
  );
});

Deno.test({
  name:
    "Vault overall deadline kills the actual child, independently of request deadlines",
  permissions: { run: ["/usr/bin/python3"] },
  fn: async () => {
    let budget = 0;
    const error = await assertRejects(() =>
      localCollection(
        "setup",
        { master_password: "private-sentinel" },
        "/missing/ca",
        undefined,
        (ms) => {
          budget = ms;
          return AbortSignal.timeout(1);
        },
      )
    );
    assertEquals(budget, 900000);
    assertFalse(String(error).includes("private-sentinel"));
  },
});

for (
  const [secretField, data] of [
    ["master_password", {
      backend: "synced",
      email: "echo-private-sentinel",
    }],
    ["client_secret", {
      backend: "synced",
      organization_name: "prefix-echo-private-sentinel-suffix",
    }],
    ["hidden", { backend: "synced", sources: ["echo-private-sentinel"] }],
    ["master_password", { error_code: "vault.echo_private_sentinel" }],
  ] as const
) {
  Deno.test(`Vault refuses ${secretField} echoed in public ${Object.keys(data).at(-1)}`, async () => {
    const sentinel = "error_code" in data
      ? "echo_private_sentinel"
      : "echo-private-sentinel";
    const configuration = secretField === "hidden"
      ? {
        wanted: [{ cipher: { fields: [{ hidden: true, value: sentinel }] } }],
      }
      : { [secretField]: sentinel };
    const projections: unknown[] = [];
    const http: Http = (method, path, body) => {
      if (path.includes("/private-material")) {
        return Promise.resolve({
          status: 200,
          body: { data: { configuration } },
        });
      }
      if (method === "POST") projections.push(body);
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 17,
            node_id: 9,
            kind: "vault.sync",
            input: {},
            status: "queued",
            cancel_requested: false,
            result: null,
          },
        },
      });
    };
    const behavior = vaultDirect(() => http, () =>
      Promise.resolve({
        outcome: "error_code" in data ? "failed" : "succeeded",
        data,
      }));

    const error = await assertRejects(() =>
      behavior.methods.apply.execute(identity, {
        globalArgs: {
          gatewayUrl: "https://gateway.orbit",
          caFile: "/local/ca",
        },
        readResource: () => Promise.resolve(null),
        writeResource: () => Promise.reject(new Error("No Vault resource")),
      })
    );

    assertEquals(String(error), "Error: vault.result_invalid");
    assertFalse(JSON.stringify(projections).includes(sentinel));
  });
}
