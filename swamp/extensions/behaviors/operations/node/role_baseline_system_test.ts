import {
  assertEquals,
  assertRejects,
  assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
  prepareHibernationDirectories,
  prepareStorageRoot,
  readRoleAccount,
} from "./role_baseline_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

/** Tests run only the fixed Python filesystem program, as the current unprivileged account. */
const fixtureTransport: SystemTransport = (command, signal) => {
  if (command.command === "/usr/bin/sudo") {
    assertEquals(command.args, ["-n", "/usr/bin/python3", "-"]);
    return localSystemCommand({
      ...command,
      command: "/usr/bin/python3",
      args: ["-"],
    }, signal);
  }
  return localSystemCommand(command, signal);
};
async function account() {
  const result = await localSystemCommand({
    command: "/usr/bin/id",
    args: ["-un"],
    timeoutMs: 10000,
  });
  if (!result.success) throw new Error("Fixture account lookup failed");
  const owner = await readRoleAccount(result.stdout.trim(), fixtureTransport);
  if (owner === null) throw new Error("Fixture account is missing");
  return owner;
}
async function scratch(run: (directory: string) => Promise<void>) {
  const directory = await Deno.realPath(
    await Deno.makeTempDir({ prefix: "orbit-role-baseline-" }),
  );
  try {
    await run(directory);
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
}
Deno.test("storage creates owned real components once and inspects without modifying an existing root", async () => {
  const owner = await account();
  await scratch(async (directory) => {
    const input = {
      root: `${directory}/apps/one`,
      user: owner.user,
      group: owner.group,
      create_missing: false,
    };
    assertEquals(await prepareStorageRoot(input, fixtureTransport), {
      ok: false,
      error_code: "node.storage_root_unusable",
    });
    assertEquals(
      await prepareStorageRoot(
        { ...input, create_missing: true },
        fixtureTransport,
      ),
      { ok: true },
    );
    const first = await Deno.stat(input.root);
    assertEquals((first.mode ?? 0) & 0o777, 0o755);
    assertEquals(first.uid, Deno.uid());
    assertEquals(await prepareStorageRoot(input, fixtureTransport), {
      ok: true,
    });
    const second = await Deno.stat(input.root);
    assertEquals(second.ino, first.ino);
    assertEquals(second.mode, first.mode);
  });
});
Deno.test("storage refuses symlinked components and writable roots without changing their targets", async () => {
  const owner = await account();
  await scratch(async (directory) => {
    await Deno.mkdir(`${directory}/real`, { mode: 0o700 });
    await Deno.symlink(`${directory}/real`, `${directory}/linked`);
    const input = {
      root: `${directory}/linked/apps`,
      user: owner.user,
      group: owner.group,
      create_missing: true,
    };
    assertEquals(await prepareStorageRoot(input, fixtureTransport), {
      ok: false,
      error_code: "node.storage_root_unusable",
    });
    await assertRejects(
      () => Deno.stat(`${directory}/real/apps`),
      Deno.errors.NotFound,
    );
    await Deno.chmod(`${directory}/real`, 0o775);
    assertEquals(
      await prepareStorageRoot(
        { ...input, root: `${directory}/real` },
        fixtureTransport,
      ),
      { ok: false, error_code: "node.storage_root_unusable" },
    );
    assertEquals((await Deno.stat(`${directory}/real`)).mode! & 0o777, 0o775);
    assertThrows(() =>
      prepareStorageRoot({ ...input, root: "relative/apps" }, fixtureTransport)
    );
    assertThrows(() =>
      prepareStorageRoot(
        { ...input, root: `${directory}/a/../b` },
        fixtureTransport,
      )
    );
  });
});
Deno.test("storage refuses missing accounts without host mutation", async () => {
  await scratch(async (directory) => {
    const result = await prepareStorageRoot({
      root: `${directory}/missing`,
      user: "orbit_test_missing_account",
      group: "orbit_test_missing_account",
      create_missing: true,
    }, fixtureTransport);
    assertEquals(result, {
      ok: false,
      error_code: "node.storage_root_unusable",
    });
    await assertRejects(
      () => Deno.stat(`${directory}/missing`),
      Deno.errors.NotFound,
    );
  });
});
Deno.test("hibernation validates both trees, repairs only owned modes, and preserves protected roots", async () => {
  const self = [Deno.uid(), Deno.gid()];
  await scratch(async (directory) => {
    const parent = directory.slice(0, directory.lastIndexOf("/"));
    const before = await Deno.stat(parent);
    const transport: SystemTransport = (command, signal) => {
      if (command.stdin?.includes("grp.getgrnam('caddy')")) {
        return Promise.resolve({ success: true, stdout: JSON.stringify(self) });
      }
      // Sandbox the real filesystem action at this fixture's disposable root.
      // The production protected-root policy remains unchanged.
      const boundary = `PROTECTED.add(${JSON.stringify(parent)})
fixture_chmod = os.chmod
def owned_chmod(path, *args, **kwargs):
    if os.path.commonpath([${JSON.stringify(directory)}, path]) != ${JSON.stringify(directory)}:
        raise AssertionError('Fixture chmod escaped its scratch directory')
    return fixture_chmod(path, *args, **kwargs)
os.chmod = owned_chmod
`;
      return fixtureTransport({
        ...command,
        stdin: command.stdin?.replace("def main(data):", boundary + "def main(data):"),
      }, signal);
    };
    const input = {
      marker_directory: `${directory}/markers/hibernation`,
      log_directory: `${directory}/logs/hibernation`,
    };
    for (let attempt = 0; attempt < 2; attempt++) {
      assertEquals(await prepareHibernationDirectories(input, transport), {
        ok: true,
      });
      for (
        const [path, mode] of [[input.marker_directory, 0o755], [
          input.log_directory,
          0o2775,
        ]] as const
      ) {
        const metadata = await Deno.stat(path);
        assertEquals(metadata.mode! & 0o7777, mode);
        assertEquals([metadata.uid, metadata.gid], self);
      }
    }
    const after = await Deno.stat(parent);
    assertEquals([after.mode, after.uid, after.gid], [before.mode, before.uid, before.gid]);
  });
});
Deno.test("hibernation refuses an invalid second tree and an unavailable Caddy group before creating the first", async () => {
  await scratch(async (directory) => {
    await Deno.mkdir(`${directory}/real`, { mode: 0o700 });
    await Deno.symlink(`${directory}/real`, `${directory}/link`);
    const input = {
      marker_directory: `${directory}/markers/hibernation`,
      log_directory: `${directory}/link/logs`,
    };
    const transport: SystemTransport = (command, signal) =>
      command.stdin?.includes("grp.getgrnam('caddy')")
        ? Promise.resolve({
          success: true,
          stdout: JSON.stringify([Deno.uid(), Deno.gid()]),
        })
        : fixtureTransport(command, signal);
    assertEquals(await prepareHibernationDirectories(input, transport), {
      ok: false,
      error_code: "hibernation.node_failed",
    });
    await assertRejects(
      () => Deno.stat(`${directory}/markers`),
      Deno.errors.NotFound,
    );
    assertEquals((await Deno.stat(`${directory}/real`)).mode! & 0o777, 0o700);
    let calls = 0;
    assertEquals(
      await prepareHibernationDirectories({
        ...input,
        log_directory: `${directory}/logs`,
      }, () => {
        calls++;
        return Promise.resolve({ success: true, stdout: "null" });
      }),
      { ok: false, error_code: "hibernation.node_failed" },
    );
    assertEquals(calls, 1);
  });
});
Deno.test("filesystem failure returns only the safe code and uncertain output stays bounded", async () => {
  const owner = await account();
  const input = {
    root: "/nonexistent/orbit-fixture",
    user: owner.user,
    group: owner.group,
    create_missing: true,
  };
  const privateText = "synthetic-never-echo-diagnostic";
  assertEquals(
    await prepareStorageRoot(
      input,
      () => Promise.resolve({ success: false, stdout: privateText }),
    ),
    { ok: false, error_code: "node.storage_root_unusable" },
  );
  await assertRejects(
    () =>
      prepareStorageRoot(
        input,
        () => Promise.resolve({ success: true, stdout: privateText }),
      ),
    Error,
    "The system action returned an invalid result",
  );
});
