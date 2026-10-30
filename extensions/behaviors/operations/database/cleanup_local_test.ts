import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { cleanup } from "./cleanup.ts";
import { cleanupAsOwner } from "./cleanup_local.ts";

async function fixture(
  run: (input: Record<string, unknown>, directory: string) => Promise<void>,
) {
  const directory = `/tmp/orbit-sqlite-clone-${
    crypto.randomUUID().replaceAll("-", "")
  }`;
  const input = { variant: "clone", agent_sha256: "a".repeat(64), directory };
  await Deno.mkdir(directory, { mode: 0o700 });
  try {
    await run(input, directory);
  } finally {
    await Deno.remove(directory, { recursive: true }).catch((error) => {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    });
  }
}

Deno.test("workflow deletes its worker-owned private snapshot and converges when absent", async () => {
  await fixture(async (input, directory) => {
    await Deno.writeTextFile(
      directory + "/snapshot.sqlite",
      "disposable snapshot",
    );
    const operation = {
      id: 17,
      node_id: 209,
      kind: "database.sqlite_cleanup",
      status: "queued",
      result: null as unknown,
      cancel_requested: false,
      input,
    };
    const http: Http = (_method, path, body) => {
      if (path.endsWith("/result")) {
        const value = body as Record<string, unknown>;
        operation.status = String(value.status);
        operation.result = value.result;
      }
      return Promise.resolve({ status: 200, body: { data: operation } });
    };
    const result = await cleanup(() => http).methods.cleanup.execute({
      operation_id: 17,
      node_id: 209,
      kind: "database.sqlite_cleanup",
      run_id: crypto.randomUUID(),
    }, {
      globalArgs: {
        gatewayUrl: "https://gateway.orbit",
        caFile: "/test/ca.pem",
      },
    });
    assertEquals(result, { status: "succeeded" });
    assertEquals(operation.result, { backend: "absent" });
    assertEquals(await cleanupAsOwner(input), { backend: "absent" });
    let absent = false;
    try {
      await Deno.lstat(directory);
    } catch (error) {
      absent = error instanceof Deno.errors.NotFound;
    }
    assertEquals(absent, true);
  });
});

Deno.test("owner cleanup refuses public directories, symlinks and unrelated contents", async () => {
  await fixture(async (input, directory) => {
    await Deno.chmod(directory, 0o755);
    assertEquals(await cleanupAsOwner(input), {
      error_code: "database.cleanup_owner_unavailable",
    });
    await Deno.chmod(directory, 0o700);
    await Deno.writeTextFile(directory + "/keep", "keep");
    await Deno.symlink(directory + "/keep", directory + "/snapshot.sqlite");
    assertEquals(await cleanupAsOwner(input), {
      error_code: "database.sqlite_cleanup_refused",
    });
    assertEquals(await Deno.readTextFile(directory + "/keep"), "keep");
    await Deno.remove(directory + "/snapshot.sqlite");
    assertEquals(await cleanupAsOwner(input), {
      error_code: "database.sqlite_cleanup_failed",
    });
    assertEquals(await Deno.readTextFile(directory + "/keep"), "keep");
  });
  await fixture(async (input, directory) => {
    await Deno.remove(directory);
    await Deno.symlink("/tmp", directory);
    assertEquals(await cleanupAsOwner(input), {
      error_code: "database.cleanup_owner_unavailable",
    });
  });
});
