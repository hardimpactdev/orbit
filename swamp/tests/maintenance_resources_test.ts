import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type Context,
  failureReceipts,
} from "../extensions/behaviors/_lib/maintenance/context.ts";
import { MaintenanceFailure } from "../extensions/behaviors/_lib/maintenance/io.ts";

Deno.test("failure capture is opt-in and never swallows abort or infrastructure failure", async () => {
  const receipts: Record<string, unknown>[] = [];
  const ctx: Context = {
    writeResource: (_spec, name, data) => {
      receipts.push(data);
      return Promise.resolve({ name });
    },
  };
  let error: Error = new MaintenanceFailure("update failed", 23);
  const methods = failureReceipts({
    run: {
      execute: (_input: { captureFailure?: boolean }, _ctx: Context) =>
        Promise.reject(error),
    },
  });
  await assertRejects(() => methods.run.execute({}, ctx), MaintenanceFailure);
  await methods.run.execute({ captureFailure: true }, ctx);
  assertEquals(receipts, [{ exitCode: 23, message: "update failed" }]);
  const controller = new AbortController();
  controller.abort();
  await assertRejects(
    () =>
      methods.run.execute({ captureFailure: true }, {
        ...ctx,
        signal: controller.signal,
      }),
    MaintenanceFailure,
  );
  error = new Error("worker transport lost");
  await assertRejects(
    () => methods.run.execute({ captureFailure: true }, ctx),
    Error,
    "transport lost",
  );
  assertEquals(receipts.length, 1);
});
