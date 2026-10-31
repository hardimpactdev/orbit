import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  assertDeploymentExecutionAvailable,
  saveDeploymentExecution,
} from "./deployment_execution.ts";
const owner = {
  operation_id: 1,
  run_id: "aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee",
};
function fixture() {
  let marker: Record<string, unknown> | null = null, writes = 0;
  const ctx = {
    readResource: () => Promise.resolve(marker),
    writeResource: (
      _spec: string,
      _name: string,
      value: Record<string, unknown>,
    ) => {
      marker = structuredClone(value);
      writes++;
      return Promise.resolve();
    },
  };
  return { ctx, read: () => marker, writes: () => writes };
}
Deno.test("the native model active pointer cannot be overwritten or cleared by another root", async () => {
  const f = fixture();
  await saveDeploymentExecution(f.ctx, owner, true);
  const next = {
    operation_id: 2,
    run_id: "bbbbbbbb-cccc-4ddd-8eee-ffffffffffff",
  };
  await assertRejects(
    () => assertDeploymentExecutionAvailable(f.ctx, next),
    Error,
    "deployment.target_busy",
  );
  for (const active of [false, true]) {
    await assertRejects(
      () => saveDeploymentExecution(f.ctx, next, active),
      Error,
      "deployment.target_busy",
    );
  }
  assertEquals(f.writes(), 1);
  assertEquals(f.read(), { ...owner, active: true });
  await saveDeploymentExecution(f.ctx, owner, false);
  await saveDeploymentExecution(f.ctx, next, true);
  assertEquals(f.read(), { ...next, active: true });
});
Deno.test("a malformed native model active pointer is never treated as an empty or inactive predecessor", async () => {
  const f = fixture();
  await f.ctx.writeResource("activeExecution", "activeExecution", {
    ...owner,
    active: "false",
  });
  await assertRejects(() => assertDeploymentExecutionAvailable(f.ctx, owner));
  await assertRejects(() => saveDeploymentExecution(f.ctx, owner, false));
  assertEquals(f.writes(), 1);
});
