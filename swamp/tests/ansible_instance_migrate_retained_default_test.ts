import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a retained default copies its unpushed commit and changed, staged, untracked, deleted and ignored files",
  ignore: !hasAnsible,
  fn() {
    const f = fixture("default", true);
    try {
      // A retained default can copy its local files; its checkout is not scheduled for deletion yet.
      f.migrate.retire = [];
      f.write();
      const head = git(f.checkout, "rev-parse", "HEAD");
      const status = git(f.checkout, "status", "--porcelain");
      f.build();
      // The Route shows the wake page from the first phase on.
      assert(!exists(f.migrate.awake.path));
      assertEquals(f.result(), { commit: head });
      // A standalone repository at the checkout's own commit, which no origin has.
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assert(Deno.statSync(`${f.rootfs}/srv/workspace/.git`).isDirectory);
      assertEquals(f.read("local.php"), "<?php // committed, never pushed\n");
      assertEquals(f.read("index.php"), "<?php echo 2;\n");
      assertEquals(f.read("staged.php"), "<?php // staged\n");
      assertEquals(f.read("note.txt"), "untracked\n");
      assertEquals(f.read("vendor/pkg/a.php"), "<?php // dependency\n");
      assertEquals(f.read("database/database.sqlite"), "sqlite\n");
      assert(!exists(`${f.rootfs}/srv/workspace/old.php`));
      // .env came as it was; the path into the checkout points into the workspace now.
      assertEquals(f.read(".env"), `APP_KEY=base64:kept\nDB_DATABASE=${f.rootfs}/srv/workspace/database/database.sqlite\n`);
      // The way to the database server came with the container.
      assertEquals(f.state()["network-acls"].find((acl: {name: string}) => acl.name === f.container.acl).egress, f.container.acl_rules.egress);
      assertEquals(f.ufw().map((line) => JSON.parse(line).comment), ["orbit:instance-7-database"]);
      // The move uses create's resource limits and full root device, even for an image-based migration.
      assertEquals(f.state().containers["instance-7"].config, ["security.idmap.isolated=true", "limits.cpu=4", "limits.memory=8GiB", "limits.processes=4096"]);
      assertEquals(f.state().containers["instance-7"].devices.filter((device: string) => device.startsWith("root,")), [
        "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB",
      ]);
      // Nothing of the copy stays behind, and the checkout is as it was: a failure from here could still restore it.
      assertEquals([...Deno.readDirSync(`${f.rootfs}/srv`)].map((entry) => entry.name), ["workspace"]);
      assertEquals([...Deno.readDirSync(`${f.folder}/mirror`)].map((entry) => entry.name), ["7.git"]);
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assertEquals(Deno.readTextFileSync(`${f.checkout}/.env`), `APP_KEY=base64:kept\nDB_DATABASE=${f.checkout}/database/database.sqlite\n`);

      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(exists(f.checkout));
      assertEquals(git(f.checkout, "status", "--porcelain"), status);
      assert(exists(f.migrate.awake.path));
      assertEquals(f.state().containers["instance-7"].status, "Running");
      const again = f.run("retire");
      assert(again.ok, again.output);
      assertMatch(again.output, /changed=0\s/);
    } finally {
      f.cleanup();
    }
  },
});

