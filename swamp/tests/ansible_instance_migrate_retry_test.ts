import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a move starts with a new container: one that an earlier failed move left goes first",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace"]) assert(f.run(phase).ok);
      Deno.writeTextFileSync(`${f.rootfs}/srv/workspace/leftover.txt`, "from the failed move\n");
      f.build();
      assert(!exists(`${f.rootfs}/srv/workspace/leftover.txt`));
      assertEquals(f.read("index.php"), "<?php echo 2;\n");
    } finally {
      f.cleanup();
    }
  },
});

