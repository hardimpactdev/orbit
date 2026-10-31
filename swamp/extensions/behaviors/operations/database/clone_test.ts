import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { clone, type Context } from "./clone.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import type { DatabaseSqlCall } from "./local_sql.ts";
import type { SqliteInvoke } from "./sqlite_local.ts";
import { good, mysql } from "./clone_fixture.ts";

const args = {
  operation_id: 17,
  kind: "database.clone" as const,
  node_id: 209,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
class Fixture {
  events: string[] = [];
  paths: string[] = [];
  outputs: Record<string, unknown>[] = [];
  stage: unknown;
  resources: Record<string, Record<string, unknown>> = {};
  operation = {
    id: 17,
    kind: "database.clone",
    node_id: 209,
    status: "queued",
    result: null as unknown,
    cancel_requested: false,
    input: {
      variant: "sqlite_remote",
      node_id: 209,
      source_node_id: 9,
      source: {
        variant: "clone",
        node_id: 9,
        transport_user: "orbit",
        checkout: "/home/orbit/source",
        source: "/home/orbit/source/database/db.sqlite",
        directory: "/tmp/orbit-sqlite-clone-" + "c".repeat(32),
      },
      target: {
        node_id: 209,
        transport_user: "orbit",
        checkout: "/home/orbit/target",
        target: "/home/orbit/target/database/db.sqlite",
        incoming: "/home/orbit/target/database/.orbit-sqlite-" + "d".repeat(32),
      },
    } as unknown,
  };
  loseProjection = false;
  failInstallOnce = false;
  http: Http = (method, path, body) => {
    this.paths.push(path);
    if (path.includes("private-material")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            password: "private-root-clone",
            payload: {
              password: "a".repeat(40),
              salt: "YWFhYWFhYWFhYWFhYWFhYQ==",
            },
          },
        },
      });
    }
    if (method === "GET") {
      return Promise.resolve({ status: 200, body: { data: this.operation } });
    }
    const data = body as Record<string, unknown>;
    if (this.loseProjection && data.status === "succeeded") {
      this.loseProjection = false;
      return Promise.resolve({ status: 503, body: {} });
    }
    this.operation.status = String(data.status);
    this.operation.result = data.result;
    return Promise.resolve({ status: 200, body: { data: this.operation } });
  };
  ctx: Context = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
    modelId: "b075eb23-7ac4-4487-80d1-57a038a92676",
    modelType: { normalized: "@nckrtl/database-clone" },
    createFileWriter: () => {
      throw new Error("Fixture uses fake transport");
    },
    dataRepository: {
      stream: async function* () {
        yield new Uint8Array();
      },
    },
    readResource: (name) => Promise.resolve(this.resources[name] ?? null),
    writeResource: (_spec, name, value) => {
      this.resources[name] = structuredClone(value);
      this.outputs.push(value);
      this.stage = value;
      return Promise.resolve({ name });
    },
  };
  sqlInvoke = (call: DatabaseSqlCall) => {
    this.events.push(String(call.input.operation));
    assertEquals(call.name, "database.server-admin");
    assertEquals("pin" in call, false);
    return good(call);
  };
  sqliteInvoke: SqliteInvoke = (call) => {
    this.events.push(String(call.input.operation));
    assertEquals(call.action, "copy");
    assertEquals("pin" in call, false);
    assertEquals("agent_sha256" in call.input, false);
    if (call.input.operation === "install" && this.failInstallOnce) {
      this.failInstallOnce = false;
      return Promise.reject(
        new Error("Install renamed incoming before reply loss"),
      );
    }
    return Promise.resolve(
      call.input.operation === "local"
        ? { bytes: 4096, sha256: "e".repeat(64), mode: 0o640, copy: "snapshot" }
        : {},
    );
  };
  behavior = clone(() => this.http, this.sqlInvoke, {
    exportSnapshot: (source, _ctx, name) => {
      this.events.push("export");
      assertEquals((source as { node_id: number }).node_id, 9);
      return Promise.resolve({
        model_id: "b075eb23-7ac4-4487-80d1-57a038a92676",
        name,
        version: 1,
        bytes: 4096,
        sha256: "e".repeat(64),
        mode: 0o640,
      });
    },
    receiveSnapshot: (target, ref) => {
      this.events.push("transfer");
      assertEquals(target.path.endsWith("d".repeat(32)), true);
      assertEquals((ref as { bytes: number }).bytes, 4096);
      return Promise.resolve();
    },
    cleanupSnapshot: (_source, _invoke, signal) => {
      assertEquals(signal?.aborted, undefined);
      this.events.push("cleanup");
      return Promise.resolve();
    },
  }, this.sqliteInvoke);
  async admit() {
    await this.behavior.methods.admit.execute(args, this.ctx);
  }
  async step(
    name:
      | "export"
      | "prepare"
      | "apply"
      | "cleanupTarget"
      | "cleanup"
      | "project",
    stage = this.stage,
  ) {
    const method = this.behavior.methods[name];
    if (stage !== this.stage && name === "apply") {
      this.resources[`prepared-${args.run_id}`] = structuredClone(
        stage as Record<string, unknown>,
      );
    }
    const intent = this.operation.input as { source_node_id: number };
    return await method.execute({
      ...args,
      selected_node_id: ["export", "cleanup"].includes(name)
        ? intent.source_node_id
        : args.node_id,
    }, this.ctx);
  }
}
Deno.test("cross-Node clone uses one native export/transfer/install pipeline without child or Gateway relay calls", async () => {
  const f = new Fixture();
  await f.admit();
  for (
    const name of [
      "export",
      "prepare",
      "apply",
      "cleanupTarget",
      "cleanup",
      "project",
    ] as const
  ) await f.step(name);
  assertEquals(f.events, [
    "export",
    "prepare",
    "transfer",
    "install",
    "remove",
    "cleanup",
  ]);
  assertEquals(f.operation.status, "succeeded");
  assertEquals(f.operation.result, { backend: "active" });
  assertEquals(
    f.paths.some((p) => /child|executor|primitive|private-material/.test(p)),
    false,
  );
});
Deno.test("same-Node SQLite clone uses the local SQLite action without export", async () => {
  const f = new Fixture();
  const i = f.operation.input as Record<string, unknown>;
  i.variant = "sqlite_local";
  i.source_node_id = 209;
  (i.source as Record<string, unknown>).node_id = 209;
  await f.admit();
  for (
    const name of [
      "export",
      "prepare",
      "apply",
      "cleanupTarget",
      "cleanup",
      "project",
    ] as const
  ) await f.step(name);
  assertEquals(f.events, ["local"]);
  assertEquals(f.operation.status, "succeeded");
});
Deno.test("projection loss reuses saved applied output and never replays reset/copy", async () => {
  const f = new Fixture();
  f.operation.input = {
    variant: "mysql",
    node_id: 209,
    source_node_id: 209,
    mysql,
  };
  await f.admit();
  for (
    const name of [
      "export",
      "prepare",
      "apply",
      "cleanupTarget",
      "cleanup",
    ] as const
  ) {
    await f.step(name);
  }
  const effects = f.events.slice();
  f.loseProjection = true;
  await assertRejects(() => f.step("project"));
  await f.step("project");
  assertEquals(f.events, effects);
  assertEquals(f.operation.status, "succeeded");
  assertEquals(JSON.stringify(f.outputs).includes("private-"), false);
});
Deno.test("uncertain SQLite install recreates incoming from the same immutable snapshot before retry", async () => {
  const f = new Fixture();
  await f.admit();
  for (const name of ["export", "prepare"] as const) await f.step(name);
  const prepared = f.stage;
  f.failInstallOnce = true;
  await assertRejects(
    () => f.step("apply", prepared),
    Error,
    "database.clone_applied_outcome_unknown",
  );
  await f.step("apply", prepared);
  assertEquals(f.events, [
    "export",
    "prepare",
    "transfer",
    "install",
    "transfer",
    "install",
  ]);
  await f.step("cleanupTarget");
  await f.step("cleanup");
  await f.step("project");
  assertEquals(f.operation.status, "succeeded");
});
Deno.test("cancellation before the first effect does no export or copy", async () => {
  const f = new Fixture();
  f.operation.cancel_requested = true;
  await f.admit();
  for (
    const name of [
      "export",
      "prepare",
      "apply",
      "cleanupTarget",
      "cleanup",
      "project",
    ] as const
  ) await f.step(name);
  assertEquals(f.events, []);
  assertEquals(f.operation.status, "cancelled");
});
Deno.test("foreign Operation identity and path escapes stop before any Node effect", async () => {
  for (
    const mutate of [(f: Fixture) => f.operation.node_id++, (f: Fixture) => {
      const i = f.operation.input as { target: { target: string } };
      i.target.target = "/home/orbit/target/../outside.sqlite";
    }]
  ) {
    const f = new Fixture();
    mutate(f);
    await assertRejects(
      () => f.admit(),
      Error,
      "database.clone_admission_outcome_unknown",
    );
    assertEquals(f.events, []);
    assertEquals(f.paths.length, 1);
  }
});

Deno.test("cancellation after export stops target effects and still cleans the snapshot", async () => {
  const f = new Fixture();
  await f.admit();
  await f.step("export");
  f.operation.cancel_requested = true;
  for (
    const name of [
      "prepare",
      "apply",
      "cleanupTarget",
      "cleanup",
      "project",
    ] as const
  ) await f.step(name);
  assertEquals(f.events, ["export", "cleanup"]);
  assertEquals(f.operation.status, "cancelled");
});

Deno.test("clone refuses changed native intent, wrong selected Node and foreign run before effects", async () => {
  for (const mutation of ["intent", "node", "run"] as const) {
    const f = new Fixture();
    await f.admit();
    if (mutation === "intent") {
      const stage = f.resources[`admitted-${args.run_id}`] as {
        intent: { source: { source: string } };
      };
      stage.intent.source.source = "/home/orbit/source/other.sqlite";
    }
    await assertRejects(() =>
      f.behavior.methods.export.execute({
        ...args,
        run_id: mutation === "run"
          ? "df53d68d-1f5d-415e-86f3-b282c1be9ca8"
          : args.run_id,
        selected_node_id: mutation === "node" ? 99 : 9,
      }, f.ctx)
    );
    assertEquals(f.events, []);
  }
});
Deno.test("clone cleanup completes after cancellation aborts the effect context", async () => {
  const f = new Fixture();
  await f.admit();
  await f.step("export");
  const paths = f.paths.length;
  const controller = new AbortController();
  f.ctx.signal = controller.signal;
  controller.abort();
  for (
    const name of [
      "prepare",
      "apply",
      "cleanupTarget",
      "cleanup",
      "project",
    ] as const
  ) await f.step(name);
  assertEquals(f.operation.status, "cancelled");
  assertEquals(f.events, ["export", "cleanup"]);
  assertEquals(f.paths.slice(paths).length, 1);
});
Deno.test("terminal clone admission refuses without writing checkpoints or running effects", async () => {
  const f = new Fixture();
  f.operation.status = "succeeded";
  await assertRejects(() => f.admit());
  assertEquals(f.events, []);
  assertEquals(f.outputs, []);
});
