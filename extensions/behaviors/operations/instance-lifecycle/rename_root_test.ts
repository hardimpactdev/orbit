import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { inspectRename } from "./rename_root.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
const input = {
  instance_id: 1,
  project_id: 1,
  branch: "feature",
  checkout: "/srv/apps/proof/feature",
  source_root: "/srv/apps",
  repository: "https://github.com/example/proof.git",
  repository_identity: "github.com/example/proof",
  user: "orbit",
  prepare_id: null,
  seed_repository: null,
};
Deno.test("rename verifies the checked-out branch through fixed local account and source reads", async () => {
  const calls: NodeCall[] = [];
  const result = await inspectRename(input, (call) => {
    calls.push(call);
    return Promise.resolve(
      call.name === "account.lookup"
        ? { user: "orbit", group: "apps" }
        : { origin: "git@github.com:example/proof.git", branch: "feature" },
    );
  });
  assertEquals(result, { branch: "feature" });
  assertEquals(calls.map((v) => [v.name, v.sudo, v.mutates, v.pin]), [[
    "account.lookup",
    false,
    false,
    "",
  ], [
    "instance.inspect-development-source",
    false,
    false,
    "",
  ]]);
  assertEquals(calls[1].input.group, "apps");
  assertEquals(calls[1].input.require_idle, true);
});
for (
  const [name, origin, branch, code] of [
    [
      "foreign repository",
      "https://github.com/other/proof.git",
      "feature",
      "instance.source_identity_invalid",
    ],
    [
      "unchecked branch",
      input.repository,
      "main",
      "instance.branch_not_checked_out",
    ],
    [
      "credentialed origin",
      "https://secret@github.com/example/proof.git",
      "feature",
      "instance.source_identity_invalid",
    ],
  ] as const
) {
  Deno.test(`rename refuses ${name}`, async () => {
    assertEquals(
      await inspectRename(input, (call) =>
        Promise.resolve(
          call.name === "account.lookup"
            ? { user: "orbit", group: "orbit" }
            : { origin, branch },
        )),
      { error_code: code },
    );
  });
}
Deno.test("rename preserves bounded source refusal and never inspects after account refusal", async () => {
  const calls: string[] = [];
  assertEquals(
    await inspectRename(input, (call) => {
      calls.push(call.name);
      return Promise.resolve({
        error_code: "account.missing",
        secret: "unreturned",
      });
    }),
    { error_code: "account.missing" },
  );
  assertEquals(calls, ["account.lookup"]);
});
Deno.test("rename refuses invalid local account identity", async () => {
  await assertRejects(() =>
    inspectRename(input, () => Promise.resolve({ user: "root", group: "root" }))
  );
});

Deno.test("rename reads a dirty local checkout without changing Git HEAD or private files", async () => {
  const root = await Deno.makeTempDir({ prefix: "orbit-rename-" });
  const checkout = `${root}/apps/proof/feature`;
  const command = async (program: string, args: string[]) => {
    const result = await new Deno.Command(program, {
      args,
      stdout: "piped",
      stderr: "piped",
    }).output();
    if (!result.success) throw new Error(`Fixture command failed: ${program}`);
    return new TextDecoder().decode(result.stdout).trimEnd();
  };
  try {
    const user = await command("/usr/bin/id", ["-un"]);
    await Deno.mkdir(checkout, { recursive: true });
    const git = (...args: string[]) =>
      command("/usr/bin/git", [
        "-c",
        "core.hooksPath=/dev/null",
        "-C",
        checkout,
        ...args,
      ]);
    await git("init", "--quiet", "--initial-branch=feature");
    await git("config", "user.name", "Orbit fixture");
    await git("config", "user.email", "fixture@example.invalid");
    await Deno.writeTextFile(`${checkout}/README.md`, "original\n");
    await git("add", "README.md");
    await git("commit", "--quiet", "-m", "fixture");
    await git("remote", "add", "origin", input.repository);
    await Deno.writeTextFile(`${checkout}/README.md`, "local edits\n");
    await Deno.writeTextFile(
      `${checkout}/.env`,
      "PRIVATE_RENAME_FIXTURE=retain-me\n",
    );
    const before = await git("rev-parse", "HEAD");
    const local = { ...input, checkout, source_root: `${root}/apps`, user };

    assertEquals(await inspectRename(local), { branch: "feature" });
    assertEquals(await git("rev-parse", "HEAD"), before);
    assertEquals(
      await Deno.readTextFile(`${checkout}/README.md`),
      "local edits\n",
    );
    assertEquals(
      await Deno.readTextFile(`${checkout}/.env`),
      "PRIVATE_RENAME_FIXTURE=retain-me\n",
    );
    assertEquals(await inspectRename({ ...local, branch: "other" }), {
      error_code: "instance.branch_not_checked_out",
    });
    assertEquals(
      await inspectRename({ ...local, source_root: `${root}/elsewhere` }),
      { error_code: "instance.source_invalid" },
    );
    const alias = `${root}/alias`;
    await Deno.symlink(checkout, alias);
    assertEquals(
      await inspectRename({ ...local, checkout: alias, source_root: root }),
      { error_code: "instance.source_invalid" },
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
