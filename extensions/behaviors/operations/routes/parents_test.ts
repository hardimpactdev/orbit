import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { RouteParentStages, RouteWaiting } from "./parents.ts";
import { RouteRefusal } from "./lifecycle.ts";

function fixture(
  status = "queued",
  error_code: string | null = null,
  directChild = false,
) {
  const calls: {
    method: string;
    path: string;
    body: Record<string, unknown>;
  }[] = [];
  const results: Record<string, unknown> = {};
  const waits: string[][] = [];
  const http: Http = (method, path, raw) =>
    Promise.resolve().then(() => {
      const body = (raw ?? {}) as Record<string, unknown>;
      calls.push({ method, path, body });
      if (method === "GET") {
        return {
          status: 200,
          body: {
            data: [{
              id: 44,
              key: "dns-published.node-17",
              kind: "route.private_dns_publish",
              node_id: 17,
              status,
              error_code,
              result: { ready: true },
            }],
          },
        };
      }
      if (path.endsWith("/step") && body.status === "succeeded") {
        results[String(body.name)] = body.result;
      }
      return {
        status: path.endsWith("/children") ? 202 : 200,
        body: {
          data: path.endsWith("/children") || path.endsWith("/wait")
            ? {
              id: path.endsWith("/wait") ? 17 : 44,
              kind: "route.private_dns_publish",
              node_id: 17,
              input: {},
              status: path.endsWith("/wait") ? "waiting" : "queued",
              cancel_requested: false,
              ...(directChild && path.endsWith("/children")
                ? {}
                : { step_results: {} }),
              parent_operation_id: 17,
              child_key: "dns-published.node-17",
            }
            : { ready: true },
        },
      };
    });
  const stages = () =>
    new RouteParentStages(
      http,
      17,
      "worker",
      [{ stage: "dns-published", key: "dns-published.node-17" }],
      results,
      (keys) => {
        waits.push(keys);
        return Promise.resolve();
      },
    );
  return {
    calls,
    results,
    waits,
    stages,
    setStatus: (next: string) => {
      status = next;
    },
  };
}

Deno.test("a Route parent yields its lease for its exact child keys without completing", async () => {
  const f = fixture();
  await assertRejects(() => f.stages().run("dns-published"), RouteWaiting);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/children") && c.method === "POST")
      .map((c) => c.body),
    [{ worker: "worker", key: "dns-published.node-17" }],
  );
  assertEquals(f.waits, [["dns-published.node-17"]]);
  assertEquals(f.calls.find((c) => c.path.endsWith("/wait"))?.body, {
    worker: "worker",
    keys: ["dns-published.node-17"],
  });
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-commit.dns-published"),
    false,
  );
});
Deno.test("re-entry reuses keyed child admission and commits only its proven terminal result", async () => {
  const f = fixture();
  await assertRejects(() => f.stages().run("dns-published"), RouteWaiting);
  f.setStatus("succeeded");
  await f.stages().run("dns-published");
  const before = f.calls.length;
  await f.stages().run("dns-published");
  assertEquals(f.calls.length, before);
  assertEquals(
    f.calls.filter((c) => c.path.endsWith("/children") && c.method === "POST")
      .map((c) => c.body),
    [{ worker: "worker", key: "dns-published.node-17" }, {
      worker: "worker",
      key: "dns-published.node-17",
    }],
  );
  assertEquals(
    f.calls.filter((c) => c.body.key === "stage-commit.dns-published").length,
    1,
  );
});
Deno.test("a Route parent accepts a direct DNS child receipt without polled execution fields", async () => {
  const f = fixture("queued", null, true);
  await assertRejects(() => f.stages().run("dns-published"), RouteWaiting);
  assertEquals(f.waits, [["dns-published.node-17"]]);
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-commit.dns-published"),
    false,
  );
  f.setStatus("succeeded");
  await f.stages().run("dns-published");
  assertEquals(
    f.calls.filter((c) => c.body.key === "stage-commit.dns-published").length,
    1,
  );
  assertEquals(f.calls.some((c) => c.path.endsWith("/complete")), false);
});
Deno.test("a failed own child supplies a fixed refusal for the Route rollback policy", async () => {
  const f = fixture("failed", "private_dns.activation_failed");
  await assertRejects(
    () => f.stages().run("dns-published"),
    RouteRefusal,
    "private_dns.activation_failed",
  );
  assertEquals(
    f.calls.some((c) => c.body.key === "stage-commit.dns-published"),
    false,
  );
  assertEquals(f.waits, []);
});

Deno.test("a DNS grace phase waits in Swamp before Gateway commit without starting another child", async () => {
  const events: string[] = [];
  const http: Http = (_method, path, raw) =>
    Promise.resolve().then(() => {
      const body = raw as Record<string, unknown>;
      if (path.endsWith("/run")) {
        const input = body.input as Record<string, unknown>;
        events.push(String(input.action));
        return {
          status: 200,
          body: {
            data: input.action === "open"
              ? { opened: true, wait_seconds: 31 }
              : { ready: true },
          },
        };
      }
      return { status: 200, body: { data: {} } };
    });
  const stages = new RouteParentStages(
    http,
    17,
    "worker",
    [],
    {},
    async () => {},
    "route.cluster-router",
    (milliseconds) => {
      events.push(`pause:${milliseconds}`);
      return Promise.resolve();
    },
  );
  await stages.run("await-old-dns-expiry");
  assertEquals(events, ["open", "pause:31000", "commit"]);
});

Deno.test("a phase settles the first Node-bound sibling before admitting its next scope", async () => {
  const admitted: string[] = [];
  const terminal = new Set<string>();
  const events: Record<string, unknown> = {};
  const http: Http = (method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    if (method === "GET") {
      return Promise.resolve({
        status: 200,
        body: {
          data: admitted.map((key, index) => ({
            id: index + 40,
            key,
            kind: "certificate.remove",
            node_id: 2,
            status: terminal.has(key) ? "succeeded" : "queued",
            result: {},
            error_code: null,
          })),
        },
      });
    }
    if (path.endsWith("/children")) {
      const key = String(body.key);
      if (!admitted.includes(key)) {
        if (admitted.some((prior) => !terminal.has(prior))) {
          throw new Error(
            "a sibling still holds the same Node certificate target",
          );
        }
        admitted.push(key);
      }
      return Promise.resolve({
        status: 202,
        body: {
          data: {
            id: admitted.indexOf(key) + 40,
            kind: "certificate.remove",
            node_id: 2,
            input: {},
            status: terminal.has(key) ? "succeeded" : "queued",
            cancel_requested: false,
            step_results: {},
          },
        },
      });
    }
    if (path.endsWith("/step") && body.status === "succeeded") {
      events[String(body.name)] = body.result;
    }
    return Promise.resolve({
      status: 200,
      body: {
        data: path.endsWith("/wait")
          ? {
            id: 17,
            kind: "route.destroy",
            node_id: 2,
            input: {},
            status: "waiting",
            cancel_requested: false,
            step_results: {},
          }
          : { ready: true },
      },
    });
  };
  const stages = () =>
    new RouteParentStages(
      http,
      17,
      "worker",
      [
        { stage: "certificates", key: "certificates.first" },
        { stage: "certificates", key: "certificates.second" },
      ],
      events,
      async () => {},
    );
  await assertRejects(() => stages().run("certificates"), RouteWaiting);
  assertEquals(admitted, ["certificates.first"]);
  terminal.add("certificates.first");
  await assertRejects(() => stages().run("certificates"), RouteWaiting);
  assertEquals(admitted, ["certificates.first", "certificates.second"]);
  terminal.add("certificates.second");
  await stages().run("certificates");
  assertEquals(events["stage.certificates"], { ready: true });
});
