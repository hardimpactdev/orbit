import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "preflight names every unpushed local branch with its count against all remote-tracking refs",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const pushed = git(f.checkout, "rev-parse", "HEAD");
      // None of these branches needs an upstream. Remote-only commits count as pushed on any remote.
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "remote-only");
      git(f.checkout, "update-ref", "refs/remotes/second/shared", "HEAD");
      git(f.checkout, "branch", "remote-only");
      git(f.checkout, "checkout", "--quiet", "-b", "spare", pushed);
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "one unpushed commit");
      git(f.checkout, "branch", "another");
      git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "two unpushed commits");
      git(f.checkout, "checkout", "--quiet", "main");
      const refs = git(f.checkout, "show-ref");
      const status = git(f.checkout, "status", "--porcelain");
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_unpushed_commits");
      assert(receipt.error_message.includes(f.checkout));
      assert(receipt.error_message.includes('"branch": "another", "commits": 1'), receipt.error_message);
      assert(receipt.error_message.includes('"branch": "spare", "commits": 2'), receipt.error_message);
      assert(!receipt.error_message.includes('"branch": "main"'));
      assert(!receipt.error_message.includes('"branch": "remote-only"'));
      assertEquals(receipt.preflight_refused, true);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
      assert(f.run("restore").ok);
      assertEquals(git(f.checkout, "show-ref"), refs);
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
    } finally {
      f.cleanup();
    }
  },
});
