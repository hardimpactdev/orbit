import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { policyActivity } from "./policy_activity.ts";

Deno.test("native Activity root uses only the bound public parent result endpoint", async () => {
  const posts: unknown[] = [], paths: string[] = [];
  const args = {
    operation_id: 7,
    node_id: 209,
    kind: "instance.policy_event" as const,
    run_id: "d258ac70-b349-4d58-b1f5-71ef899a8ce9",
  };
  const behavior = policyActivity(() => (method, path, body) => {
    paths.push(path);
    if (method === "POST") posts.push(body);
    return Promise.resolve({
      status: 200,
      body: {
        data: method === "GET"
          ? {
            id: 7,
            node_id: 209,
            kind: args.kind,
            status: "queued",
            result: null,
            input: { event: "activity", result: "started" },
            cancel_requested: false,
          }
          : {},
      },
    });
  });
  assertEquals(
    await behavior.methods.run.execute(args, {
      globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
    }),
    { status: "succeeded" },
  );
  assertEquals(paths, [
    "/api/v1/direct-operations/7?run_id=" + args.run_id,
    "/api/v1/direct-operations/7/result",
    "/api/v1/direct-operations/7/result",
  ]);
  assertEquals((posts as { status: string }[]).map((p) => p.status), [
    "running",
    "succeeded",
  ]);
});

Deno.test("Activity refuses a foreign Node before projecting any record", async () => {
  let posts = 0;
  const behavior = policyActivity(() => (method) => {
    if (method === "POST") posts++;
    return Promise.resolve({
      status: 200,
      body: {
        data: {
          id: 7,
          node_id: 9,
          kind: "instance.policy_event",
          status: "queued",
          result: null,
          input: {},
          cancel_requested: false,
        },
      },
    });
  });
  await assertRejects(
    () =>
      behavior.methods.run.execute({
        operation_id: 7,
        node_id: 209,
        kind: "instance.policy_event",
        run_id: "d258ac70-b349-4d58-b1f5-71ef899a8ce9",
      }, {
        globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
      }),
    Error,
    "Direct Operation does not match admitted intent",
  );
  assertEquals(posts, 0);
});
