import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { nativeFailure } from "./failure.ts";

Deno.test("native failure reporter sends only fixed root identity and no diagnostics", async () => {
  const calls: unknown[] = [];
  const behavior = nativeFailure(() => (method, path, body) => {
    calls.push({ method, path, body });
    return Promise.resolve({
      status: 200,
      body: { data: { status: "failed" } },
    });
  });
  const args = {
    operation_id: 71,
    node_id: 9,
    kind: "sample.apply",
    run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
  };
  await behavior.methods.report.execute(args, {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
  });
  assertEquals(calls, [{
    method: "POST",
    path: "/api/v1/direct-operations/71/failure",
    body: { run_id: args.run_id },
  }]);
});

Deno.test("native failure reporter refuses invalid root identity before HTTP", async () => {
  let calls = 0;
  const behavior = nativeFailure(() => () => {
    calls++;
    return Promise.resolve({ status: 200, body: { data: {} } });
  });
  await assertRejects(() =>
    behavior.methods.report.execute({
      operation_id: -1,
      node_id: 9,
      kind: "sample.apply",
      run_id: "bad",
    }, {
      globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/tmp/ca" },
    })
  );
  assertEquals(calls, 0);
});
