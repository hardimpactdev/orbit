import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { nodeAgentRekey } from "./rekey_direct.ts";

const args = {
  operation_id: 7,
  kind: "node.agent_rekey" as const,
  node_id: 13,
  run_id: "ff3b7992-7376-4ac7-a49f-ed45bedc8de5",
};
const context = {
  globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/test-ca" },
  writeResource: () => Promise.resolve({}),
};

Deno.test("rekey observes only the exact admitted Node before an effect", async () => {
  let effects = 0;
  const behavior = nodeAgentRekey(
    () => {
      effects++;
      return Promise.resolve({
        rekeyed: true as const,
        restarted: true as const,
      });
    },
    () => () =>
      Promise.resolve({
        status: 200,
        body: {
          data: { ...args, id: 7, node_id: 209, cancel_requested: false },
        },
      }),
  );
  await assertRejects(() => behavior.methods.observe.execute(args, context));
  assertEquals(effects, 0);
});

Deno.test("rekey refuses another run's cancellation checkpoint before HTTP or effects", async () => {
  let calls = 0;
  const behavior = nodeAgentRekey(undefined, () => () => {
    calls++;
    return Promise.resolve({ status: 500, body: {} });
  });
  await assertRejects(
    () =>
      behavior.methods.execute.execute({
        ...args,
        observation: {
          operation_id: 7,
          run_id: "3a332a4f-0911-4e9d-9a18-09899b7b1864",
          cancel_requested: false,
        },
      }, context),
    Error,
    "Recovery observation belongs to another native run",
  );
  assertEquals(calls, 0);
});
