import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { publish, PublishInput } from "./publish.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";

function fixture(withPr = true) {
  const input = PublishInput.parse({
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    check: 4,
    checkout_path: "/srv/workspace",
    commit: "a".repeat(40),
    tree: "b".repeat(40),
    repository: "acme/shop",
    base: "main",
    branch: "task-1",
    pull_request: withPr
      ? { title: "Export orders", body: "Adds the export." }
      : null,
  });
  const mutations: Array<{ method: string; url: string; body: unknown }> = [];
  const calls: Record<string, unknown>[] = [];
  const controller = new AbortController();
  let reads = 0;
  let pulls: unknown[] = [];
  const gateway = {
    withPrivateMaterial: async (
      use: (material: Record<string, unknown>) => Promise<void>,
    ) => {
      reads++;
      await use({
        credentials: {
          repository: input.repository,
          write_token: "fixture-private-token",
        },
      });
    },
  };
  const runtime = {
    invoke: (value: Record<string, unknown>): Promise<unknown> => {
      calls.push(value);
      return Promise.resolve(
        value.mode === "snapshot"
          ? { head: input.commit, tree: input.tree }
          : { pushed: true },
      );
    },
    request: ((url: string | URL | Request, options?: RequestInit) => {
      const method = options?.method ?? "GET";
      mutations.push({
        method,
        url: String(url),
        body: options?.body ? JSON.parse(String(options.body)) : null,
      });
      if (method === "DELETE") {
        return Promise.resolve(new Response(null, { status: 204 }));
      }
      if (method === "GET") return Promise.resolve(Response.json(pulls));
      return Promise.resolve(Response.json({ number: 12 }));
    }) as typeof fetch,
  };
  return {
    input,
    gateway,
    runtime,
    calls,
    mutations,
    controller,
    reads: () => reads,
    setPulls: (value: unknown[]) => {
      pulls = value;
    },
  };
}
const pull = (number = 12) => ({
  number,
  state: "open",
  head: {
    ref: "task-1",
    sha: "a".repeat(40),
    repo: { full_name: "acme/shop" },
  },
  base: { ref: "main" },
});
Deno.test("publication pushes the checked SHA and opens one PR with unchanged caller prose", async () => {
  const f = fixture();
  const result = await publish(
    f.input,
    15,
    f.gateway,
    f.controller.signal,
    f.runtime,
  );
  assertEquals(result, {
    branch: "task-1",
    commit: f.input.commit,
    pull_request_number: 12,
    pull_request_url: "https://github.com/acme/shop/pull/12",
  });
  const push = f.calls.find((call) => call.mode === "push")!;
  assertEquals(push.commit, f.input.commit);
  assertEquals(push.branch, "task-1");
  assertEquals(push.execution_key, "operation-15");
  assertEquals(
    f.mutations.filter((call) => call.method === "POST").map((call) =>
      call.body
    ),
    [{ ...f.input.pull_request, head: "task-1", base: "main" }],
  );
  assertEquals(
    f.mutations.filter((call) => call.method === "DELETE").length,
    1,
  );
  assertEquals(JSON.stringify(result).includes("fixture-private-token"), false);
});
Deno.test("a push-only request uses no pull-request endpoint", async () => {
  const f = fixture(false);
  assertEquals(
    (await publish(f.input, 16, f.gateway, f.controller.signal, f.runtime))
      .pull_request_url,
    null,
  );
  assertEquals(f.mutations.map((call) => call.method), ["DELETE"]);
});
Deno.test("an existing task PR is updated and no reviewers are requested", async () => {
  const f = fixture();
  f.setPulls([pull()]);
  await publish(f.input, 17, f.gateway, f.controller.signal, f.runtime);
  assertEquals(f.mutations.map((call) => call.method), [
    "GET",
    "PATCH",
    "DELETE",
  ]);
  assertEquals(
    f.mutations[1].url,
    "https://api.github.com/repos/acme/shop/pulls/12",
  );
  assertEquals(f.mutations[1].body, f.input.pull_request);
});
Deno.test("a stale checked tree refuses before minting credentials or pushing", async () => {
  const f = fixture();
  f.runtime.invoke = () =>
    Promise.resolve({ head: f.input.commit, tree: "f".repeat(40) });
  assertEquals(
    await publish(f.input, 18, f.gateway, f.controller.signal, f.runtime),
    { error_code: "tasks.head_moved" },
  );
  assertEquals(f.reads(), 0);
  assertEquals(f.mutations, []);
});
Deno.test("push refusals do not open a PR or leak token-bearing diagnostics", async () => {
  for (
    const error_code of [
      "tasks.not_fast_forward",
      "tasks.workflows_permission",
      "tasks.push_failed",
    ]
  ) {
    const f = fixture();
    const original = f.runtime.invoke;
    f.runtime.invoke = (value) =>
      value.mode === "push" ? Promise.resolve({ error_code }) : original(value);
    assertEquals(
      await publish(f.input, 19, f.gateway, f.controller.signal, f.runtime),
      { error_code },
    );
    assertEquals(f.mutations.map((call) => call.method), ["DELETE"]);
  }
});
Deno.test("cancellation after a started push finishes it and opens no PR", async () => {
  const f = fixture();
  const original = f.runtime.invoke;
  f.runtime.invoke = async (value) => {
    const result = await original(value);
    if (value.mode === "push") f.controller.abort();
    return result;
  };
  await assertRejects(
    () => publish(f.input, 20, f.gateway, f.controller.signal, f.runtime),
    DirectOperationCancelled,
  );
  assertEquals(f.mutations.map((call) => call.method), ["DELETE"]);
});
Deno.test("a lost PR-create reply is reconciled by branch lookup without a second create", async () => {
  const f = fixture();
  let creates = 0;
  const request = f.runtime.request;
  f.runtime.request = ((url: string | URL | Request, options?: RequestInit) => {
    if (options?.method === "POST") {
      creates++;
      f.setPulls([pull()]);
      return Promise.reject(new Error("lost response"));
    }
    return request(url, options);
  }) as typeof fetch;
  assertEquals(
    await publish(f.input, 21, f.gateway, f.controller.signal, f.runtime),
    { error_code: "tasks.publish_failed" },
  );
  assertEquals(
    (await publish(f.input, 21, f.gateway, f.controller.signal, f.runtime))
      .pull_request_number,
    12,
  );
  assertEquals(creates, 1);
});
