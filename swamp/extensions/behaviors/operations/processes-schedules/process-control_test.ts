import { assertEquals } from "jsr:@std/assert@1.0.14";
import { processAction } from "./process-control.ts";
const input = {
  process_id: 7,
  agent_sha256: "a".repeat(64),
  runtime: "systemd" as const,
  name: "orbit-process-7-proof.service",
  on_demand: false,
  vite: null,
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
      digest: string | undefined;
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

});

Deno.test("native parent Process restart needs no agent digest", async () => {
  const { agent_sha256: _stamp, ...admitted } = input;
  const calls: string[] = [];
  const action = processAction((name, _value, digest) => {
    assertEquals(digest, undefined);
    calls.push(name);
    return Promise.resolve(
      name === "process.status" ? { state: "active" } : {},
    );
  });
  assertEquals(await action("process.restart", admitted), {
    ready: true,
    state: "active",
  });
  assertEquals(calls, ["process.control", "process.status"]);
});
