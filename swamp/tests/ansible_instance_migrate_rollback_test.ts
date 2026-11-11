import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a failed move removes its container, its ACL, its firewall rule and its mirror, and the checkout serves as it was",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const status = git(f.checkout, "status", "--porcelain");
      const head = git(f.checkout, "rev-parse", "HEAD");
      f.build();
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assert(!exists(f.container.mirror));
      assert(!exists(f.rootfs));
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      // The restore keeps the result of the phase that failed before it, and a second restore finds nothing to remove.
      assertEquals(f.result(), { commit: head });
      const again = f.run("restore");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
      // The same command moves the Instance again, from a new container.
      f.build();
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
    } finally {
      f.cleanup();
    }
  },
});
