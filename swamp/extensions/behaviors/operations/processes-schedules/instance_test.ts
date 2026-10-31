import { assertEquals } from "jsr:@std/assert@1.0.14";
import { instanceMethods } from "./instance.ts";

const args = {
  operation_id: 17,
  kind: "process.restart_instance" as const,
  node_id: 9,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  member_id: 7,
  publication_status: "skipped" as const,
};
function fixture(status: "running" | "succeeded" | "failed" | "cancelled") {
  const calls: string[] = [];
  const writes: unknown[] = [];
  const methods = instanceMethods(() => () =>
    Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 17,
          kind: args.kind,
          node_id: 9,
          status,
          cancel_requested: false,
          input: {
            instance_id: 8,
            members: [{
              id: 7,
              input: {
                process_id: 7,
                agent_sha256: "a".repeat(64),
                runtime: "docker",
                name: "orbit-process-7-proof",
                on_demand: false,
                vite: null,
              },
            }],
          },
        },
      },
    }), (name) => {
    calls.push(name);
    return Promise.resolve(
      name === "process.status" ? { state: "active" } : {},
    );
  });
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "test-ca" },
    readResource: () =>
      Promise.resolve({ member_ids: [7], proceed: true, vite: false }),
    writeResource: (_type: string, _name: string, value: unknown) => {
      writes.push(value);
      return Promise.resolve({});
    },
  };
  return { methods, ctx, calls, writes };
}
Deno.test("terminal Instance parents skip saved prepared members without another effect", async () => {
  for (const status of ["succeeded", "failed", "cancelled"] as const) {
    const f = fixture(status);
    assertEquals(await f.methods.applyInstanceMember.execute(args, f.ctx), {
      status,
    });
    assertEquals(f.calls, []);
    assertEquals(f.writes, []);
  }
});
Deno.test("a running Instance parent executes and saves its admitted member outcome", async () => {
  const f = fixture("running");
  await f.methods.applyInstanceMember.execute(args, f.ctx);
  assertEquals(f.calls, ["process.control", "process.status"]);
  assertEquals(f.writes, [{ id: 7, ready: true, state: "active" }]);
});
