import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a clean standalone non-default checkout moves with T3 and leaves an unrelated default repository intact",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("feature", false, false);
    try {
      f.migrate.retire.push({ path: f.main, mirror: `${f.folder}/mirror/7.git`, common_of: f.checkout });
      f.write();
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "feature");
      assert(Deno.statSync(`${f.rootfs}/srv/workspace/.git`).isDirectory);
      assertEquals(f.state().containers["instance-8"].added.t3, {
        type: "proxy", settings: ["listen=tcp:10.44.0.7:3800", "connect=tcp:10.253.0.8:3773", "nat=true"],
      });
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      assert(exists(`${f.main}/.git`));
      assertEquals(f.state().containers["instance-8"].status, "Running");
    } finally {
      f.cleanup();
    }
  },
});

