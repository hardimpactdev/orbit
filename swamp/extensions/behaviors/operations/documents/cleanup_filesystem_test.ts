/** Real scratch filesystem coverage for the exact fixed startup program. */
import { assert, assertEquals, assertNotEquals } from "jsr:@std/assert@1.0.19";
import { cleanupFixture, exists } from "./cleanup_fixture_test.ts";
const test = (
  name: string,
  body: (f: Awaited<ReturnType<typeof cleanupFixture>>) => Promise<void>,
) =>
  Deno.test(name, async () => {
    await using f = await cleanupFixture();
    await body(f);
  });
test("creates paused bound generation and preserves lock when repeated", async (f) => {
  const first = await f.start(), inode = Deno.statSync(f.lock).ino;
  f.arm();
  const second = await f.start();
  assertNotEquals(first, second);
  assertEquals(Deno.statSync(f.lock).ino, inode);
  assert(!exists(f.permit));
  assertEquals(f.state().lock_inode, inode);
  assertEquals(f.state().lock_device, Deno.statSync(f.lock).dev);
  assertEquals(f.state().report_id, null);
  assertEquals(f.state().report_sha256, null);
  for (
    const [path, mode] of [[f.directory, 0o700], [f.lock, 0o600], [
      f.generation,
      0o600,
    ]] as const
  ) {
    assertEquals(Deno.statSync(path).mode! & 0o7777, mode);
    assertEquals(Deno.statSync(path).uid, Deno.uid());
  }
});
test("never repairs a missing existing lock", async (f) => {
  await f.start();
  Deno.removeSync(f.lock);
  await f.refused();
  assert(!exists(f.lock));
});
test("rejects symlinked paths without touching targets", async (f) => {
  await f.start();
  f.arm();
  for (const path of [f.permit, f.generation, f.lock, f.directory, f.root]) {
    const saved = `${f.base}/saved`;
    Deno.renameSync(path, saved);
    Deno.symlinkSync(saved, path);
    await f.refused();
    assert(Deno.lstatSync(path).isSymlink);
    Deno.removeSync(path);
    Deno.renameSync(saved, path);
  }
});
test("rejects hardlinked state and lock", async (f) => {
  await f.start();
  f.arm();
  for (const path of [f.lock, f.generation, f.permit]) {
    const extra = `${f.base}/extra`;
    Deno.linkSync(path, extra);
    await f.refused();
    Deno.removeSync(extra);
  }
});
test("refuses mode drift without repair", async (f) => {
  await f.start();
  f.arm();
  for (const path of [f.root, f.directory, f.lock, f.generation, f.permit]) {
    const previous = Deno.statSync(path).mode! & 0o7777;
    Deno.chmodSync(path, 0o777);
    await f.refused();
    assertEquals(Deno.statSync(path).mode! & 0o7777, 0o777);
    Deno.chmodSync(path, previous);
  }
});
test("refuses a generation bound to a different lock", async (f) => {
  await f.start();
  f.arm();
  const state = f.state();
  state.lock_inode++;
  Deno.writeTextFileSync(f.generation, JSON.stringify(state));
  await f.refused();
  assert(exists(f.permit));
});
test("refuses malformed oversized and wrong shape generation", async (f) => {
  await f.start();
  for (
    const value of [
      "{",
      "x".repeat(8193),
      JSON.stringify({ generation: "a".repeat(64) }),
    ]
  ) {
    Deno.writeTextFileSync(f.generation, value);
    await f.refused();
  }
});
test("failed atomic publication cannot leave old authorization", async (f) => {
  await f.start();
  f.arm();
  assert(
    "error_code" in
      await f.invoke(
        f.source.replace(
          "os.replace(candidate,generation)",
          "raise OSError('injected publication failure')",
        ),
      ),
  );
  assert(!exists(f.permit));
  assert(!exists(f.generation));
  assertEquals([...Deno.readDirSync(f.directory)].map((e) => e.name).sort(), [
    "execution.lock",
  ]);
});
test("uses same lock as a running cleanup", async (f) => {
  await f.start();
  f.arm();
  using lock = Deno.openSync(f.lock, { read: true, write: true });
  await lock.lock(true);
  const child = f.spawn();
  await child.stdin.close();
  let finished = false;
  const output = child.output().then((result) => {
    finished = true;
    return result;
  });
  const timer = setTimeout(() => {
    if (!finished) child.kill("SIGKILL");
  }, 3000);
  try {
    await new Promise((resolve) => setTimeout(resolve, 100));
    assert(!finished);
    assert(exists(f.permit));
    await lock.unlock();
    const result = await output;
    assertEquals(result.code, 0);
    assertEquals(new TextDecoder().decode(result.stderr), "");
    assert(
      "generation" in JSON.parse(new TextDecoder().decode(result.stdout)),
    );
  } finally {
    clearTimeout(timer);
    if (!finished) {
      child.kill("SIGKILL");
      await output;
    }
  }
});
