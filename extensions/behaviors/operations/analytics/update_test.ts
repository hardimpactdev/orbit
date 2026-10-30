import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { analyticsUpdate, updateRuntime } from "./update.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
export const environment = {
  SECRET_KEY_BASE: "runtime-secret-fixture",
  BASE_URL: "https://analytics.orbit",
};
export async function input() {
  const bytes = new TextEncoder().encode(
    JSON.stringify(
      Object.fromEntries(
        Object.entries(environment).sort(([a], [b]) => a < b ? -1 : 1),
      ),
    ),
  );
  const hash = Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
  const before = {
    process_id: 250,
    container: "orbit-process-250-plausible",
    desired: "running",
    spec: "a".repeat(64),
    image: "ghcr.io/plausible/community-edition:v3.2.1",
    command: ["/usr/local/bin/orbit-agent", "service", "plausible"],
    restart: "unless-stopped",
    working_directory: null,
    ports: ["10.44.0.3:8000:8000/tcp"],
    mounts: [],
  };
  return {
    process_id: 250,
    assignment_id: 4,
    runtime_environment: { "$secret": "analytics.runtime_environment", id: 17 },
    version: "3.3.0",
    previous_version: "3.2.1",
    agent_sha256: "a".repeat(64),
    environment_sha256: hash,
    health_url: "http://10.44.0.3:8000/api/health",
    before,
    after: {
      ...before,
      spec: "b".repeat(64),
      image: "ghcr.io/plausible/community-edition:v3.3.0",
    },
  };
}
Deno.test("update uses the owned container, preserves environment, and verifies health before success", async () => {
  const calls: NodeCall[] = [];
  const result = await updateRuntime(
    await input(),
    environment,
    (call) => {
      calls.push(call);
      return Promise.resolve({});
    },
  );
  assertEquals(calls.map((c) => c.name), [
    "process.converge-container",
  ]);
  assertEquals(calls[0].input.environment, environment);
  assertEquals(calls[0].input.process_id, 250);
  assertEquals(calls[0].input.health, {
    url: "http://10.44.0.3:8000/api/health",
    attempts: 90,
    interval_seconds: 2,
  });
  assertEquals(result, {
    ready: true,
    version: "3.3.0",
    previous_version: "3.2.1",
  });
});
Deno.test("same version checks health without replacing the container", async () => {
  const i = await input();
  i.after = i.before;
  i.version = i.previous_version;
  const calls: NodeCall[] = [];
  await updateRuntime(i, environment, (c) => {
    calls.push(c);
    return Promise.resolve({});
  });
  assertEquals(calls.map((c) => c.name), ["http.await-healthy"]);
});
Deno.test("agent health refusal projects restored state without a second mutation", async () => {
  const calls: NodeCall[] = [];
  const result = await updateRuntime(await input(), environment, (c) => {
    calls.push(c);
    return Promise.resolve({
      error_code: "process.health_failed",
      restored: true,
    });
  });
  assertEquals(calls.length, 1);
  assertEquals(result, {
    error_code: "analytics.update_failed",
    rolled_back: true,
  });
});
Deno.test("failed agent recovery is explicit and never issues another mutation", async () => {
  let count = 0;
  const result = await updateRuntime(await input(), environment, () => {
    count++;
    return Promise.resolve({ error_code: "process.recovery_required" });
  });
  assertEquals(count, 1);
  assertEquals(result, {
    error_code: "analytics.rollback_failed",
    rolled_back: false,
  });
});
Deno.test("stale private environment refuses before Node effects", async () => {
  let count = 0;
  const result = await updateRuntime(await input(), {
    ...environment,
    SECRET_KEY_BASE: "changed",
  }, () => {
    count++;
    return Promise.resolve({});
  });
  assertEquals(count, 0);
  assertEquals(result, {
    error_code: "analytics.runtime_environment_changed",
    rolled_back: true,
  });
});
Deno.test("transport uncertainty stays in native recovery instead of issuing a second mutation", async () => {
  let count = 0;
  await assertRejects(
    () =>
      updateRuntime(awaitedInput, environment, () => {
        count++;
        return Promise.reject(new Error("uncertain"));
      }),
    Error,
    "uncertain",
  );
  assertEquals(count, 1);
});
const awaitedInput = await input();
Deno.test("running projection is re-read before effects and terminal replay makes no Node call", async () => {
  const i = await input();
  let status = "queued";
  let calls = 0;
  let reads = 0;
  const behavior = analyticsUpdate(() => (method, path, body) => {
    if (path.includes("/private-material?")) {
      return Promise.resolve({
        status: 200,
        body: { data: { runtime_environment: environment } },
      });
    }
    if (method === "POST") status = (body as { status: string }).status;
    else reads++;
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 12,
          kind: "analytics.update",
          node_id: 17,
          status,
          result: status === "succeeded"
            ? {
              ready: true,
              version: i.version,
              previous_version: i.previous_version,
            }
            : null,
          cancel_requested: false,
          input: status === "queued" ? {} : i,
        },
      },
    });
  }, () => {
    calls++;
    return Promise.resolve({});
  });
  const args = {
    operation_id: 12,
    kind: "analytics.update" as const,
    node_id: 17,
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
  };
  await behavior.methods.apply.execute(args, ctx);
  await behavior.methods.apply.execute(args, ctx);
  assertEquals(calls, 1);
  assertEquals(reads >= 3, true);
});

Deno.test("stale runtime refusal leaves the newer Process healthy and makes no retry", async () => {
  let count = 0;
  const result = await updateRuntime(
    await input(),
    environment,
    (call) => {
      count++;
      assertEquals(call.input.expected_spec, "a".repeat(64));
      assertEquals(call.input.expected_running, true);
      return Promise.resolve({ error_code: "process.spec_changed" });
    },
  );
  assertEquals(count, 1);
  assertEquals(result, {
    error_code: "analytics.update_failed",
    rolled_back: true,
  });
});
