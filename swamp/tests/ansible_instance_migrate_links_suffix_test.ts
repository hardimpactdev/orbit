import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "file targets with directory suffixes refuse before downtime and in the copied tree without repairing links",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.migrate.retire = [];
      f.write();
      Deno.mkdirSync(`${f.checkout}/public`);
      Deno.mkdirSync(`${f.checkout}/storage`);
      const file = `${f.checkout}/storage/file`;
      Deno.writeTextFileSync(file, "data\n");
      const targets = new Map<string, string>();
      for (const [kind, prefix] of [["absolute", f.checkout], ["relative", ".."]]) {
        for (const [label, suffix] of [["slash", "/"], ["dot", "/."]]) {
          const path = `public/${kind}-${label}`;
          const target = `${prefix}/storage/file${suffix}`;
          targets.set(path, target);
          Deno.symlinkSync(target, `${f.checkout}/${path}`);
        }
      }
      const preflight = f.run("container");
      assert(!preflight.ok, preflight.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, true);
      for (const [path, target] of targets) {
        assert(f.result().error_message.includes(path), f.result().error_message);
        assert(f.result().error_message.includes(target), f.result().error_message);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
      }
      // Make every link valid for preflight, then copy a file in place of that directory.
      Deno.removeSync(file);
      Deno.mkdirSync(file);
      for (const phase of ["container", "quiesce", "workspace"]) {
        const result = f.run(phase);
        assert(result.ok, result.output);
      }
      Deno.writeTextFileSync(`${f.folder}/bin/tar`, `#!/usr/bin/env python3
import os, sys
if os.environ.get("ORBIT_CHECKOUT") == ${JSON.stringify(f.checkout)}:
    os.rmdir(${JSON.stringify(file)})
    with open(${JSON.stringify(file)}, "w") as copied:
        copied.write("copied file")
os.execv("/usr/bin/tar", ["tar", *sys.argv[1:]])
`);
      Deno.chmodSync(`${f.folder}/bin/tar`, 0o755);
      const copied = f.run("files");
      assert(!copied.ok, copied.output);
      assertEquals(f.result().error_code, "instance.migrate_symlink_unsupported");
      assertEquals(f.result().preflight_refused, false);
      assert(f.result().error_message.includes("broken"), f.result().error_message);
      for (const [path, target] of targets) {
        assert(f.result().error_message.includes(path), f.result().error_message);
        assert(f.result().error_message.includes(target), f.result().error_message);
        assertEquals(Deno.readLinkSync(`${f.rootfs}/srv/workspace/${path}`), target);
        assertEquals(Deno.readLinkSync(`${f.checkout}/${path}`), target);
      }
      const restored = f.run("restore");
      assert(restored.ok, restored.output);
      assert(!exists(f.rootfs));
      assert(exists(f.migrate.awake.path));
      assertEquals(Deno.readTextFileSync(file), "copied file");
    } finally {
      f.cleanup();
    }
  },
});

