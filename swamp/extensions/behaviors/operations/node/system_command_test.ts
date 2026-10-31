import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { localSystemCommand } from "./system_command.ts";

const command = (source: string, stdin?: string, timeoutMs = 2000) => ({
  command: "/usr/bin/python3",
  args: ["-c", source],
  stdin,
  timeoutMs,
});

Deno.test("system transport drains output while writing input beyond pipe capacity", async () => {
  const result = await localSystemCommand(command(
    "import sys; sys.stdout.write('ready\\n'); sys.stdout.flush(); data=sys.stdin.read(); print(len(data))",
    "x".repeat(256 * 1024),
  ));
  assertEquals(result, { success: true, stdout: "ready\n262144\n" });
});

Deno.test("large full duplex echo refuses its output bound without blocking", async () => {
  await assertRejects(
    () =>
      localSystemCommand(command(
        "import sys; sys.stdout.write(sys.stdin.read()); sys.stdout.flush()",
        "x".repeat(256 * 1024),
      )),
    Error,
    "no confirmed outcome",
  );
});

Deno.test("system transport refuses output above 64KiB", async () => {
  await assertRejects(
    () =>
      localSystemCommand(command(
        "import sys; sys.stdout.write('x'*65537)",
      )),
    Error,
    "no confirmed outcome",
  );
});

Deno.test("deadline termination is uncertain even when child status resolves false", async () => {
  await assertRejects(
    () =>
      localSystemCommand(command(
        "import time; time.sleep(30)",
        undefined,
        20,
      )),
    Error,
    "no confirmed outcome",
  );
});

Deno.test("pre-effect cancellation invokes no system command", async () => {
  const controller = new AbortController();
  controller.abort();
  await assertRejects(() =>
    localSystemCommand({
      command: "/this/program/must/not/run",
      args: [],
      timeoutMs: 100,
    }, controller.signal)
  );
});

Deno.test("late cancellation retains the completed system outcome", async () => {
  const controller = new AbortController();
  const result = localSystemCommand(
    command(
      "import time; time.sleep(0.05); print('complete')",
    ),
    controller.signal,
  );
  controller.abort();
  assertEquals(await result, { success: true, stdout: "complete\n" });
});

Deno.test("private stdin and stderr never enter bounded failure diagnostics", async () => {
  await assertRejects(
    () =>
      localSystemCommand(command(
        "import sys,time; print(sys.stdin.read(), file=sys.stderr); time.sleep(30)",
        "synthetic-private-secret",
        20,
      )),
    Error,
    "The system command has no confirmed outcome",
  );
});

Deno.test("per-call output bound permits a private in-memory snapshot above the default", async () => {
  const result = await localSystemCommand({
    ...command("import sys; sys.stdout.write('x'*131072)"),
    maxOutputBytes: 131072,
  });
  assertEquals(result.success, true);
  assertEquals(result.stdout.length, 131072);
  await assertRejects(
    () =>
      localSystemCommand({
        ...command("import sys; sys.stdout.write('x'*131073)"),
        maxOutputBytes: 131072,
      }),
    Error,
    "no confirmed outcome",
  );
});
Deno.test("invalid output bounds refuse before spawning a command", async () => {
  for (const maxOutputBytes of [0, -1, 1.5, 64 * 1024 * 1024 + 1]) {
    await assertRejects(
      () =>
        localSystemCommand({
          command: "/must-not-execute",
          args: [],
          timeoutMs: 1000,
          maxOutputBytes,
        }),
      Error,
      "output bound",
    );
  }
});
