import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { aiRouterSource, removeAiRouterSource } from "./source.ts";
Deno.test("AI source step sends only fixed selection on stdin through the placed Node runner", async () => {
  const signal = new AbortController().signal;
  const result = await removeAiRouterSource((command, actualSignal) => {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(0, 5), [
      "-n",
      "--",
      "/usr/bin/python3",
      "-I",
      "-c",
    ]);
    assertEquals(command.stdin, '{"retired":true}');
    assertEquals(command.timeoutMs, 30000);
    assertEquals(actualSignal, signal);
    return Promise.resolve({ success: true, stdout: '{"removed":true}' });
  })({ retired: true }, signal);
  assertEquals(result, { removed: true });
});
Deno.test("AI source rejects arbitrary paths before execution", async () => {
  let calls = 0;
  const remove = removeAiRouterSource(() => {
    calls++;
    throw new Error("unexpected");
  });
  await assertRejects(() =>
    remove({ retired: false, path: "/foreign" } as { retired: boolean })
  );
  assertEquals(calls, 0);
});
Deno.test("AI source distinguishes a bounded refusal from unknown transport without leaking diagnostics", async () => {
  for (
    const code of ["ai-router.source_unsafe", "ai-router.source_remove_failed"]
  ) {
    await assertRejects(
      () =>
        removeAiRouterSource(() =>
          Promise.resolve({
            success: true,
            stdout: JSON.stringify({ error_code: code }),
          })
        )({ retired: false }),
      Error,
      code,
    );
  }
  for (const stdout of ["private-output", "{}", '{"removed":"yes"}']) {
    await assertRejects(
      () =>
        removeAiRouterSource(() => Promise.resolve({ success: true, stdout }))({
          retired: false,
        }),
      Error,
      "ai-router.source_outcome_unknown",
    );
  }
  await assertRejects(
    () =>
      removeAiRouterSource(() => {
        throw new Error("private-error");
      })({ retired: false }),
    Error,
    "ai-router.source_outcome_unknown",
  );
});
Deno.test("AI native method exposes the lower's bounded result", async () => {
  const step = aiRouterSource((input) =>
    Promise.resolve({ removed: input.retired })
  );
  assertEquals(await step.methods.remove.execute({ retired: false }, {}), {
    removed: false,
  });
});
