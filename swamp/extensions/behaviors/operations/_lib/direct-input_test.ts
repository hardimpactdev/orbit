import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import {
  DirectArgs,
  DirectOperationCancelled,
  DirectResult,
} from "./direct-input.ts";

const intent = {
  operation_id: 71,
  kind: "sample.apply",
  node_id: null,
  run_id: "a075eb23-7ac4-4487-80d1-57a038a92676",
};

Deno.test("public schemas validate without network, process or environment access", () => {
  assertEquals(DirectArgs.parse(intent), intent);
  assertEquals(DirectArgs.parse({ ...intent, node_id: 4 }).node_id, 4);
  for (
    const invalid of [
      { operation_id: 0 },
      { node_id: -1 },
      { kind: "sample" },
      { run_id: "not-a-run" },
    ]
  ) {
    assertEquals(
      DirectArgs.safeParse({ ...intent, ...invalid }).success,
      false,
    );
  }
  assertEquals(DirectResult.parse([]), {});
  assertEquals(DirectResult.parse({ changed: true }), { changed: true });
  assertEquals(DirectResult.safeParse(["not-an-object"]).success, false);
});

Deno.test("cancellation still requires explicit evidence that no effect is running", () => {
  const cancelled = new DirectOperationCancelled({ effectRunning: false });
  assertEquals(cancelled instanceof Error, true);
  assertThrows(
    () => new DirectOperationCancelled({ effectRunning: true as false }),
    Error,
    "Cancellation requires confirmation that no effect is running",
  );
});
