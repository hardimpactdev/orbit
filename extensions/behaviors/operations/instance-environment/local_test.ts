import {
  assertEquals,
  assertFalse,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import { invokeLocalEnvironment } from "./local.ts";
import { EnvironmentRefusal, synchronizeEnvironment } from "./sync.ts";

const account = new TextDecoder().decode(
  (await new Deno.Command("/usr/bin/id", { args: ["-un"] }).output()).stdout,
).trim();
async function fixture(run: (base: string) => Promise<void>) {
  const base = await Deno.makeTempDir({ prefix: "orbit-env-native-" });
  try {
    await run(base);
  } finally {
    await Deno.remove(base, { recursive: true });
  }
}
const input = (base: string) => ({
  base,
  user: account,
  maximum: 1048576,
  required_capacity: 1048576,
  require_home: false,
});
async function write(
  base: string,
  content: string,
  extra: Record<string, unknown> = {},
) {
  return await invokeLocalEnvironment({
    name: "instance.write-environment",
    input: {
      ...input(base),
      mode: "write",
      file: ".env",
      managed_keys: [],
      content,
      ...extra,
    },
  });
}
async function git(base: string, args: string[]) {
  const output = await new Deno.Command("/usr/bin/git", {
    args: ["-C", base, ...args],
    stdout: "null",
    stderr: "null",
  }).output();
  assertEquals(output.success, true);
}

Deno.test("native environment atomically creates private owned file and recognizes unchanged bytes", async () => {
  await fixture(async (base) => {
    assertEquals(await write(base, "TOKEN=private\n"), {
      state: "changed",
      installed: true,
    });
    const before = await Deno.lstat(`${base}/.env`);
    assertEquals(before.mode! & 0o7777, 0o600);
    assertEquals(before.uid, (await Deno.lstat(base)).uid);
    assertEquals(await write(base, "TOKEN=private\n"), {
      state: "unchanged",
      installed: false,
    });
    assertEquals((await Deno.lstat(`${base}/.env`)).ino, before.ino);
    assertEquals(await write(base, "TOKEN=updated\n"), {
      state: "changed",
      installed: true,
    });
    assertEquals(await Deno.readTextFile(`${base}/.env`), "TOKEN=updated\n");
    assertFalse((await Deno.lstat(`${base}/.env`)).ino === before.ino);
    assertEquals(
      (await Array.fromAsync(Deno.readDir(base))).map((x) => x.name),
      [
        ".env",
      ],
    );
  });
});
Deno.test("native replacement repairs unsafe file mode even with unchanged content", async () => {
  await fixture(async (base) => {
    await Deno.writeTextFile(`${base}/.env`, "KEY=value\n", { mode: 0o644 });
    assertEquals((await write(base, "KEY=value\n")).state, "changed");
    assertEquals((await Deno.lstat(`${base}/.env`)).mode! & 0o7777, 0o600);
  });
});
Deno.test("native filesystem boundary refuses file and ancestor symlinks", async () => {
  await fixture(async (base) => {
    await Deno.writeTextFile(`${base}/outside`, "leave unchanged");
    await Deno.symlink(`${base}/outside`, `${base}/.env`);
    assertEquals((await write(base, "KEY=replaced\n")).installed, false);
    assertEquals(await Deno.readTextFile(`${base}/outside`), "leave unchanged");
    await Deno.mkdir(`${base}/real`);
    await Deno.symlink(`${base}/real`, `${base}/link`);
    assertFalse(["changed", "unchanged"].includes(
      String((await write(`${base}/link`, "KEY=no\n")).state),
    ));
    assertEquals(await Array.fromAsync(Deno.readDir(`${base}/real`)), []);
  });
});
Deno.test("native filesystem boundary refuses production home mismatch and oversized content", async () => {
  await fixture(async (base) => {
    assertEquals(
      (await write(base, "KEY=no\n", { require_home: true })).installed,
      false,
    );
    assertEquals((await write(base, "x".repeat(1048577))).state, "refused");
    assertEquals(await Array.fromAsync(Deno.readDir(base)), []);
  });
});
Deno.test("native testing merge replaces owned duplicates and preserves unmanaged lines", async () => {
  await fixture(async (base) => {
    await Deno.writeTextFile(
      `${base}/.env.testing`,
      "# keep\nOTHER=unchanged\n export DB_DATABASE = old\nDB_DATABASE=duplicate\nSTALE=drop\nTAIL=keep",
      { mode: 0o600 },
    );
    const result = await write(
      base,
      'DB_DATABASE="new"\nDB_USERNAME="worker"\n',
      {
        mode: "merge",
        file: ".env.testing",
        managed_keys: ["DB_DATABASE", "DB_USERNAME", "STALE"],
      },
    );
    assertEquals(result.state, "changed");
    assertEquals(
      await Deno.readTextFile(`${base}/.env.testing`),
      '# keep\nOTHER=unchanged\nDB_DATABASE="new"\nTAIL=keep\nDB_USERNAME="worker"\n',
    );
  });
});
Deno.test("native testing merge preserves tracked file without replacing its inode", async () => {
  await fixture(async (base) => {
    await git(base, ["init", "-q"]);
    await Deno.writeTextFile(`${base}/.env.testing`, "DB_DATABASE=tracked\n");
    await git(base, ["add", ".env.testing"]);
    const before = await Deno.lstat(`${base}/.env.testing`);
    assertEquals(
      await write(base, "DB_DATABASE=new\n", {
        mode: "merge",
        file: ".env.testing",
        managed_keys: ["DB_DATABASE"],
      }),
      { state: "tracked", installed: false },
    );
    assertEquals((await Deno.lstat(`${base}/.env.testing`)).ino, before.ino);
    assertEquals(
      await Deno.readTextFile(`${base}/.env.testing`),
      "DB_DATABASE=tracked\n",
    );
  });
});
Deno.test("native sync refuses loss of unmanaged workload keys before writing", async () => {
  await fixture(async (base) => {
    await Deno.writeTextFile(`${base}/.env`, "UNIMPORTED=keep\n");
    const plan = {
      instance_id: 31,
      node_id: 9,
      environment: "development",
      base,
      user: account,
      agent_sha256: "a".repeat(64),
      maximum: 1048576,
      required_capacity: 1048576,
      preserve_workload_keys: true,
      runtime: null,
    };
    await assertRejects(
      () =>
        synchronizeEnvironment(plan, (callback) =>
          callback({
            environment_material: {
              content: "TOKEN=synthetic-secret\n",
              keys: ["TOKEN"],
              owned_keys: [],
              testing: null,
            },
          })),
      EnvironmentRefusal,
    );
    assertEquals(await Deno.readTextFile(`${base}/.env`), "UNIMPORTED=keep\n");
    const result = await synchronizeEnvironment(
      { ...plan, preserve_workload_keys: false },
      (callback) =>
        callback({
          environment_material: {
            content: "TOKEN=synthetic-secret\n",
            keys: ["TOKEN"],
            owned_keys: [],
            testing: null,
          },
        }),
    );
    assertEquals(result.changed, true);
    assertFalse(JSON.stringify(result).includes("synthetic-secret"));
    assertEquals(
      await Deno.readTextFile(`${base}/.env`),
      "TOKEN=synthetic-secret\n",
    );
  });
});
