import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
import { registerCheckout } from "./register_root.ts";
import type { NodeCall } from "../_lib/node-agent.ts";
const input = {
  instance_id: 3,
  project_id: 2,
  checkout: "/srv/apps/proof/feature",
  application: "/srv/apps/proof/feature",
  repository_identity: "github.com/example/proof",
  user: "orbit",
};
const source = {
  path: input.checkout,
  layout: "checkout",
  repository_url: "https://github.com/example/proof.git",
  branch: "feature",
  detached: false,
  commit: "a".repeat(40),
  source_digest: "b".repeat(64),
  default_branch: "main",
  inferred_slug: "proof",
  common_repository_path: `${input.checkout}/.git`,
  worktree_paths: [input.checkout],
};
function fixture(change: Record<string, unknown> = {}, refusal?: string) {
  const calls: NodeCall[] = [];
  return {
    calls,
    invoke: (call: NodeCall) => {
      calls.push(call);
      if (call.name === "account.lookup") {
        return Promise.resolve({
          user: "orbit",
          group: "apps",
        });
      }
      if (call.name === "instance.inspect-registration-source") {
        return Promise
          .resolve({ rows: [{ ...source, ...change }] });
      }
      return Promise.resolve(
        refusal ? { error_code: refusal } : { identities: [] },
      );
    },
  };
}
Deno.test("registration inspects and adopts only its admitted local checkout before returning safe source metadata", async () => {
  const f = fixture();
  assertEquals(await registerCheckout(input, f.invoke), {
    registered: true,
    branch: source.branch,
    commit: source.commit,
    detached: false,
    digest: source.source_digest,
    common: source.common_repository_path,
    default_branch: "main",
    inferred_slug: "proof",
  });
  assertEquals(f.calls.map((v) => [v.name, v.sudo, v.mutates]), [
    ["account.lookup", false, false],
    ["instance.inspect-registration-source", false, false],
    ["instance.relocate-registration-source", false, true],
    ["instance.relocate-registration-source", false, true],
  ]);
  assertEquals(f.calls.slice(2).map((v) => v.input.operation), [
    "adopt",
    "adopt-finalize",
  ]);
  const member = (f.calls[2].input.members as Record<string, unknown>[])[0];
  assertEquals(member.source, member.destination);
  assertEquals(member.digest, source.source_digest);
});
for (
  const [name, change, code] of [
    ["foreign repository", {
      repository_url: "https://github.com/other/repo.git",
    }, "instance.source_identity_invalid"],
    [
      "foreign checkout",
      { path: "/srv/apps/other/feature" },
      "instance.registration_conflict",
    ],
    ["worktree", { layout: "worktree" }, "instance.registration_conflict"],
    ["additional worktrees", {
      worktree_paths: [input.checkout, "/srv/apps/proof/other"],
    }, "instance.registration_conflict"],
  ] as const
) {
  Deno.test(`registration refuses ${name} before adoption`, async () => {
    const f = fixture(change);
    assertEquals(await registerCheckout(input, f.invoke), { error_code: code });
    assertEquals(f.calls.length, 2);
  });
}
Deno.test("registration stops on adoption refusal without reporting completion or running finalize", async () => {
  const f = fixture({}, "instance.registration_invalid");
  assertEquals(await registerCheckout(input, f.invoke), {
    error_code: "instance.registration_invalid",
  });
  assertEquals(f.calls.length, 3);
});
Deno.test("registration rejects malformed source output before mutation", async () => {
  const f = fixture({ source_digest: "not-a-digest" });
  await assertRejects(() => registerCheckout(input, f.invoke));
  assertEquals(f.calls.length, 2);
});

Deno.test("registration adopts real dirty source in place and keeps private bytes out of its result", async () => {
  const root = await Deno.makeTempDir({ prefix: "orbit-register-" });
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
    await git(
      "remote",
      "add",
      "origin",
      "https://github.com/example/proof.git",
    );
    await Deno.writeTextFile(`${checkout}/README.md`, "local edits\n");
    await Deno.writeTextFile(
      `${checkout}/.env`,
      "PRIVATE_REGISTER_FIXTURE=retain-me\n",
      { mode: 0o644 },
    );
    const commit = await git("rev-parse", "HEAD");
    const result = await registerCheckout({
      ...input,
      checkout,
      application: checkout,
      user,
    });
    assert("registered" in result);
    assertEquals(result.registered, true);
    assertEquals(result.commit, commit);
    assertEquals(result.branch, "feature");
    assertEquals(JSON.stringify(result).includes("retain-me"), false);
    assertEquals(
      await Deno.readTextFile(`${checkout}/.env`),
      "PRIVATE_REGISTER_FIXTURE=retain-me\n",
    );
    assertEquals((await Deno.stat(`${checkout}/.env`)).mode! & 0o007, 0);
    assertEquals(
      await Deno.readTextFile(`${checkout}/README.md`),
      "local edits\n",
    );
    assertEquals(await git("rev-parse", "HEAD"), commit);
    const alias = `${root}/alias`;
    await Deno.symlink(checkout, alias);
    assertEquals(
      await registerCheckout({
        ...input,
        checkout: alias,
        application: alias,
        user,
      }),
      { error_code: "instance.source_invalid" },
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
