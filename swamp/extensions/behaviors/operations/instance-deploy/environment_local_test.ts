import { assertEquals } from "jsr:@std/assert@1.0.19";
import { userInfo } from "node:os";
import { environmentProgram } from "./environment_local.ts";

async function access(base: string, extra: Record<string, unknown>) {
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-I", "-c", environmentProgram],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify({
    base,
    user: userInfo().username,
    maximum: 1048576,
    require_home: false,
    required_capacity: 0,
    file: ".env",
    managed_keys: [],
    ...extra,
  })));
  await writer.close();
  const reply = await child.output();
  assertEquals(reply.success, true, new TextDecoder().decode(reply.stderr));
  assertEquals(reply.stderr.length, 0);
  return JSON.parse(new TextDecoder().decode(reply.stdout));
}

Deno.test("local env atomically installs private content and preserves idempotent inode", async () => {
  const base = await Deno.makeTempDir();
  try {
    assertEquals(await access(base, { mode: "inspect" }), {
      preflight: { state: "ok", installed: false },
      existing: { state: "missing", installed: false },
    });
    const content = 'TOKEN="private-fixture"\n';
    assertEquals(await access(base, { mode: "write", content }), {
      state: "changed",
      installed: true,
    });
    const before = await Deno.stat(base + "/.env");
    assertEquals((before.mode ?? 0) & 0o777, 0o600);
    assertEquals(await Deno.readTextFile(base + "/.env"), content);
    assertEquals(await access(base, { mode: "write", content }), {
      state: "unchanged",
      installed: false,
    });
    assertEquals((await Deno.stat(base + "/.env")).ino, before.ino);
  } finally {
    await Deno.remove(base, { recursive: true });
  }
});

Deno.test("local env refuses symlinks, wrong home, excess content and insufficient capacity", async () => {
  const base = await Deno.makeTempDir();
  try {
    await Deno.writeTextFile(base + "/outside", "unchanged");
    await Deno.symlink(base + "/outside", base + "/.env");
    assertEquals(
      (await access(base, { mode: "write", content: "BAD=1\n" })).state,
      "refused",
    );
    assertEquals(await Deno.readTextFile(base + "/outside"), "unchanged");
    await Deno.remove(base + "/.env");
    for (
      const extra of [
        { require_home: true },
        { maximum: 3, content: "LONG=1\n" },
        { required_capacity: Number.MAX_SAFE_INTEGER },
      ]
    ) {
      assertEquals(
        (await access(base, { mode: "write", content: "A=1\n", ...extra }))
          .state,
        "refused",
      );
    }
    assertEquals([...Deno.readDirSync(base)].map((v) => v.name), ["outside"]);
  } finally {
    await Deno.remove(base, { recursive: true });
  }
});

Deno.test("local env testing merge retains unrelated lines and refuses edits to tracked files", async () => {
  const base = await Deno.makeTempDir();
  try {
    await Deno.writeTextFile(
      base + "/.env.testing",
      "# comment\nKEEP=local\nexport DB_DATABASE = old\nDB_DATABASE=duplicate\n",
    );
    await Deno.chmod(base + "/.env.testing", 0o600);
    const input = {
      mode: "merge",
      file: ".env.testing",
      managed_keys: ["DB_DATABASE"],
      content: 'DB_DATABASE="testing"\n',
    };
    assertEquals((await access(base, input)).state, "changed");
    assertEquals(
      await Deno.readTextFile(base + "/.env.testing"),
      '# comment\nKEEP=local\nDB_DATABASE="testing"\n',
    );
    assertEquals(
      (await new Deno.Command("/usr/bin/git", {
        args: ["init", base],
        stderr: "null",
      }).output()).success,
      true,
    );
    assertEquals(
      (await new Deno.Command("/usr/bin/git", {
        args: ["-C", base, "add", ".env.testing"],
      }).output()).success,
      true,
    );
    const tracked = await Deno.readTextFile(base + "/.env.testing");
    assertEquals(
      (await access(base, { ...input, content: "DB_DATABASE=other\n" })).state,
      "tracked",
    );
    assertEquals(await Deno.readTextFile(base + "/.env.testing"), tracked);
  } finally {
    await Deno.remove(base, { recursive: true });
  }
});
