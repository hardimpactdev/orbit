import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { userInfo } from "node:os";
import { LocalDeployError, localDeploySource } from "./deploy_local.ts";

async function git(directory: string, ...args: string[]) {
  const result = await new Deno.Command("/usr/bin/git", {
    args: ["-C", directory, ...args],
    env: {
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_AUTHOR_NAME: "fixture",
      GIT_AUTHOR_EMAIL: "fixture@example.test",
      GIT_COMMITTER_NAME: "fixture",
      GIT_COMMITTER_EMAIL: "fixture@example.test",
    },
    stdout: "piped",
    stderr: "piped",
  }).output();
  assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
  return new TextDecoder().decode(result.stdout).trim();
}
async function fixture() {
  const base = await Deno.makeTempDir(),
    repository = base + "/source",
    checkout = base + "/checkout";
  await Deno.mkdir(repository);
  await git(repository, "init", "--initial-branch=main");
  await Deno.writeTextFile(repository + "/app.txt", "one\n");
  await git(repository, "add", ".");
  await git(repository, "commit", "-m", "first");
  const first = await git(repository, "rev-parse", "HEAD");
  await git(repository, "clone", repository, checkout);
  const home = {
    home: checkout,
    branch: "main",
    repository,
    instance: 763,
    user: userInfo().username,
  };
  const local = localDeploySource({
    deadline: Date.now() + 60000,
    unprivileged: true,
  });
  return { base, repository, checkout, first, home, local };
}

Deno.test("native checkout fetches and fast-forwards a real repository without an agent", async () => {
  const f = await fixture();
  try {
    await Deno.writeTextFile(f.repository + "/app.txt", "two\n");
    await git(f.repository, "add", ".");
    await git(f.repository, "commit", "-m", "second");
    const commit = await git(f.repository, "rev-parse", "HEAD");
    assertEquals(
      await f.local.resolveDevelopment(f.home, {
        branch: "main",
        commit,
        protocols: ["file"],
        git_read: { github_token: null },
      }),
      { commit },
    );
    assertEquals(
      await f.local.checkoutDevelopment(f.home, { branch: "main", commit }),
      { commit },
    );
    assertEquals(await Deno.readTextFile(f.checkout + "/app.txt"), "two\n");
    assertEquals(await f.local.inspectDevelopment(f.home), { commit });
  } finally {
    await Deno.remove(f.base, { recursive: true });
  }
});

Deno.test("native checkout preserves dirty and divergent work and refuses origin changes", async () => {
  const f = await fixture();
  try {
    await Deno.writeTextFile(f.checkout + "/app.txt", "private local work\n");
    await assertRejects(
      () =>
        f.local.checkoutDevelopment(f.home, {
          branch: "main",
          commit: f.first,
        }),
      LocalDeployError,
      "deployment.checkout_dirty",
    );
    assertEquals(
      await Deno.readTextFile(f.checkout + "/app.txt"),
      "private local work\n",
    );
    await git(f.checkout, "add", ".");
    await git(f.checkout, "commit", "-m", "local");
    await assertRejects(
      () =>
        f.local.checkoutDevelopment(f.home, {
          branch: "main",
          commit: f.first,
        }),
      LocalDeployError,
      "deployment.branch_diverged",
    );
    await git(
      f.checkout,
      "remote",
      "set-url",
      "origin",
      f.repository + "/foreign",
    );
    await assertRejects(
      () => f.local.inspectDevelopment(f.home),
      LocalDeployError,
      "deployment.identity_changed",
    );
  } finally {
    await Deno.remove(f.base, { recursive: true });
  }
});

Deno.test("native source rejects linked checkouts and off-branch pins before checkout", async () => {
  const f = await fixture();
  try {
    await Deno.symlink(f.checkout, f.base + "/linked");
    await assertRejects(
      () => f.local.inspectDevelopment({ ...f.home, home: f.base + "/linked" }),
      LocalDeployError,
    );
    await git(f.repository, "checkout", "-b", "side");
    await Deno.writeTextFile(f.repository + "/side.txt", "side\n");
    await git(f.repository, "add", ".");
    await git(f.repository, "commit", "-m", "side");
    const other = await git(f.repository, "rev-parse", "HEAD");
    await assertRejects(
      () =>
        f.local.resolveDevelopment(f.home, {
          branch: "main",
          commit: other,
          protocols: ["file"],
          git_read: { github_token: null },
        }),
      LocalDeployError,
    );
    assertEquals(await git(f.checkout, "rev-parse", "HEAD"), f.first);
  } finally {
    await Deno.remove(f.base, { recursive: true });
  }
});

Deno.test("final native inspection refuses another branch or detached HEAD at the same commit", async () => {
  const f = await fixture();
  try {
    await git(f.checkout, "checkout", "-b", "side");
    await assertRejects(
      () => f.local.inspectDevelopment(f.home),
      LocalDeployError,
      "deployment.branch_changed",
    );
    await git(f.checkout, "checkout", "--detach", f.first);
    await assertRejects(
      () => f.local.inspectDevelopment(f.home),
      LocalDeployError,
      "deployment.branch_changed",
    );
    assertEquals(await git(f.checkout, "rev-parse", "HEAD"), f.first);
  } finally {
    await Deno.remove(f.base, { recursive: true });
  }
});
