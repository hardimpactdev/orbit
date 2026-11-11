import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

Deno.test({
  name: "a start points php at the Project's PHP version, and removes the link of a Project without PHP",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const link = `${f.folder}/containers/instance-7/usr/local/bin/php`;
    try {
      assert(f.run("container").ok);
      f.container.php = "/opt/orbit-runtime/current/php/8.4/bin/php";
      f.write();
      let result = f.run("start");
      assert(result.ok, result.output);
      assertEquals(Deno.readLinkSync(link), "/opt/orbit-runtime/current/php/8.4/bin/php");
      f.container.php = null;
      f.write();
      result = f.run("start");
      assert(result.ok, result.output);
      assertThrows(() => Deno.lstatSync(link));
      // A php that is no link is not Orbit's to remove.
      Deno.writeTextFileSync(link, "#!/bin/sh\n");
      result = f.run("start");
      assert(result.ok, result.output);
      assertEquals(result.changed, 0, result.output);
      assert(Deno.lstatSync(link).isFile);
      f.container.php = "/opt/orbit-runtime/current/php/8.5/bin/php";
      f.write();
      result = f.run("start");
      assert(result.ok, result.output);
      assertEquals(result.changed, 0, result.output);
      assert(Deno.lstatSync(link).isFile);
    } finally {
      f.cleanup();
    }
  },
});

Deno.test({
  name: "container PHP reads the shared 1G memory limit and PHP Data Bridge, and start repairs them without rerun changes",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    const rootfs = `${f.folder}/containers/instance-7`;
    const settings = `${rootfs}/etc/orbit/php/conf.d/99-orbit-runtime.ini`;
    try {
      Deno.writeTextFileSync(`${f.folder}/empty.ini`, "");
      const created = f.run("container");
      assert(created.ok, created.output);
      const bridge = "/opt/orbit-runtime/current/php-data-bridge/bridge/prepend.php";
      const setting = (name: string) => {
        const result = new Deno.Command("php", {
          args: ["-r", `echo ini_get("${name}");`], clearEnv: true,
          env: { PATH: Deno.env.get("PATH")!, PHPRC: `${f.folder}/empty.ini`, PHP_INI_SCAN_DIR: `${rootfs}/etc/orbit/php/conf.d` },
        }).outputSync();
        assert(result.success, new TextDecoder().decode(result.stderr));
        return new TextDecoder().decode(result.stdout);
      };
      assertEquals(setting("memory_limit"), "1G");
      assertEquals(setting("auto_prepend_file"), bridge);
      assertEquals(f.state().files["/etc/orbit/php/conf.d/99-orbit-runtime.ini"], { uid: "0", gid: "0", mode: "0644" });

      // A running unit read the old settings at its start, so a repair restarts the running Orbit units; a rerun does not.
      const tryRestarts = () =>
        (f.state().systemctl ?? []).filter((call: string[]) => call[0] === "try-restart").map((call: string[]) => call.join(" "));
      const before = tryRestarts().length;
      Deno.writeTextFileSync(settings, "memory_limit = 128M\n");
      const repaired = f.run("start");
      assert(repaired.ok, repaired.output);
      assertEquals(setting("memory_limit"), "1G");
      assertEquals(setting("auto_prepend_file"), bridge);
      assertEquals(tryRestarts().slice(before), ["try-restart orbit-*.service"]);
      const again = f.run("start");
      assert(again.ok, again.output);
      assertEquals(again.changed, 0, again.output);
      assertEquals(tryRestarts().length, before + 1);
    } finally {
      f.cleanup();
    }
  },
});
