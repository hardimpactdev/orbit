import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const change of ["absolute", "relative", "outside", "broken"]) {
  Deno.test({
    name: `the exact archive tree handles ${change} link changes after preflight and preserves rollback`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.migrate.retire = [];
        f.write();
        Deno.mkdirSync(`${f.checkout}/public`);
        Deno.mkdirSync(`${f.checkout}/storage/app/public`, { recursive: true });
        Deno.mkdirSync(`${f.checkout}/alternate`);
        Deno.writeTextFileSync(`${f.checkout}/alternate/file.txt`, "copied target\n");
        const link = `${f.checkout}/public/storage`;
        Deno.symlinkSync(`${f.checkout}/storage/app/public`, link);
        const target = change === "absolute" ? `${f.checkout}/alternate` : change === "relative" ? "../alternate"
          : change === "outside" ? f.apps : `${f.checkout}/missing`;
        // A developer changes the source at the last possible point before tar copies it, after any source-side scan.
        Deno.writeTextFileSync(`${f.folder}/bin/tar`, `#!/usr/bin/env python3
import os, sys
if os.environ.get("ORBIT_CHECKOUT") == ${JSON.stringify(f.checkout)}:
    os.unlink(${JSON.stringify(link)})
    os.symlink(${JSON.stringify(target)}, ${JSON.stringify(link)})
os.execv("/usr/bin/tar", ["tar", *sys.argv[1:]])
`);
        Deno.chmodSync(`${f.folder}/bin/tar`, 0o755);
        for (const phase of ["container", "quiesce", "workspace"]) {
          const result = f.run(phase);
          assert(result.ok, result.output);
        }

        const result = f.run("files");

        assertEquals(Deno.readLinkSync(link), target);
        if (change === "absolute" || change === "relative") {
          assert(result.ok, result.output);
          assertEquals(Deno.readLinkSync(`${f.rootfs}/srv/workspace/public/storage`), "../alternate");
        } else {
          assert(!result.ok, result.output);
          assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
          assertEquals(f.result().preflight_refused, false);
          assert(f.result().error_message.includes("public/storage"), f.result().error_message);
          assert(f.result().error_message.includes(target), f.result().error_message);
          const restored = f.run("restore");
          assert(restored.ok, restored.output);
          assert(!exists(f.rootfs));
          assert(exists(f.migrate.awake.path));
          assertEquals(Deno.readLinkSync(link), target);
        }
      } finally {
        f.cleanup();
      }
    },
  });
}

