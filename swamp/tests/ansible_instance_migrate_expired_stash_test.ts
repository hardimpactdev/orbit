import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a stash ref with an expired reflog still refuses before downtime",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      Deno.writeTextFileSync(`${f.checkout}/expired-stash.txt`, "kept stash data\n");
      git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      const stash = git(f.checkout, "rev-parse", "refs/stash");
      git(f.checkout, "reflog", "expire", "--expire=all", "refs/stash");
      assertEquals(git(f.checkout, "stash", "list"), "");
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_stashes_present");
      assert(f.result().error_message.includes("Stashes: 1"));
      assertEquals(git(f.checkout, "rev-parse", "refs/stash"), stash);
      assertEquals(git(f.checkout, "show", `${stash}^3:expired-stash.txt`), "kept stash data");
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});

