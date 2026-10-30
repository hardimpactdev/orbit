import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { inspectSource, lifecycleDirect } from "./direct.ts";

const args = {
  operation_id: 17,
  node_id: 9,
  kind: "instance.rename" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
const input = {
  agent_sha256: "a".repeat(64),
  repository_identity: "github.com/example/app",
  branch: "feature",
  source: {
    repository: "https://github.com/example/app.git",
    checkout: "/srv/apps/app/proof",
    root: "/srv/apps/app",
    user: "orbit",
    group: "orbit",
    prepare_id: null,
    seed_repository: null,
    verify_origin: false as const,
    require_idle: true as const,
  },
};
class Fixture {
  operation = {
    id: 17,
    node_id: 9,
    kind: "instance.rename",
    status: "queued",
    input,
    result: null as Record<string, unknown> | null,
    cancel_requested: false,
  };
  output: unknown = {
    ok: true,
    data: {
      origin: "git@github.com:example/app.git",
      branch: "feature",
      commit: "b".repeat(40),
    },
  };
  calls: { path: string; body?: Record<string, unknown> }[] = [];
  inspections = 0;
  loseCompletion = false;
  unbound = false;
  controller = new AbortController();
  inspect = async (value: unknown) => {
    assertEquals(value, input);
    this.inspections++;
    await Promise.resolve();
    return this.output;
  };
  http: Http = async (method, path, body) => {
    await Promise.resolve();
    const value = body as Record<string, unknown>;
    this.calls.push({ path, body: value });
    if (this.unbound) {
      return {
        status: 409,
        body: { error: { code: "operation.run_unbound" } },
      };
    }
    if (method === "GET") {
      return { status: 200, body: { data: this.operation } };
    }
    assertEquals(path, "/api/v1/direct-operations/17/result");
    this.operation.status = String(value.status);
    this.operation.result = value.result as Record<string, unknown> ?? null;
    if (this.loseCompletion && value.status !== "running") {
      this.loseCompletion = false;
      throw new Error("lost terminal reply");
    }
    return { status: 200, body: { data: this.operation } };
  };
  execute() {
    return lifecycleDirect(() => this.http, this.inspect).methods.rename
      .execute(args, { ...ctx, signal: this.controller.signal });
  }
}

Deno.test("direct rename reads admitted input and projects only verified branch facts", async () => {
  const f = new Fixture();
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.inspections, 1);
  assertEquals(f.calls.map((c) => c.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
  assertEquals(f.calls.at(-1)!.body!.result, {
    source_verified: true,
    branch: "feature",
  });
});

for (
  const [name, data, code] of [
    ["foreign repository", {
      origin: "https://github.com/foreign/app.git",
      branch: "feature",
      commit: null,
    }, "instance.source_identity_invalid"],
    ["URL path normalization", {
      origin: "https://github.com/foreign/../example/app.git",
      branch: "feature",
      commit: null,
    }, "instance.source_identity_invalid"],
    ["wrong branch", {
      origin: "https://github.com/example/app.git",
      branch: "other",
      commit: null,
    }, "instance.branch_not_checked_out"],
    ["detached HEAD", {
      origin: "https://github.com/example/app.git",
      branch: null,
      commit: null,
    }, "instance.branch_not_checked_out"],
  ] as const
) {
  Deno.test(`direct rename refuses ${name} without publishing raw source data`, async () => {
    const f = new Fixture();
    f.output = { ok: true, data };
    await assertRejects(() => f.execute(), Error, code);
    assertEquals(f.calls.at(-1)!.body!.result, { error_code: code });
    assertEquals(f.operation.status, "failed");
  });
}
Deno.test("agent refusal carries only the safe code", async () => {
  const f = new Fixture();
  f.output = {
    ok: false,
    error: {
      code: "instance.source_busy",
      message: "private",
      details: { token: "private" },
    },
  };
  await assertRejects(() => f.execute(), Error, "instance.source_busy");
  assertEquals(f.operation.result, { error_code: "instance.source_busy" });
});
Deno.test("unbound first read and foreign placement never inspect the checkout", async () => {
  for (const variation of ["unbound", "node", "kind"]) {
    const f = new Fixture();
    if (variation === "unbound") f.unbound = true;
    if (variation === "node") f.operation.node_id = 10;
    if (variation === "kind") f.operation.kind = "instance.create";
    await assertRejects(() => f.execute());
    assertEquals(f.inspections, 0);
    assertEquals(f.calls.length, 1);
  }
});
Deno.test("cancellation before inspection skips it and cancellation during inspection still projects", async () => {
  const before = new Fixture();
  before.operation.cancel_requested = true;
  assertEquals(await before.execute(), { status: "cancelled" });
  assertEquals(before.inspections, 0);
  const during = new Fixture();
  const inspect = during.inspect;
  during.inspect = (value) => {
    during.controller.abort();
    return inspect(value);
  };
  assertEquals(await during.execute(), { status: "succeeded" });
  assertEquals(during.inspections, 1);
});
Deno.test("lost completion replays the terminal record without another local command", async () => {
  const f = new Fixture();
  f.loseCompletion = true;
  await assertRejects(() => f.execute(), Error, "lost terminal reply");
  assertEquals(await f.execute(), { status: "succeeded" });
  assertEquals(f.inspections, 1);
});
Deno.test("malformed agent output stays uncertain for native recovery", async () => {
  const f = new Fixture();
  f.output = { ok: true, data: { branch: "feature" } };
  await assertRejects(() => f.execute());
  assertEquals(f.operation.status, "running");
  assertEquals(f.calls.length, 2);
});

Deno.test("local inspection uses fixed argv and the admitted pin/input without sudo or shell", async () => {
  let envelope = "";
  let command: string | URL = "";
  let options: Deno.CommandOptions = {};
  const original = Deno.Command;
  Object.defineProperty(Deno, "Command", {
    value: class {
      constructor(cmd: string | URL, opts: Deno.CommandOptions) {
        command = cmd;
        options = opts;
      }
      spawn() {
        return {
          stdin: new WritableStream<Uint8Array>({
            write(bytes) {
              envelope += new TextDecoder().decode(bytes);
            },
          }),
          stdout: new ReadableStream<Uint8Array>({
            start(controller) {
              controller.enqueue(
                new TextEncoder().encode(
                  '{"ok":true,"data":{"origin":"git@github.com:example/app.git","branch":"feature","commit":null}}\n',
                ),
              );
              controller.close();
            },
          }),
          status: Promise.resolve({ success: true }),
          kill() {},
        };
      }
    },
  });
  try {
    assertEquals(await inspectSource(input), {
      ok: true,
      data: {
        origin: "git@github.com:example/app.git",
        branch: "feature",
        commit: null,
      },
    });
    assertEquals(command, "/usr/local/bin/orbit-agent");
    assertEquals(options, {
      args: ["command", "instance.inspect-development-source"],
      stdin: "piped",
      stdout: "piped",
      stderr: "null",
    });
    assertEquals(JSON.parse(envelope), {
      agent_sha256: input.agent_sha256,
      deadline_ms: 120000,
      input: input.source,
    });
  } finally {
    Object.defineProperty(Deno, "Command", { value: original });
  }
});

Deno.test("migration canary proves read and projections without any foreign Node action", async () => {
  const f = new Fixture();
  await assertRejects(
    () =>
      lifecycleDirect(() => f.http, f.inspect).methods.bindingCanary.execute(
        args,
        ctx,
      ),
    Error,
    "instance.node_action_unavailable",
  );
  assertEquals(f.inspections, 0);
  assertEquals(f.calls.map((call) => call.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
  assertEquals(f.calls.slice(1).map((call) => call.body!.status), [
    "running",
    "failed",
  ]);
  assertEquals(f.operation.result, {
    error_code: "instance.node_action_unavailable",
  });
});
