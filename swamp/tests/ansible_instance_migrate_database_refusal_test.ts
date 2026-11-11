import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a database server that the container cannot reach refuses the move, and the copy leaves nothing behind",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const status = git(f.checkout, "status", "--porcelain");
      // Nothing listens on this port.
      f.migrate.databases.push({ host: "127.0.0.1", port: 1 });
      f.write();
      const processes = migrationProcesses(f, "running");
      const processBefore = processes();
      const markerBefore = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const files = f.run("container");
      assert(!files.ok);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
      assert(exists(f.migrate.awake.path));
      assertEquals(processes(), processBefore);
      assert(!exists(`${f.folder}/run/quiesced`));
      assert(!exists(`${f.rootfs}/srv/workspace/.git`));
      assertEquals([...Deno.readDirSync(`${f.rootfs}/srv`)].map((entry) => entry.name), ["workspace"]);
      assertEquals([...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name), ["7.git"]);
      const restore = f.run("restore");
      assert(restore.ok, restore.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assert(exists(f.migrate.awake.path));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(f.result(), { error_code: "instance.database_unreachable" });
      assertEquals(processes(), processBefore);
      assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), markerBefore);
    } finally {
      f.cleanup();
    }
  },
});

