/** Token-authenticated scratch serve for native resume tests. */
import { assert } from "jsr:@std/assert@1.0.19";
import { type NativeRepo } from "./native_harness.ts";

export async function nativeServer(repo: NativeRepo) {
  await repo.cli(["access", "token", "mint", "fixture-admin", "--principal", "user:fixture"]);
  const token = JSON.parse((await repo.cli(["access", "token", "reveal", "fixture-admin", "--yes"])).stdout).token;
  const tokenFile = `${repo.dir}/fixture-admin-token`;
  await Deno.writeTextFile(tokenFile, token, { mode: 0o600 });
  const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  const port = (listener.addr as Deno.NetAddr).port;
  listener.close();
  const child = await repo.spawn([
    "serve",
    "--host",
    "127.0.0.1",
    "--port",
    String(port),
    "--auth-mode",
    "token",
    "--admins",
    "user:fixture",
    "--no-schedule",
  ]);
  const output = child.output();
  let done = false;
  child.status.then(() => done = true);
  try {
    let ready = false;
    for (let i = 0; i < 100; i++) {
      try {
        const r = await fetch(`http://127.0.0.1:${port}/ready`);
        await r.body?.cancel();
        if (r.ok) {
          ready = true;
          break;
        }
      } catch { /* starting */ }
      assert(
        !done,
        new TextDecoder().decode((await Promise.race([output, Promise.resolve({ stderr: new Uint8Array() })])).stderr),
      );
      await new Promise((r) => setTimeout(r, 100));
    }
    assert(ready, "Scratch serve did not become ready");
  } catch (error) {
    if (!done) child.kill("SIGTERM");
    await output;
    throw error;
  }
  return {
    flags: ["--server", `ws://127.0.0.1:${port}`, "--token-file", tokenFile],
    async [Symbol.asyncDispose]() {
      if (!done) child.kill("SIGTERM");
      await output;
    },
  };
}
