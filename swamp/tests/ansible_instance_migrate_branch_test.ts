import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a checkout on another branch than the recorded one is refused before anything changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      git(f.checkout, "checkout", "--quiet", "-b", "elsewhere");
      const quiesce = f.run("container");
      assert(!quiesce.ok);
      assertEquals(f.result(), { error_code: "instance.migrate_branch_changed", preflight_refused: true });
      // The Route still serves, and no container was made.
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});
