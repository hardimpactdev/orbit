import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { collectLogs } from "./doctor_logs.ts";
import type { Row } from "./doctor_contract.ts";
const record = (
  className = "RuntimeException",
  path = "/gateway/app/Jobs/Probe.php",
  call = "App\\\\Jobs\\\\Probe->handle()",
  message = "secret-message-sentinel",
) =>
  `[2026-10-31 10:20:30] testing.ERROR: ${message} {"request_id":"request-123","exception":"[object] (${className}(code: 0): ${message} at ${path}:1)\n[stacktrace]\n#0 /vendor/framework.php(5): ignore()\n#1 ${path}(15): ${call}\n"}\n`;
async function logs(run: (directory: string, input: Row) => Promise<void>) {
  const directory = await Deno.makeTempDir();
  try {
    await run(directory, {
      log_directory: directory,
      base_directory: "/gateway",
      cursor: null,
    });
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
}

Deno.test("first collection starts at the end and ignores log history", () =>
  logs(async (directory, input) => {
    const path = `${directory}/laravel.log`;
    await Deno.writeTextFile(path, record());
    const result = await collectLogs(input);
    assertEquals(result.signals, []);
    assertEquals((result.cursor as Row).offset, (await Deno.stat(path)).size);
    await Deno.writeTextFile(path, record(), { append: true });
    const next = await collectLogs({ ...input, cursor: result.cursor });
    assertEquals(next.signals, [{
      exception_class: "RuntimeException",
      frame: "app/Jobs/Probe.php:App\\Jobs\\Probe->handle",
      request_id: "request-123",
      recorded_at: "2026-10-31 10:20:30",
    }]);
    assert(!JSON.stringify(next).includes("secret-message-sentinel"));
    assert(!JSON.stringify(next).includes("excerpt"));
  }));

Deno.test("rotation finishes the old inode then selects the new file at zero", () =>
  logs(async (directory, input) => {
    const path = `${directory}/laravel.log`;
    await Deno.writeTextFile(path, "");
    const initial = await collectLogs(input);
    await Deno.writeTextFile(path, record(), { append: true });
    await Deno.rename(path, `${directory}/laravel-rotated.log`);
    await Deno.writeTextFile(path, record("LogicException"));
    const rotated = await collectLogs({ ...input, cursor: initial.cursor });
    assertEquals(
      (rotated.signals as Row[]).map((signal) => signal.exception_class),
      ["RuntimeException"],
    );
    assertEquals((rotated.cursor as Row).path, path);
    assertEquals((rotated.cursor as Row).offset, 0);
    const latest = await collectLogs({ ...input, cursor: rotated.cursor });
    assertEquals(
      (latest.signals as Row[]).map((signal) => signal.exception_class),
      ["LogicException"],
    );
  }));

Deno.test("a vanished inode restarts its replacement but copytruncate starts at the new end", () =>
  logs(async (directory, input) => {
    const path = `${directory}/laravel.log`;
    await Deno.writeTextFile(path, record());
    const initial = await collectLogs(input);
    await Deno.writeTextFile(path, "short");
    const truncated = await collectLogs({ ...input, cursor: initial.cursor });
    assertEquals((truncated.cursor as Row).offset, 5);
    assertEquals(truncated.signals, []);
    // Hold the old inode with a hard link, so the replacement cannot reuse it.
    await Deno.link(path, `${directory}/old-inode`);
    await Deno.remove(path);
    await Deno.writeTextFile(path, record("LogicException"));
    const replaced = await collectLogs({ ...input, cursor: truncated.cursor });
    assertEquals(
      (replaced.signals as Row[]).map((signal) => signal.exception_class),
      ["LogicException"],
    );
  }));

Deno.test("daily selection uses newest mtime and laravel.log takes precedence", () =>
  logs(async (directory, input) => {
    const older = `${directory}/laravel-2026-10-30.log`;
    const newer = `${directory}/laravel-2026-10-31.log`;
    await Deno.writeTextFile(older, record());
    await Deno.writeTextFile(newer, record());
    await Deno.utime(older, 100, 100);
    await Deno.utime(newer, 200, 200);
    assertEquals(((await collectLogs(input)).cursor as Row).path, newer);
    await Deno.writeTextFile(`${directory}/laravel.log`, "");
    assertEquals(
      ((await collectLogs(input)).cursor as Row).path,
      `${directory}/laravel.log`,
    );
  }));

Deno.test("noise validation and sub-500 HTTP exceptions never count", () =>
  logs(async (directory, input) => {
    const path = `${directory}/laravel.log`;
    await Deno.writeTextFile(path, "");
    const initial = await collectLogs(input);
    await Deno.writeTextFile(
      path,
      [
        record("ValidationException"),
        record(
          "Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\NotFoundHttpException",
        ),
        record("RuntimeException", "/vendor/framework.php"),
        record().replace(".ERROR:", ".WARNING:"),
        record(
          "Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\ServiceUnavailableHttpException",
        ),
      ].join(""),
    );
    const next = await collectLogs({ ...input, cursor: initial.cursor });
    assertEquals(
      (next.signals as Row[]).map((signal) => signal.exception_class),
      ["Symfony\\Component\\HttpKernel\\Exception\\ServiceUnavailableHttpException"],
    );
  }));

Deno.test("closure frames lose line numbers and runtime arguments", () =>
  logs(async (directory, input) => {
    const path = `${directory}/laravel.log`;
    await Deno.writeTextFile(path, "");
    const initial = await collectLogs(input);
    await Deno.writeTextFile(
      path,
      record(
        "RuntimeException",
        "/gateway/app/Http/Middleware/Access.php",
        "Illuminate\\\\Pipeline\\\\Pipeline->{closure:{closure:Illuminate\\\\Pipeline\\\\Pipeline::carry():194}:195}(secret-argument)",
      ),
    );
    const next = await collectLogs({ ...input, cursor: initial.cursor });
    assertEquals(
      (next.signals as Row[])[0].frame,
      "app/Http/Middleware/Access.php:Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry()}}",
    );
    assert(!JSON.stringify(next).includes("secret-argument"));
  }));

Deno.test("a large record crosses the budget and the hard cap advances without a stuck cursor", () =>
  logs(async (directory, input) => {
    const path = `${directory}/laravel.log`;
    await Deno.writeTextFile(path, "");
    const initial = await collectLogs(input);
    const large = record() + "x".repeat(1100000) + "\n";
    await Deno.writeTextFile(path, large + record("LogicException"));
    const next = await collectLogs({ ...input, cursor: initial.cursor });
    assertEquals((next.signals as Row[]).length, 2);
    assertEquals((next.cursor as Row).offset, (await Deno.stat(path)).size);
    await Deno.writeTextFile(path, "x".repeat(8388608 + 100));
    const capped = await collectLogs({
      ...input,
      cursor: { ...(initial.cursor as Row), offset: 0 },
    });
    assertEquals((capped.cursor as Row).offset, 8388608);
    assertEquals(capped.signals, []);
  }));

Deno.test("missing files preserve the cursor and a missing initial directory produces no cursor", () =>
  logs(async (directory, input) => {
    assertEquals(await collectLogs(input), { cursor: null, signals: [] });
    const cursor = {
      path: `${directory}/laravel.log`,
      inode: 9999,
      offset: 100,
    };
    assertEquals(await collectLogs({ ...input, cursor }), {
      cursor,
      signals: [],
    });
  }));
