import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { processAction, processControl } from "./process-control.ts";
const input = {
  process_id: 7,
  agent_sha256: "a".repeat(64),
  runtime: "systemd" as const,
  name: "orbit-process-7-proof.service",
  on_demand: false,
  vite: null,
};
const args = {
  operation_id: 17,
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  kind: "process.start" as const,
};
for (
  const kind of [
    "process.start",
    "process.stop",
    "process.restart",
    "process.destroy",
  ] as const
) {
  Deno.test(`${kind} uses fixed commands and exposes no raw agent output`, async () => {
    const calls: {
      name: string;
      data: Record<string, unknown>;
      digest: string;
    }[] = [];
    const action = processAction((name, data, digest) => {
      calls.push({ name, data, digest });
      return Promise.resolve(
        name === "process.status"
          ? { state: "inactive", private: "secret" }
          : {},
      );
    });
    const result = await action(kind, input);
    assertEquals(
      result,
      kind === "process.destroy"
        ? { ready: true }
        : { ready: true, state: "inactive" },
    );
    assertEquals(
      calls.map((c) => c.name),
      kind === "process.destroy"
        ? ["process.remove"]
        : ["process.control", "process.status"],
    );
    assertEquals(calls.every((c) => c.digest === input.agent_sha256), true);
    assertEquals(
      calls[0].data,
      kind === "process.destroy"
        ? { process_id: 7, runtime: "systemd", name: input.name, vite: null }
        : {
          process_id: 7,
          runtime: "systemd",
          name: input.name,
          on_demand: false,
          operation: kind.slice(8),
        },
    );
  });
}
Deno.test("an unavailable selected release refuses before any Process mutation", async () => {
  const seen: string[] = [];
  const action = processAction((name) => {
    seen.push(name);
    return Promise.resolve({ available: false });
  });
  assertEquals(
    await action("process.start", {
      ...input,
      directory: "/home/orbit-app-7/current",
    }),
    { error_code: "process.release_unavailable" },
  );
  assertEquals(seen, ["process.check-release"]);
});
Deno.test("an agent refusal stops the action before runtime status", async () => {
  const seen: string[] = [];
  const action = processAction((name) => {
    seen.push(name);
    return Promise.resolve({
      error_code: "process.name_collision",
      output: "private",
    });
  });
  assertEquals(await action("process.restart", input), {
    error_code: "process.name_collision",
  });
  assertEquals(seen, ["process.control"]);
});
function fixture() {
  const current = {
    id: 17,
    kind: args.kind as string,
    node_id: 9,
    status: "queued",
    input: { ...input } as Record<string, unknown>,
    result: null,
    cancel_requested: false,
  };
  let effects = 0;
  const calls: unknown[] = [];
  const action = processAction(() => {
    effects++;
    return Promise.resolve({ state: "active" });
  });
  const control = processControl(() => (method, _path, body) => {
    calls.push({ method, body });
    return Promise.resolve({ status: 200, body: { data: current } });
  }, action);
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "test-ca" },
  };
  return { current, control, ctx, calls, effects: () => effects };
}
Deno.test("shared Process binding projects bounded results without execution relay", async () => {
  const f = fixture();
  assertEquals(await f.control.methods.control.execute(args, f.ctx), {
    status: "succeeded",
  });
  assertEquals(f.effects(), 2);
  assertEquals(f.calls.length, 3);
});
Deno.test("a foreign Node refuses before any Process effect", async () => {
  const f = fixture();
  f.current.node_id = 8;
  await assertRejects(() => f.control.methods.control.execute(args, f.ctx));
  assertEquals(f.effects(), 0);
});
Deno.test("cancellation and an already projected success do not repeat control", async () => {
  for (const terminal of [true, false]) {
    const f = fixture();
    f.current.cancel_requested = true;
    f.current.status = terminal ? "succeeded" : "queued";
    assertEquals(await f.control.methods.control.execute(args, f.ctx), {
      status: terminal ? "succeeded" : "cancelled",
    });
    assertEquals(f.effects(), 0);
  }
});
Deno.test("a Vite plan or unadmitted command refuses before local execution", async () => {
  for (const extra of [{ command: "private" }, { vite: { instance_id: 8 } }]) {
    const f = fixture();
    Object.assign(f.current.input, extra);
    await assertRejects(() => f.control.methods.control.execute(args, f.ctx));
    assertEquals(f.effects(), 0);
  }
});

Deno.test("native destroy passes only the exact admitted Vite environment", async () => {
  let removed: Record<string, unknown> | undefined;
  const vite = { instance_id: 8, path: "/etc/orbit/vite/app-instance-8.env" };
  const action = processAction((_name, data) => {
    removed = data;
    return Promise.resolve({});
  });
  assertEquals(await action("process.destroy", { ...input, vite }), {
    ready: true,
  });
  assertEquals(removed, {
    process_id: input.process_id,
    runtime: input.runtime,
    name: input.name,
    vite,
  });
  const f = fixture();
  f.current.kind = "process.destroy";
  f.current.input.vite = {
    instance_id: 8,
    path: "/etc/orbit/vite/app-instance-8.env",
  };
  assertEquals(
    await f.control.methods.control.execute({
      ...args,
      kind: "process.destroy",
    }, f.ctx),
    { status: "succeeded" },
  );
  assertEquals(f.effects(), 1);
  for (
    const path of [
      "/etc/orbit/vite/app-instance-9.env",
      "/tmp/app-instance-8.env",
      "/etc/orbit/vite/app-instance-8.env/../other",
    ]
  ) {
    const invalid = fixture();
    invalid.current.kind = "process.destroy";
    invalid.current.input.vite = { instance_id: 8, path };
    await assertRejects(() =>
      invalid.control.methods.control.execute({
        ...args,
        kind: "process.destroy",
      }, invalid.ctx)
    );
    assertEquals(invalid.effects(), 0);
  }
});
