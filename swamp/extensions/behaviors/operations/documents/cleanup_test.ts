import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { documentsCleanup, prepareDocumentsCleanup } from "./cleanup.ts";

Deno.test("Documents startup step executes fixed native code without agent, PHP or caller paths", async () => {
  const abort = new AbortController();
  const result = await prepareDocumentsCleanup((command, signal) => {
    assertEquals(command.command, "/usr/bin/sudo");
    assertEquals(command.args.slice(0, 5), [
      "-n",
      "--",
      "/usr/bin/python3",
      "-I",
      "-c",
    ]);
    assertEquals(command.stdin, undefined);
    assertEquals(command.timeoutMs, 60000);
    assertEquals(signal, abort.signal);
    return Promise.resolve({
      success: true,
      stdout: JSON.stringify({ generation: "a".repeat(64) }),
    });
  })(abort.signal);
  assertEquals(result, { generation: "a".repeat(64) });
});
Deno.test("Documents refuses malformed or unconfirmed startup outcomes", async () => {
  for (
    const reply of [
      { success: false, stdout: "" },
      { success: true, stdout: "{}" },
      { success: true, stdout: "{" },
      { success: true, stdout: '{"generation":"short"}' },
    ]
  ) {
    await assertRejects(
      () => prepareDocumentsCleanup(() => Promise.resolve(reply))(),
      Error,
      "documents.cleanup_outcome_unknown",
    );
  }
  await assertRejects(
    () =>
      prepareDocumentsCleanup(() =>
        Promise.resolve({
          success: true,
          stdout:
            '{"error_code":"project_documents.cleanup_state_unavailable"}',
        })
      )(),
    Error,
    "project_documents.cleanup_state_unavailable",
  );
});
Deno.test("Documents native method exposes only its bounded generation output", async () => {
  const step = documentsCleanup(() =>
    Promise.resolve({ generation: "b".repeat(64) })
  );
  assertEquals(step.type, "@nckrtl/documents-cleanup");
  assertEquals(await step.methods.prepare.execute({}, {}), {
    generation: "b".repeat(64),
  });
});

Deno.test("Documents transport exceptions never expose unbounded diagnostics", async () => {
  const error = await assertRejects(
    () =>
      prepareDocumentsCleanup(() => {
        throw new Error("private-transport-detail");
      })(),
    Error,
    "documents.cleanup_outcome_unknown",
  );
  assertEquals(error.message, "documents.cleanup_outcome_unknown");
});
