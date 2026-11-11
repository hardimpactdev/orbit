import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "preflight names linked and detached worktrees and every tracked or untracked change before any Node change",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const linked = `${f.apps}/linked with spaces`;
      const detached = `${f.apps}/detached`;
      git(f.checkout, "worktree", "add", "--quiet", "-b", "extra", linked);
      git(f.checkout, "worktree", "add", "--quiet", "--detach", detached);
      Deno.writeTextFileSync(`${f.checkout}/index.php`, "changed\n");
      Deno.writeTextFileSync(`${f.checkout}/staged.php`, "staged edit\n");
      git(f.checkout, "add", "staged.php");
      git(f.checkout, "mv", "note.txt", "renamed with spaces.txt");
      Deno.removeSync(`${f.checkout}/local.php`);
      Deno.writeTextFileSync(`${f.checkout}/untracked.txt`, "not ignored\n");
      const before = git(f.checkout, "status", "--porcelain=v1", "--untracked-files=all");
      // Retained resources from a previous failure must survive both preflight and its restore path.
      assert(f.run("container", true).ok);
      const state = f.state();
      const rules = f.ufw();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const receipt = f.result();
      assertEquals(receipt.error_code, "instance.migrate_retirement_blocked");
      for (const path of [f.checkout, linked, detached, "index.php", "staged.php", "note.txt", "renamed with spaces.txt", "local.php", "untracked.txt"]) {
        assert(receipt.error_message.includes(path), receipt.error_message);
      }
      assert(receipt.error_message.includes("Other worktrees (2 total; first 20)"));
      assert(receipt.error_message.includes("Changed files (5 total; first 20)"));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state(), state);
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state(), state);
      assertEquals(f.ufw(), rules);
      assertEquals(git(f.checkout, "status", "--porcelain=v1", "--untracked-files=all"), before);
      assertEquals(f.result(), receipt);
    } finally {
      f.cleanup();
    }
  },
});

