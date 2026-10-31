import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { userInfo } from "node:os";
import {
  assertTransferDestination,
  inspectTransferPath,
  removeTransferPath,
} from "./transfer_paths.ts";
async function git(path: string, ...args: string[]) {
  const reply = await new Deno.Command("/usr/bin/git", {
    args: ["-C", path, ...args],
    stdout: "null",
    stderr: "null",
  }).output();
  if (!reply.success) throw new Error("fixture Git failed");
}
async function fixture() {
  const root = await Deno.makeTempDir();
  const source = root + "/source";
  await Deno.mkdir(source);
  await git(source, "init", "--quiet");
  await git(
    source,
    "-c",
    "user.name=Fixture",
    "-c",
    "user.email=f@example.invalid",
    "commit",
    "--allow-empty",
    "--quiet",
    "-m",
    "fixture",
  );
  return {
    root,
    source,
    user: userInfo().username,
    clean: () => Deno.remove(root, { recursive: true }),
  };
}
Deno.test("native transfer cleanup refuses a replaced path and preserves the replacement", async () => {
  const f = await fixture();
  try {
    const proof = await inspectTransferPath(f.source, f.user);
    await Deno.rename(f.source, f.root + "/original");
    await Deno.mkdir(f.source);
    await assertRejects(() => removeTransferPath(proof));
    assertEquals((await Deno.stat(f.source)).isDirectory, true);
  } finally {
    await f.clean();
  }
});
Deno.test("native transfer worktree cleanup preserves sibling and common repository", async () => {
  const f = await fixture();
  try {
    const worktree = f.root + "/feature";
    await git(
      f.source,
      "worktree",
      "add",
      "--quiet",
      "-b",
      "feature",
      worktree,
    );
    await Deno.writeTextFile(worktree + "/private", "retained in archive");
    await assertRejects(
      () => inspectTransferPath(f.source, f.user),
      Error,
      "instance.transfer_shared_checkout",
    );
    const proof = await inspectTransferPath(worktree, f.user);
    await removeTransferPath(proof);
    assertEquals((await Deno.stat(f.source + "/.git")).isDirectory, true);
    assertEquals(await removeTransferPath(proof), { removed: true });
  } finally {
    await f.clean();
  }
});
Deno.test("destination preflight refuses occupied and symlink parents before capture", async () => {
  const f = await fixture();
  try {
    await assertRejects(
      () => assertTransferDestination(f.source, f.root, f.user),
      Error,
      "instance.transfer_destination_exists",
    );
    await Deno.symlink(f.source, f.root + "/link");
    await assertRejects(
      () => assertTransferDestination(f.root + "/link/target", f.root, f.user),
      Error,
      "instance.transfer_destination_invalid",
    );
    assertEquals(
      await assertTransferDestination(f.root + "/new/target", f.root, f.user),
      { ready: true },
    );
  } finally {
    await f.clean();
  }
});
