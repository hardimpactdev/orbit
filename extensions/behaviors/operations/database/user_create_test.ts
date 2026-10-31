import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { userCreate } from "./user_create.ts";

const args = {
  operation_id: 17,
  node_id: 209,
  kind: "database_user.create" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
class Fixture {
  operation = {
    id: 17,
    node_id: 209,
    kind: "database_user.create",
    status: "queued",
    result: null as unknown,
    cancel_requested: false,
    input: {
      driver: "mysql",
      container: "orbit-process-42-proof",
      agent_sha256: "a".repeat(64),
      username: "reader",
      replication: false,
      known_user: false,
    },
  };
  paths: string[] = [];
  effects = 0;
  output: Record<string, unknown> = {};
  http: Http = (method, path, body) => {
    this.paths.push(path);
    if (path.includes("private-material")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            password: "private-root-sentinel",
            sql: "private-sql-sentinel",
          },
        },
      });
    }
    if (method === "GET") {
      return Promise.resolve({ status: 200, body: { data: this.operation } });
    }
    const projection = body as Record<string, unknown>;
    this.operation.status = String(projection.status);
    this.operation.result = projection.result;
    return Promise.resolve({ status: 200, body: { data: this.operation } });
  };
  ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      this.output = value;
      return Promise.resolve({ name });
    },
  };
  behavior = userCreate(() => this.http, () => {
    this.effects++;
    return Promise.resolve({ output: "private-sql-sentinel" });
  });
}

Deno.test("SQL effect emits a safe native step output and leaves terminal projection to its next step", async () => {
  const f = new Fixture();
  await f.behavior.methods.create.execute(args, f.ctx);
  assertEquals(f.effects, 1);
  assertEquals(f.operation.status, "running");
  assertEquals(JSON.stringify(f.output).includes("private-"), false);
  const outcome = f.behavior.methods.project.arguments.parse({
    ...args,
    outcome: f.output.outcome,
  }).outcome;
  assertEquals(
    await f.behavior.methods.project.execute({ ...args, outcome }, f.ctx),
    { status: "succeeded" },
  );
  assertEquals(f.operation.result, { backend: "active" });
  assertEquals(
    f.paths.filter((path) => path.includes("private-material")).length,
    1,
  );
});

Deno.test("foreign SQL intent refuses before private material or any Node effect", async () => {
  for (const key of ["id", "node_id", "kind"] as const) {
    const f = new Fixture();
    if (key === "kind") f.operation.kind = "database.create";
    else f.operation[key]++;
    await assertRejects(
      () => f.behavior.methods.create.execute(args, f.ctx),
      Error,
      "database.user_create_outcome_unknown",
    );
    assertEquals(f.paths.length, 1);
    assertEquals(f.effects, 0);
  }
});

Deno.test("cancelled or already terminal SQL intent reads no material and runs no SQL", async () => {
  for (const terminal of [false, true]) {
    const f = new Fixture();
    if (terminal) {
      f.operation.status = "succeeded";
      f.operation.result = { backend: "active" };
    } else f.operation.cancel_requested = true;
    await f.behavior.methods.create.execute(args, f.ctx);
    assertEquals(f.effects, 0);
    assertEquals(
      f.paths.some((path) => path.includes("private-material")),
      false,
    );
    const outcome = f.behavior.methods.project.arguments.parse({
      ...args,
      outcome: f.output.outcome,
    }).outcome;
    assertEquals(outcome.status, terminal ? "succeeded" : "cancelled");
  }
});
