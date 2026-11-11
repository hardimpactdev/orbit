import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "retirement error lists stop at 20 entries with total counts",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (let i = 0; i < 22; i++) {
        Deno.writeTextFileSync(`${f.checkout}/file-${String(i).padStart(2, "0")}.txt`, "new\n");
        git(f.checkout, "worktree", "add", "--quiet", "--detach", `${f.apps}/worktree-${String(i).padStart(2, "0")}`);
      }
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      const message = f.result().error_message;
      assert(message.includes("Other worktrees (22 total; first 20)"));
      assert(message.includes("Changed files (22 total; first 20)"));
      assert(message.includes("file-19.txt"));
      assert(message.includes("worktree-19"));
      assert(!message.includes("file-20.txt"));
      assert(!message.includes("worktree-20"));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});

