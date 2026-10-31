import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { childExecutor, WaitingForChildren } from "./children.ts";
import type { Child, Receipt } from "./runtime.ts";

const child: Child = {
  key: "route-converge",
  kind: "route.converge",
  node_id: 9,
  input: { route_id: 30, instance_id: 501 },
};
class Fixture {
  calls: { method: string; path: string; body: unknown }[] = [];
  admissions = 0;
  admitted = false;
  loseReply = false;
  status = "queued";
  node = 9;
  error: string | null = null;
  result: Receipt = { converged: true };
  http: Http = (method, path, body) =>
    Promise.resolve().then(() => {
      this.calls.push({ method, path, body });
      if (path.endsWith("/wait")) {
        return { status: 200, body: { data: { status: "waiting" } } };
      }
      if (method === "POST") {
        if (!this.admitted) {
          this.admitted = true;
          this.admissions++;
        }
        if (this.loseReply) {
          this.loseReply = false;
          throw new Error("Lost child reply");
        }
        return { status: 202, body: { data: { id: 200 } } };
      }
      return {
        status: 200,
        body: {
          data: [{
            id: 200,
            key: child.key,
            kind: child.kind,
            node_id: this.node,
            status: this.status,
            error_code: this.error,
            result: this.result,
          }],
        },
      };
    });
  execute() {
    return childExecutor(this.http, 100, "parent-worker")(child);
  }
}

Deno.test("child admission sends only a stable key and yields the parent worker", async () => {
  const f = new Fixture();
  await assertRejects(() => f.execute(), WaitingForChildren);
  assertEquals(f.calls[0], {
    method: "POST",
    path: "/api/v1/operation-executor/100/children",
    body: { worker: "parent-worker", key: child.key },
  });
  assertEquals(f.calls.filter((call) => call.method === "GET").length, 1);
  assertEquals(f.calls.at(-1), {
    method: "POST",
    path: "/api/v1/operation-executor/100/wait",
    body: { worker: "parent-worker", keys: [child.key] },
  });
  f.status = "succeeded";
  assertEquals(await f.execute(), { converged: true });
  assertEquals(f.admissions, 1);
});

Deno.test("lost child admission replies reuse the same key and one recorded child", async () => {
  const f = new Fixture();
  f.loseReply = true;
  await assertRejects(() => f.execute(), Error, "Lost child reply");
  f.status = "succeeded";
  assertEquals(await f.execute(), { converged: true });
  assertEquals(f.admissions, 1);
  const posts = f.calls.filter((call) => call.method === "POST");
  assertEquals(posts[0].body, posts[1].body);
});

Deno.test("child refusal keeps its typed safe result and original error code", async () => {
  const f = new Fixture();
  f.status = "failed";
  f.error = "route.domain_conflict";
  f.result = { route_id: 30 };
  assertEquals(await f.execute(), {
    route_id: 30,
    error_code: "route.domain_conflict",
  });
});

Deno.test("a different recorded Node cannot become child proof", async () => {
  const f = new Fixture();
  f.node = 10;
  f.status = "succeeded";
  await assertRejects(
    () => f.execute(),
    Error,
    "does not match admitted placement",
  );
});

Deno.test("a child waiting for its own descendants also releases the parent worker", async () => {
  const f = new Fixture();
  f.status = "waiting";
  await assertRejects(() => f.execute(), WaitingForChildren);
  assertEquals(f.calls.at(-1)!.path, "/api/v1/operation-executor/100/wait");
});
