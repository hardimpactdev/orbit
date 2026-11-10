import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

Deno.test({
  name: "a clone refuses a seed that a deployment replaced, and fails with instance.t3_unready when T3 does not answer",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const seed = git(f.source, "rev-parse", "HEAD");
      Object.assign(f.container, {
        name: "instance-8", address: "10.253.0.3", acl: "instance-8", mirror: `${f.folder}/mirror/8.git`, branch: "main", firewall: [],
        clone: { source: "instance-7", snapshot: "seed-gone", seed_commit: seed, source_mirror: `${f.folder}/mirror/7.git`,
          t3: { port: 3800, listen: "10.44.0.7", device: "t3", unit: "other.service", internal_port: 3773, ready_seconds: 1, t3: "/t3",
            project_mark: "/home/agent/.config/orbit/t3-project" },
          model_environment: { path: "/home/agent/.config/orbit/model.env", uid: 2750, gid: 2750, mode: "0600" }, files: [], links: [] },
      });
      f.write();
      // A deployment of the default replaced the admitted seed before the copy.
      assert(!f.run("clone").ok);
      assertEquals(f.result(), { error_code: "instance.default_seed_moved" });
      (f.container as unknown as { clone: { snapshot: string } }).clone.snapshot = `seed-${seed}`;
      f.write();
      assert(f.run("clone").ok);
      const unready = f.run("t3");
      assert(!unready.ok);
      assertEquals(f.result(), { error_code: "instance.t3_unready" });
    } finally {
      f.cleanup();
    }
  },
});

