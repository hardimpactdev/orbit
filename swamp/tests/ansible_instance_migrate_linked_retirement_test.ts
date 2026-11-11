import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a linked checkout refuses retirement even when the default is in the same retirement batch",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature");
    try {
      f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
      f.write();
      const quiesce = f.run("container");
      assert(!quiesce.ok, quiesce.output);
      assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
      assert(f.result().error_message.includes(f.main));
      assert(f.result().error_message.includes("Other worktrees (1 total; first 20)"));
      assert(exists(f.checkout));
      assert(exists(`${f.main}/.git`));
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers ?? {}, {});
    } finally {
      f.cleanup();
    }
  },
});

