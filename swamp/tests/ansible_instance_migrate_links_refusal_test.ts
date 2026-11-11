import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const reason of ["outside checkout", "broken"]) {
  Deno.test({
    name: `${reason} symlinks refuse before downtime, name every path and target, and preserve the source and earlier resources`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.write();
        Deno.mkdirSync(`${f.checkout}/public`);
        const target = reason === "broken" ? `${f.checkout}/missing/storage` : f.apps;
        for (const path of ["public/storage", "local package"]) Deno.symlinkSync(target, `${f.checkout}/${path}`);
        const before = Deno.readTextFileSync(`${f.folder}/state.json`);

        const result = f.run("container");

        assert(!result.ok, result.output);
        assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
        assertEquals(f.result().preflight_refused, true);
        for (const path of ["public/storage", "local package"]) {
          assert(f.result().error_message.includes(path), f.result().error_message);
          assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
        }
        assert(f.result().error_message.includes(target), f.result().error_message);
        assert(f.result().error_message.includes(reason), f.result().error_message);
        assert(f.run("restore").ok);
        assert(exists(f.migrate.awake.path));
        assertEquals(Deno.readTextFileSync(`${f.folder}/state.json`), before);
        assert(!exists(`${f.folder}/calls`), "No container command may run after a preflight refusal.");
      } finally {
        f.cleanup();
      }
    },
  });
}

