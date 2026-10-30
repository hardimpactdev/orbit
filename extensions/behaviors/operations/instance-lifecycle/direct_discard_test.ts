import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { lifecycleDirect } from "./direct.ts";
import { discard, discardDestination } from "./direct_discard.ts";

const args = {
  operation_id: 17,
  node_id: 9,
  kind: "instance.transfer_discard" as const,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};
const input = {
  agent_sha256: "a".repeat(64),
  destination: "/srv/apps/example/proof",
};
const ctx = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca.pem" },
};
class Fixture {
  operation = {
    id: 17,
    node_id: 9,
    kind: args.kind as string,
    status: "queued",
    input,
    result: null as unknown,
    cancel_requested: false,
  };
  calls: { path: string; body?: Record<string, unknown> }[] = [];
  removed = 0;
  loseReply = false;
  outcome: unknown = { ok: true, data: {} };
  action = async (value: unknown) => {
    assertEquals(value, input);
    this.removed++;
    return await Promise.resolve(this.outcome);
  };
  http: Http = async (method, path, body) => {
    this.calls.push({ path, body: body as Record<string, unknown> });
    if (method !== "GET") {
      const result = body as Record<string, unknown>;
      this.operation.status = String(result.status);
      this.operation.result = result.result;
      if (this.loseReply && result.status === "succeeded") {
        this.loseReply = false;
        throw new Error("lost result reply");
      }
    }
    return await Promise.resolve({
      status: 200,
      body: { data: this.operation },
    });
  };
  run() {
    return lifecycleDirect(() => this.http, () => {
      throw new Error("Discard must not inspect source");
    }, this.action)
      .methods.execute.execute(args, ctx);
  }
}
Deno.test("direct discard reads only its admitted destination and projects cleanup without executor endpoints", async () => {
  const f = new Fixture();
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.removed, 1);
  assertEquals(f.operation.result, { discarded: true });
  assertEquals(f.calls.map((call) => call.path), [
    `/api/v1/direct-operations/17?run_id=${args.run_id}`,
    "/api/v1/direct-operations/17/result",
    "/api/v1/direct-operations/17/result",
  ]);
});
Deno.test("discard lost terminal reply does not repeat the Node effect", async () => {
  const f = new Fixture();
  f.loseReply = true;
  await assertRejects(() => f.run(), Error, "lost result reply");
  assertEquals(await f.run(), { status: "succeeded" });
  assertEquals(f.removed, 1);
});
Deno.test("discard respects cancellation and foreign placement before the command", async () => {
  const cancelled = new Fixture();
  cancelled.operation.cancel_requested = true;
  assertEquals(await cancelled.run(), { status: "cancelled" });
  assertEquals(cancelled.removed, 0);
  const foreign = new Fixture();
  foreign.operation.node_id = 10;
  await assertRejects(() => foreign.run());
  assertEquals(foreign.removed, 0);
});
Deno.test("discard projects only a typed error code, while malformed outcomes remain uncertain", async () => {
  const failed = new Fixture();
  failed.outcome = {
    ok: false,
    error: { code: "instance.transfer_source_failed", message: "private path" },
  };
  await assertRejects(
    () => failed.run(),
    Error,
    "instance.transfer_source_failed",
  );
  assertEquals(failed.operation.result, {
    error_code: "instance.transfer_source_failed",
  });
  const unknown = new Fixture();
  unknown.outcome = { ok: true, data: { private: "unrecognized" } };
  await assertRejects(() => unknown.run());
  assertEquals(unknown.operation.status, "running");
});
Deno.test("discard refuses noncanonical or shallow destinations before any command", async () => {
  for (
    const destination of [
      "/",
      "/srv/apps",
      "/srv/apps/../home/proof",
      "/srv/apps//proof",
      "/srv/apps/proof/",
      "relative/proof",
      "/srv/apps/proof\n",
      "/srv/apps/\u0000proof",
      "/srv/apps/\u007fproof",
    ]
  ) {
    let calls = 0;
    await assertRejects(() =>
      discard({ ...input, destination }, () => {
        calls++;
        return Promise.resolve({ ok: true, data: {} });
      })
    );
    assertEquals(calls, 0);
  }
});
Deno.test("discard observes missing paths and refuses symlink ancestors without following them", async () => {
  const root = await Deno.makeTempDir({ prefix: "orbit-discard-" });
  try {
    assertEquals(
      await discardDestination({ ...input, destination: root + "/absent" }),
      { ok: true, data: {} },
    );
    await Deno.mkdir(root + "/actual");
    await Deno.writeTextFile(root + "/actual/keep", "preserved");
    await Deno.symlink(root + "/actual", root + "/link");
    assertEquals(
      await discardDestination({ ...input, destination: root + "/link/keep" }),
      { ok: false, error: { code: "instance.transfer_archive_invalid" } },
    );
    assertEquals(await Deno.readTextFile(root + "/actual/keep"), "preserved");
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("discard uses fixed sudo argv, admitted digest, account input and native deadline", async () => {
  const root = await Deno.makeTempDir({ prefix: "orbit-discard-" });
  const destination = root + "/destination";
  await Deno.mkdir(destination);
  const original = Deno.Command;
  let command: string | URL = "";
  let options: Deno.CommandOptions = {};
  let envelope = "";
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
                new TextEncoder().encode('{"ok":true,"data":{}}\n'),
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
    assertEquals(await discardDestination({ ...input, destination }), {
      ok: true,
      data: {},
    });
    assertEquals(command, "/usr/bin/sudo");
    assertEquals(options, {
      args: [
        "-n",
        "/usr/local/bin/orbit-agent",
        "command",
        "instance.transfer-source",
      ],
      stdin: "piped",
      stdout: "piped",
      stderr: "null",
    });
    assertEquals(JSON.parse(envelope), {
      agent_sha256: input.agent_sha256,
      deadline_ms: 600000,
      input: { operation: "discard", destination },
    });
  } finally {
    Object.defineProperty(Deno, "Command", { value: original });
    await Deno.remove(root, { recursive: true });
  }
});
