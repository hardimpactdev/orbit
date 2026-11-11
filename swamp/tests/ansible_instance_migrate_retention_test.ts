import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a clean default with pushed commits and no stash retains all Git metadata and repeats without changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      git(f.checkout, "branch", "spare");
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.retained(), "rev-parse", "refs/heads/main"), head);
      assertEquals(git(f.retained(), "rev-parse", "refs/heads/spare"), head);
      const again = f.run("retire");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
    } finally {
      f.cleanup();
    }
  },
});

