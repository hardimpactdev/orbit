import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../tests/_support/native_harness.ts";
import { shellSplit } from "../../../tests/_support/fake_tool.ts";

const Sentinel = "private-test-sentinel";

Deno.test("private bytes across a read boundary are detected without echo", async () => {
  await using repo = await nativeRepo();
  repo.secrets = [Sentinel];
  await Deno.writeTextFile(`${repo.dir}/history`, "x".repeat(1024 * 1024 - 3) + Sentinel);
  const failure = await assertRejects(() => repo.assertPrivateAbsent());
  assertEquals(String(failure).includes(Sentinel), false);
});

Deno.test("private CLI output is detected without echo", async () => {
  await using repo = await nativeRepo();
  repo.secrets = [Sentinel];
  const failure = await assertRejects(() => repo.assertPrivateAbsent(Sentinel));
  assertEquals(String(failure).includes(Sentinel), false);
});

Deno.test("an inherited extensions root does not reach the native process", async () => {
  await using repo = await nativeRepo();
  const probe = await repo.fakeBinary(
    "env-probe",
    'if (Deno.env.get("SWAMP_EXTENSIONS_DIR") !== undefined) Deno.exit(1);\nconsole.log("isolated");',
  );
  const saved = { bin: Deno.env.get("SWAMP_BIN"), extensions: Deno.env.get("SWAMP_EXTENSIONS_DIR") };
  Deno.env.set("SWAMP_BIN", probe);
  Deno.env.set("SWAMP_EXTENSIONS_DIR", "/inherited/extensions");
  try {
    assertEquals((await repo.cli([])).stdout.trim(), "isolated");
  } finally {
    for (const [key, value] of [["SWAMP_BIN", saved.bin], ["SWAMP_EXTENSIONS_DIR", saved.extensions]]) {
      if (value === undefined) Deno.env.delete(key!);
      else Deno.env.set(key!, value);
    }
  }
});

Deno.test("fake tools split shell words like shlex", () => {
  assertEquals(shellSplit(`sudo -n -- 'a b' "c \\"d\\"" e\\ f ''`), ["sudo", "-n", "--", "a b", 'c "d"', "e f", ""]);
});
