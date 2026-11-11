import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

Deno.test({
  name: "the first build makes the container, checks out the default branch, starts FrankenPHP and seeds it; a rerun changes nothing",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      const head = git(f.source, "rev-parse", "HEAD");
      for (const phase of ["container", "workspace", "processes", "seed"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
        assertEquals(f.result(), { commit: phase === "workspace" || phase === "seed" ? head : null });
      }
      const state = f.state();
      assertEquals(state["network-acls"].find((acl: {name: string}) => acl.name === "instance-7"), { name: "instance-7", ...f.container.acl_rules });
      assertEquals(state.containers["instance-7"].devices, [
        "eth0,ipv4.address=10.253.0.2",
        "eth0,security.acls=orbitinst0-proxy,instance-7",
        "eth0,security.acls.default.egress.action=reject",
        "eth0,security.acls.default.ingress.action=reject",
        "root,type=disk", "root,path=/", "root,pool=orbit-instances", "root,size=40GiB",
      ]);
      assertEquals(state.containers["instance-7"].config, ["security.idmap.isolated=true", "limits.cpu=4", "limits.memory=8GiB", "limits.processes=4096"]);
      assertEquals(state.containers["instance-7"].status, "Running");
      // `php` on every PATH in the container is the Project's static build.
      assertEquals(
        Deno.readLinkSync(`${f.folder}/containers/instance-7/usr/local/bin/php`),
        "/opt/orbit-runtime/current/php/8.5/bin/php",
      );
      assertEquals(f.workspace("rev-parse", "HEAD"), head);
      assertEquals(f.workspace("symbolic-ref", "--short", "HEAD"), "main");
      assertEquals(state.files["/srv/workspace/.env"], { uid: "1000", gid: "2760", mode: "0640" });
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/srv/workspace/.env`), "APP_KEY=base64:secret\n");
      assertEquals(state.containers["instance-7"].snapshots, [`seed-${head}`]);
      assert(state.systemctl.some((call: string[]) => call.join(" ") === "enable --now -- orbit-frankenphp.service"));
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/etc/orbit/frankenphp.Caddyfile`), f.container.web_config.content);
      // No bundle, credential or .env stays behind on the Node or in the container.
      assertEquals([...Deno.readDirSync(`${f.folder}/containers/instance-7/tmp`)], []);

      for (const phase of ["container", "workspace", "processes", "seed", "start", "check"]) {
        const again = f.run(phase);
        assert(again.ok, again.output);
        assertEquals(again.changed, 0, `${phase}: ${again.output}`);
      }

      // Configuration drift alone restarts PHP, even when its unit has not changed.
      Deno.writeTextFileSync(`${f.folder}/containers/instance-7/etc/orbit/frankenphp.Caddyfile`, "stale\n");
      const before = f.state().systemctl.length;
      const repaired = f.run("processes");
      assert(repaired.ok, repaired.output);
      assertEquals(Deno.readTextFileSync(`${f.folder}/containers/instance-7/etc/orbit/frankenphp.Caddyfile`), f.container.web_config.content);
      assert(f.state().systemctl.slice(before).some((call: string[]) => call.join(" ") === "restart -- orbit-frankenphp.service"));
      const unchanged = f.run("processes");
      assert(unchanged.ok, unchanged.output);
      assertEquals(unchanged.changed, 0, unchanged.output);
    } finally {
      f.cleanup();
    }
  },
});
