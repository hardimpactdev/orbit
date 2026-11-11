import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a link introduced after preparation refuses quiesce with zero downtime and removes only the prepared container",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      const processes = migrationProcesses(f, "running");
      const before = processes();
      const markerBefore = Deno.statSync(f.migrate.awake.path).mtime?.getTime();
      const prepared = f.run("container");
      assert(prepared.ok, prepared.output);
      Deno.symlinkSync(f.apps, `${f.checkout}/late-link`);
      const refused = f.run("quiesce");
      assert(!refused.ok, refused.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, false);
      assertEquals(processes(), before);
      assert(!exists(`${f.folder}/run/quiesced`));
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assertEquals(f.state().containers, {});
      assertEquals(f.state()["network-acls"].filter((acl: {name: string}) => acl.name !== "orbitinst0-egress"), []);
      assertEquals(f.ufw(), []);
      assertEquals(processes(), before);
      assertEquals(Deno.statSync(f.migrate.awake.path).mtime?.getTime(), markerBefore);
      assertEquals(Deno.readLinkSync(`${f.checkout}/late-link`), f.apps);
    } finally {
      f.cleanup();
    }
  },
});

