import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.14";
import { createHash } from "node:crypto";
import { userInfo } from "node:os";
import {
  cleanupSnapshot,
  CloneSource,
  exportSnapshot,
  type FileContext,
  receiveSnapshot,
  SeedSource,
  SeedTarget,
} from "./sqlite_actions.ts";

const run = "5f4c515c-6a11-45ba-bdc2-4262207c6917";
const user = userInfo().username;
function fixture() {
  const directory = "/tmp/orbit-sqlite-clone-" +
    crypto.randomUUID().replaceAll("-", "");
  const source = CloneSource.parse({
    variant: "clone",
    node_id: 12,
    transport_user: user,
    checkout: "/home/fixture",
    source: "/home/fixture/database.sqlite",
    directory,
  });
  const payload = new TextEncoder().encode(
    "SQLite format 3\0" + "private-snapshot-sentinel",
  );
  const digest = createHash("sha256").update(payload).digest("hex");
  return {
    source,
    payload,
    digest,
    async clean() {
      await Deno.remove(directory, { recursive: true }).catch(() => {});
    },
  };
}
Deno.test("native file export and receive preserve bytes without putting them in the typed reference", async () => {
  const f = fixture();
  const temporary = await Deno.makeTempDir();
  const saved = temporary + "/native";
  const incoming = temporary + "/.orbit-sqlite-" + "f".repeat(32);
  const ctx: FileContext = {
    modelId: run,
    modelType: { normalized: "fixture/sqlite" },
    createFileWriter: () => ({
      getFilePath: () => Promise.resolve(saved),
      finalize: () => Promise.resolve({ name: "snapshot-" + run, version: 1 }),
    }),
    dataRepository: {
      stream: async function* (_type, modelId, name, version) {
        assertEquals([modelId, name, version], [run, "snapshot-" + run, 1]);
        const file = await Deno.open(saved);
        yield* file.readable;
      },
    },
  };
  try {
    const ref = await exportSnapshot(
      f.source,
      ctx,
      "snapshot-" + run,
      async (call) => {
        assertEquals(call.action, "copy");
        if (call.input.operation === "cleanup") return {};
        await Deno.mkdir(f.source.directory, { mode: 0o700 });
        await Deno.writeFile(
          f.source.directory + "/snapshot.sqlite",
          f.payload,
          { mode: 0o600 },
        );
        return { bytes: f.payload.length, sha256: f.digest, mode: 0o644 };
      },
    );
    assertEquals(
      JSON.stringify(ref).includes("private-snapshot-sentinel"),
      false,
    );
    await receiveSnapshot(
      {
        transport_user: user,
        base: temporary,
        path: incoming,
      },
      ref,
      ctx,
    );
    assertEquals(await Deno.readFile(incoming), f.payload);
    assertEquals((await Deno.stat(incoming)).mode! & 0o777, 0o600);
  } finally {
    await f.clean();
    await Deno.remove(temporary, { recursive: true });
  }
});
Deno.test("export refuses a snapshot symlink, a foreign digest, and nonprivate mode without leaking diagnostics", async () => {
  for (const variant of ["symlink", "mode", "digest"]) {
    const f = fixture();
    const temp = await Deno.makeTempDir();
    const ctx: FileContext = {
      modelId: run,
      modelType: { normalized: "fixture/sqlite" },
      createFileWriter: () => ({
        getFilePath: () => Promise.resolve(temp + "/native"),
        finalize: () =>
          Promise.resolve({ name: "snapshot-" + run, version: 1 }),
      }),
      dataRepository: { stream: async function* () {} },
    };
    try {
      await assertRejects(
        () =>
          exportSnapshot(f.source, ctx, "snapshot-" + run, async (call) => {
            if (call.input.operation === "cleanup") return {};
            await Deno.mkdir(f.source.directory, { mode: 0o700 });
            const path = f.source.directory + "/snapshot.sqlite";
            if (variant === "symlink") {
              await Deno.writeFile(temp + "/db", f.payload, { mode: 0o600 });
              await Deno.symlink(temp + "/db", path);
            } else {await Deno.writeFile(path, f.payload, {
                mode: variant === "mode" ? 0o644 : 0o600,
              });}
            return {
              bytes: f.payload.length,
              sha256: variant === "digest" ? "b".repeat(64) : f.digest,
            };
          }),
        Error,
        "database.sqlite_export_failed",
      );
    } finally {
      await f.clean();
      await Deno.remove(temp, { recursive: true });
    }
  }
});
Deno.test("seed descriptors refuse path escapes and target state from another Instance", () => {
  const operation = "a".repeat(64);
  const source = {
    variant: "seed",
    node_id: 12,
    transport_user: user,
    target_instance_id: 2,
    base: "/home/source",
    source_path: "/home/source/../private",
    source_user: user,
    operation,
    state_dir: "/var/lib/orbit/app-instance-sqlite-seeds/source-1-target-2",
    snapshot_path: `/tmp/orbit-sqlite-${operation}.sqlite`,
  };
  assertEquals(SeedSource.safeParse(source).success, false);
  assertEquals(
    SeedTarget.safeParse({
      node_id: 17,
      transport_user: user,
      target_instance_id: 2,
      base: "/home/target",
      target_user: user,
      operation,
      state_dir: "/var/lib/orbit/app-instance-sqlite-seeds/target-3",
      environment: "development",
      relative_path: "database.sqlite",
    }).success,
    false,
  );
});
Deno.test("cleanup invokes only the admitted copy action and drops arbitrary exceptions", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      cleanupSnapshot(f.source, (call) => {
        assertEquals(call.action, "copy");
        assertEquals(call.input, {
          operation: "cleanup",
          directory: f.source.directory,
        });
        return Promise.reject(new Error("private-snapshot-sentinel"));
      }),
    Error,
    "database.sqlite_cleanup_failed",
  );
});

Deno.test("clone export replay cleans only its owned partial snapshot directory before a fresh export", async () => {
  const f = fixture();
  const temporary = await Deno.makeTempDir();
  await Deno.mkdir(f.source.directory, { mode: 0o700 });
  const partial = f.source.directory + "/.orbit-sqlite-" + "a".repeat(16);
  await Deno.writeFile(partial, new Uint8Array([1]), { mode: 0o600 });
  const calls: string[] = [];
  const ctx: FileContext = {
    modelId: run,
    modelType: { normalized: "fixture/sqlite" },
    createFileWriter: () => ({
      getFilePath: () => Promise.resolve(temporary + "/native"),
      finalize: () => Promise.resolve({ name: "snapshot-" + run, version: 1 }),
    }),
    dataRepository: { stream: async function* () {} },
  };
  try {
    await exportSnapshot(f.source, ctx, "snapshot-" + run, async (call) => {
      calls.push(String(call.input.operation));
      if (call.input.operation === "cleanup") {
        assertEquals(
          await Array.fromAsync(Deno.readDir(f.source.directory)),
          [],
        );
        await Deno.remove(f.source.directory);
        return {};
      }
      await Deno.mkdir(f.source.directory, { mode: 0o700 });
      await Deno.writeFile(f.source.directory + "/snapshot.sqlite", f.payload, {
        mode: 0o600,
      });
      return { bytes: f.payload.length, sha256: f.digest };
    });
    assertEquals(calls, ["cleanup", "export"]);
  } finally {
    await f.clean();
    await Deno.remove(temporary, { recursive: true });
  }
});
