import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
import {
  checkDestroyPublication,
  destroySystem,
  verifyDestroySource,
} from "./destroy_system.ts";

import { DestroyLocal } from "./destroy_contract.ts";
import {
  finalizeDestroySources,
  prepareDestroySources,
} from "./destroy_local.ts";
import { verifyDestroySourcesWithMaterial } from "./destroy_material.ts";
const text = new TextDecoder();
const encode = (s: Uint8Array) =>
  btoa(Array.from(s, (b) => String.fromCharCode(b)).join(""));
async function git(args: string[]) {
  const result = await new Deno.Command("/usr/bin/git", {
    args: [
      "-c",
      "core.hooksPath=/dev/null",
      "-c",
      "core.fsmonitor=false",
      ...args,
    ],
    env: {
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_AUTHOR_NAME: "Test",
      GIT_AUTHOR_EMAIL: "test@example.invalid",
      GIT_COMMITTER_NAME: "Test",
      GIT_COMMITTER_EMAIL: "test@example.invalid",
    },
    stdout: "piped",
    stderr: "piped",
  }).output();
  assert(result.success, "fixture Git failed");
  return { value: text.decode(result.stdout).trimEnd(), bytes: result.stdout };
}
async function fixture(worktree = false) {
  const root = await Deno.makeTempDir({ prefix: "destroy-system-" });
  const who = await new Deno.Command("/usr/bin/id", {
    args: ["-un"],
    stdout: "piped",
  }).output();
  const groupResult = await new Deno.Command("/usr/bin/id", {
    args: ["-gn"],
    stdout: "piped",
  }).output();
  const user = text.decode(who.stdout).trim(),
    group = text.decode(groupResult.stdout).trim();
  const grouping = root + "/project",
    common = grouping + "/main",
    remote = root + "/origin.git";
  await Deno.mkdir(grouping);
  await git(["init", "--bare", remote]);
  await git(["init", "-b", "main", common]);
  await Deno.writeTextFile(common + "/file", "one\n");
  await git(["-C", common, "add", "."]);
  await git(["-C", common, "commit", "-m", "first"]);
  await git(["-C", common, "remote", "add", "origin", remote]);
  await git(["-C", common, "push", "origin", "main"]);
  const checkout = worktree ? grouping + "/feature" : common;
  if (worktree) {
    await git(["-C", common, "worktree", "add", "-b", "feature", checkout]);
  }
  const info = await Deno.lstat(checkout);
  const source = {
    root,
    operation: "b6f47b6f-1d5f-47f0-8e87-539cba58f9c4",
    member: "775",
    digest: "a".repeat(64),
    user,
    group,
    checkout,
    common_repository: common,
    layout: worktree ? "worktree" : "checkout",
    source_identity: `${info.dev}:${info.ino}`,
  };
  const input = {
    ...source,
    receipt: "b".repeat(64),
    grouping_directory: grouping,
    branch: worktree ? "feature" : "main",
    force: false,
    origin: remote,
    worktrees: encode(
      (await git(["-C", checkout, "worktree", "list", "--porcelain", "-z"]))
        .bytes,
    ),
    commit: (await git(["-C", checkout, "rev-parse", "HEAD"])).value,
    unresolved: false,
    independent_worktree: worktree,
    own_units: [],
    git_read: { github_token: null },
  };
  const receipt = {
    ...source,
    receipt: input.receipt,
    grouping_directory: grouping,
  };
  const prefix = root + "/.orbit-removals/" + source.operation + "." +
    source.member;
  return {
    root,
    remote,
    common,
    checkout,
    source,
    input,
    receipt,
    prefix,
    dispose: () => Deno.remove(root, { recursive: true }),
  };
}
async function missing(path: string) {
  try {
    await Deno.lstat(path);
    return false;
  } catch (e) {
    if (e instanceof Deno.errors.NotFound) return true;
    throw e;
  }
}

Deno.test("direct system finalizer deletes only the captured clean published checkout and replays absence", async () => {
  const f = await fixture();
  try {
    assertEquals(
      await destroySystem("instance.prepare-source-removal", f.source),
      {},
    );
    assertEquals(
      await destroySystem("instance.read-source-removal-state", f.receipt),
      { state: "present" },
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { state: "finalized", receipt: f.input.receipt },
    );
    assert(await missing(f.checkout));
    assert(await missing(f.input.grouping_directory));
    assertEquals(
      await destroySystem("instance.read-source-removal-state", f.receipt),
      { state: "completed" },
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { state: "finalized", receipt: f.input.receipt },
    );
    assert(!(await missing(f.remote)));
  } finally {
    await f.dispose();
  }
});
Deno.test("pre-effect local publication check refuses a clean unpushed commit", async () => {
  const f = await fixture();
  try {
    await Deno.writeTextFile(f.checkout + "/file", "two\n");
    await git(["-C", f.checkout, "commit", "-am", "not published"]);
    const commit = (await git(["-C", f.checkout, "rev-parse", "HEAD"])).value;
    assertEquals(
      await checkDestroyPublication(f.remote, commit, { github_token: null }),
      false,
    );
    const input = {
      ...f.input,
      commit,
      worktrees: encode(
        (await git(["-C", f.checkout, "worktree", "list", "--porcelain", "-z"]))
          .bytes,
      ),
    };
    assertEquals(
      await destroySystem("instance.prepare-source-removal", f.source),
      {},
    );
    await assertRejects(
      () => verifyDestroySource(input),
      Error,
      "instance.unsafe_content",
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", input),
      { error_code: "instance.unsafe_content" },
    );
    assert(!(await missing(f.checkout)));
    assert(await missing(f.prefix + ".receipt"));
  } finally {
    await f.dispose();
  }
});
Deno.test("post-teardown content fence refuses new untracked files before deletion", async () => {
  const f = await fixture();
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    await Deno.writeTextFile(f.checkout + "/untracked", "keep me");
    await assertRejects(
      () => verifyDestroySource(f.input),
      Error,
      "instance.unsafe_content",
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { error_code: "instance.unsafe_content" },
    );
    assertEquals(await Deno.readTextFile(f.checkout + "/untracked"), "keep me");
  } finally {
    await f.dispose();
  }
});
Deno.test("force permits dirty source but still refuses replacement inode and different origin", async () => {
  const f = await fixture();
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    await git([
      "-C",
      f.checkout,
      "remote",
      "set-url",
      "origin",
      f.remote + "-other",
    ]);
    assertEquals(
      await destroySystem("instance.finalize-source-removal", {
        ...f.input,
        force: true,
      }),
      { error_code: "instance.removal_refused" },
    );
    await git(["-C", f.checkout, "remote", "set-url", "origin", f.remote]);
    await Deno.rename(f.checkout, f.checkout + "-saved");
    await Deno.mkdir(f.checkout);
    assertEquals(
      await destroySystem("instance.finalize-source-removal", {
        ...f.input,
        force: true,
      }),
      { error_code: "instance.removal_refused" },
    );
    assert(!(await missing(f.checkout + "-saved/.git")));
  } finally {
    await f.dispose();
  }
});
Deno.test("journal replay refuses another digest without overwriting the original", async () => {
  const f = await fixture();
  try {
    assertEquals(
      await destroySystem("instance.prepare-source-removal", f.source),
      {},
    );
    assertEquals(
      await destroySystem("instance.prepare-source-removal", {
        ...f.source,
        digest: "c".repeat(64),
      }),
      { error_code: "instance.removal_refused" },
    );
    assertEquals(
      await Deno.readTextFile(f.prefix + ".journal"),
      f.source.digest + "\n",
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("force on captured absent checkout remains idempotent and refuses a late directory", async () => {
  const f = await fixture();
  try {
    await Deno.remove(f.checkout, { recursive: true });
    const source = { ...f.source, source_identity: "absent" },
      input = { ...f.input, ...source, force: true };
    assertEquals(
      await destroySystem("instance.prepare-source-removal", source),
      {},
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", input),
      { state: "finalized", receipt: input.receipt },
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", input),
      { state: "finalized", receipt: input.receipt },
    );
    await Deno.mkdir(f.input.grouping_directory);
    await Deno.mkdir(f.checkout);
    assertEquals(
      await destroySystem("instance.finalize-source-removal", input),
      { error_code: "instance.removal_refused" },
    );
    assert(!(await missing(f.checkout)));
  } finally {
    await f.dispose();
  }
});
Deno.test("symlinked state and checkout paths cannot authorize removal outside the source root", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(f.root + "/outside");
    await Deno.symlink(f.root + "/outside", f.root + "/.orbit-removals");
    assertEquals(
      await destroySystem("instance.prepare-source-removal", f.source),
      { error_code: "instance.removal_refused" },
    );
    await Deno.remove(f.root + "/.orbit-removals");
    await Deno.rename(f.checkout, f.checkout + "-saved");
    await Deno.symlink(f.checkout + "-saved", f.checkout);
    assertEquals(
      await destroySystem("instance.prepare-source-removal", f.source),
      { error_code: "instance.removal_refused" },
    );
    assert(!(await missing(f.checkout + "-saved/.git")));
  } finally {
    await f.dispose();
  }
});
Deno.test("native worktree finalization preserves the common checkout, branch and unrelated sibling", async () => {
  const f = await fixture(true);
  try {
    const sibling = f.input.grouping_directory + "/sibling";
    await git(["-C", f.common, "worktree", "add", "-b", "sibling", sibling]);
    const input = {
      ...f.input,
      force: true,
      worktrees: encode(
        (await git(["-C", f.checkout, "worktree", "list", "--porcelain", "-z"]))
          .bytes,
      ),
    };
    assertEquals(
      await destroySystem("instance.prepare-source-removal", f.source),
      {},
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", input),
      { state: "finalized", receipt: f.input.receipt },
    );
    assert(await missing(f.checkout));
    assert(!(await missing(f.common + "/file")));
    assert(!(await missing(sibling + "/file")));
    assertEquals(
      (await git([
        "-C",
        f.common,
        "show-ref",
        "--verify",
        "refs/heads/feature",
      ])).value.endsWith("refs/heads/feature"),
      true,
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("local native inspection captures an actual inode and absent source without the agent", async () => {
  const f = await fixture();
  try {
    const input = {
      checkout: f.checkout,
      root: f.root,
      user: f.source.user,
      group: f.source.group,
      layout: "checkout",
      branch: "main",
      inspect_content: true,
      unresolved: false,
      allow_absent: false,
      origin: f.remote,
      prepare_id: "",
    };
    const result = await destroySystem("instance.inspect-source", input);
    assertEquals(
      atob(String(result.source_identity)),
      f.source.source_identity,
    );
    assertEquals(atob(String(result.commit)), f.input.commit);
    await Deno.remove(f.checkout, { recursive: true });
    const absent = await destroySystem("instance.inspect-source", {
      ...input,
      allow_absent: true,
    });
    assertEquals(atob(String(absent.source_identity)), "absent");
  } finally {
    await f.dispose();
  }
});
Deno.test("a crash after checkout quarantine resumes with the captured inode and inventory", async () => {
  const f = await fixture();
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    await Deno.rename(f.checkout, f.prefix + ".quarantine");
    assertEquals(
      await destroySystem("instance.read-source-removal-state", f.receipt),
      { state: "quarantined" },
    );
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { state: "finalized", receipt: f.input.receipt },
    );
    assert(await missing(f.prefix + ".quarantine"));
  } finally {
    await f.dispose();
  }
});
Deno.test("a crash after linked-worktree quarantine preserves unrelated administration", async () => {
  const f = await fixture(true);
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    await git([
      "-C",
      f.common,
      "worktree",
      "move",
      f.checkout,
      f.prefix + ".quarantine",
    ]);
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { state: "finalized", receipt: f.input.receipt },
    );
    assert(await missing(f.prefix + ".quarantine"));
    assert(!(await missing(f.common + "/file")));
  } finally {
    await f.dispose();
  }
});
Deno.test("receipt-backed cleanup survives interrupted recursive source deletion", async () => {
  const f = await fixture();
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    await Deno.rename(f.checkout, f.prefix + ".quarantine");
    await Deno.writeTextFile(f.prefix + ".receipt", f.input.receipt + "\n", {
      mode: 0o600,
    });
    await Deno.remove(f.prefix + ".quarantine/.git", { recursive: true });
    assertEquals(
      await destroySystem("instance.read-source-removal-state", f.receipt),
      { state: "receipt_pending_cleanup" },
    );
    assertEquals(
      await destroySystem("instance.clean-removal-receipt", f.receipt),
      { receipt: f.input.receipt },
    );
    assertEquals(
      await destroySystem("instance.clean-removal-receipt", f.receipt),
      { receipt: f.input.receipt },
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("source processes stop before the captured checkout leaves its path", async () => {
  const f = await fixture();
  const child = new Deno.Command("/usr/bin/sleep", {
    args: ["60"],
    cwd: f.checkout,
    stdin: "null",
    stdout: "null",
    stderr: "null",
  }).spawn();
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { state: "finalized", receipt: f.input.receipt },
    );
    const status = await child.status;
    assertEquals(status.signal, "SIGTERM");
    assert(await missing(f.checkout));
  } finally {
    try {
      child.kill("SIGKILL");
    } catch { /* stopped */ }
    await child.status;
    await f.dispose();
  }
});
Deno.test("receipt cleanup refuses a worktree Git pointer redirected to a sibling", async () => {
  const f = await fixture(true);
  try {
    const sibling = f.input.grouping_directory + "/sibling";
    await git(["-C", f.common, "worktree", "add", "-b", "sibling", sibling]);
    await destroySystem("instance.prepare-source-removal", f.source);
    await git([
      "-C",
      f.common,
      "worktree",
      "move",
      f.checkout,
      f.prefix + ".quarantine",
    ]);
    const admin = (await git([
      "-C",
      f.prefix + ".quarantine",
      "rev-parse",
      "--absolute-git-dir",
    ])).value;
    const siblingAdmin =
      (await git(["-C", sibling, "rev-parse", "--absolute-git-dir"])).value;
    const inode = async (path: string) => {
      const info = await Deno.lstat(path);
      return `${info.dev}:${info.ino}`;
    };
    const record = [
      btoa(admin),
      await inode(admin),
      await inode(f.common + "/.git"),
      await inode(f.common + "/.git/worktrees"),
    ].join("\n") + "\n";
    await Deno.writeTextFile(f.prefix + ".recovery", record, { mode: 0o600 });
    await Deno.writeTextFile(f.prefix + ".receipt", f.input.receipt + "\n", {
      mode: 0o600,
    });
    await Deno.writeTextFile(
      f.prefix + ".quarantine/.git",
      "gitdir: " + siblingAdmin + "\n",
    );
    assertEquals(
      await destroySystem("instance.clean-removal-receipt", f.receipt),
      { error_code: "instance.removal_refused" },
    );
    assert(!(await missing(siblingAdmin)));
    assert(!(await missing(f.prefix + ".quarantine")));
    assert(!(await missing(sibling + "/file")));
  } finally {
    await f.dispose();
  }
});
Deno.test("linked-worktree receipt cleanup resumes after partial administration and source deletion", async () => {
  const f = await fixture(true);
  try {
    const sibling = f.input.grouping_directory + "/sibling";
    await git(["-C", f.common, "worktree", "add", "-b", "sibling", sibling]);
    await destroySystem("instance.prepare-source-removal", f.source);
    await git([
      "-C",
      f.common,
      "worktree",
      "move",
      f.checkout,
      f.prefix + ".quarantine",
    ]);
    const admin = (await git([
      "-C",
      f.prefix + ".quarantine",
      "rev-parse",
      "--absolute-git-dir",
    ])).value;
    const inode = async (path: string) => {
      const info = await Deno.lstat(path);
      return `${info.dev}:${info.ino}`;
    };
    await Deno.writeTextFile(
      f.prefix + ".recovery",
      [
        btoa(admin),
        await inode(admin),
        await inode(f.common + "/.git"),
        await inode(f.common + "/.git/worktrees"),
      ].join("\n") + "\n",
      { mode: 0o600 },
    );
    await Deno.writeTextFile(f.prefix + ".receipt", f.input.receipt + "\n", {
      mode: 0o600,
    });
    // Recursive removal may be interrupted after either of these pointers goes.
    await Deno.remove(admin + "/gitdir");
    await Deno.remove(f.prefix + ".quarantine/.git");
    assertEquals(
      await destroySystem("instance.clean-removal-receipt", f.receipt),
      { receipt: f.input.receipt },
    );
    assertEquals(
      await destroySystem("instance.read-source-removal-state", f.receipt),
      { state: "completed" },
    );
    assertEquals(
      await destroySystem("instance.clean-removal-receipt", f.receipt),
      { receipt: f.input.receipt },
    );
    assert(await missing(admin));
    assert(await missing(f.prefix + ".quarantine"));
    assert(!(await missing(sibling + "/file")));
    assert(!(await missing(f.common + "/file")));
  } finally {
    await f.dispose();
  }
});
Deno.test("receipt-backed cleanup refuses a replacement quarantine directory", async () => {
  const f = await fixture();
  try {
    await destroySystem("instance.prepare-source-removal", f.source);
    await Deno.rename(f.checkout, f.prefix + ".quarantine-original");
    await Deno.mkdir(f.prefix + ".quarantine");
    await Deno.writeTextFile(f.prefix + ".quarantine/keep", "new source");
    await Deno.writeTextFile(f.prefix + ".receipt", f.input.receipt + "\n", {
      mode: 0o600,
    });
    assertEquals(
      await destroySystem("instance.clean-removal-receipt", f.receipt),
      { error_code: "instance.removal_refused" },
    );
    assertEquals(
      await Deno.readTextFile(f.prefix + ".quarantine/keep"),
      "new source",
    );
    assert(!(await missing(f.prefix + ".quarantine-original/.git")));
  } finally {
    await f.dispose();
  }
});

Deno.test("a new removal root verifies the remaining checkout after an admitted worktree already finalized", async () => {
  const f = await fixture(true);
  try {
    const mainInfo = await Deno.lstat(f.common);
    const mainSource = {
      ...f.source,
      member: "1001",
      checkout: f.common,
      layout: "checkout",
      source_identity: `${mainInfo.dev}:${mainInfo.ino}`,
    };
    const member = (instance_id: number, source: typeof f.source) => ({
      instance_id,
      source,
      branch: source.layout === "checkout" ? "main" : "feature",
      receipt: instance_id === 1 ? "c".repeat(64) : f.input.receipt,
      force: false,
      origin: f.remote,
      worktrees: f.input.worktrees,
      commit: f.input.commit,
      grouping_directory: f.input.grouping_directory,
      unresolved: false,
      independent_worktree: false,
      own_units: [],
    });
    const local = DestroyLocal.parse({
      node_id: 9,
      members: [member(1, mainSource), member(2, f.source)],
      runtime: { processes: [], schedules: [] },
      database: {
        owner_instance_id: 1,
        intent_id: 4321,
        connection_ids: [],
        servers: [],
        sqlite: [],
      },
    });
    await prepareDestroySources(local);
    assertEquals(
      await destroySystem("instance.finalize-source-removal", f.input),
      { state: "finalized", receipt: f.input.receipt },
    );
    await verifyDestroySourcesWithMaterial(local, {
      withPrivateMaterial: (use) => use({ git_read: { github_token: null } }),
    });
    await finalizeDestroySources(local, { github_token: null });
    assert(await missing(f.checkout));
    assert(await missing(f.common));
  } finally {
    await f.dispose();
  }
});
