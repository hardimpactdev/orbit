import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "preflight refuses stashes with the full count and preserves every entry and earlier move resource",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const name of ["first", "second"]) {
        Deno.writeTextFileSync(`${f.checkout}/${name}.txt`, name);
        git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
      }
      const stashes = git(f.checkout, "stash", "list", "--format=%H");
      const refs = git(f.checkout, "show-ref");
      assert(f.run("container", true).ok);
      const state = f.state();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_stashes_present");
      assert(f.result().error_message.includes("Stashes: 2"));
      assert(f.result().error_message.includes(f.checkout));
      assertEquals(f.result().preflight_refused, true);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state(), state);
      assert(f.run("restore").ok);
      assertEquals(f.state(), state);
      assertEquals(git(f.checkout, "show-ref"), refs);
      assertEquals(git(f.checkout, "stash", "list", "--format=%H"), stashes);
      for (const [index, name] of [[0, "second"], [1, "first"]] as const) {
        assertEquals(git(f.checkout, "show", `stash@{${index}}^3:${name}.txt`), name);
      }
      assertEquals(git(f.checkout, "status", "--porcelain"), "");
    } finally {
      f.cleanup();
    }
  },
});
