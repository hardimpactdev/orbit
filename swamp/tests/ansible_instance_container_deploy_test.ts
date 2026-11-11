import { assert, assertEquals, assertThrows } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, fixture, worker, paused, environments, restarts } from "./_support/ansible_instance_container.ts";

Deno.test({
  name: "a deployment checks out the branch's new commit, seeds it and deletes the older seed; dirty or diverged work refuses",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      for (const phase of ["container", "workspace", "processes", "seed"]) assert(f.run(phase).ok);
      const first = git(f.source, "rev-parse", "HEAD");
      Deno.writeTextFileSync(`${f.source}/index.php`, "<?php echo 2;\n");
      git(f.source, "commit", "--quiet", "-am", "second");
      const second = git(f.source, "rev-parse", "HEAD");

      for (const phase of ["start", "fetch", "checkout", "seed", "check"]) {
        const result = f.run(phase);
        assert(result.ok, `${phase}: ${result.output}`);
      }
      assertEquals(f.result(), { commit: second });
      assertEquals(f.workspace("rev-parse", "HEAD"), second);
      assertEquals(f.state().containers["instance-7"].snapshots, [`seed-${second}`]);

      // A pinned commit that is not on the branch refuses at the fetch.
      f.container.commit = "f".repeat(40);
      f.write();
      const stray = f.run("fetch");
      assert(!stray.ok);
      assertEquals(f.result(), { error_code: "deployment.commit_not_on_branch" });

      // Tracked changes in the workspace refuse the checkout and stay.
      f.container.commit = first;
      f.write();
      assert(f.run("fetch").ok);
      Deno.writeTextFileSync(`${f.folder}/containers/instance-7/srv/workspace/index.php`, "<?php echo 3;\n");
      const dirty = f.run("checkout");
      assert(!dirty.ok);
      assertEquals(f.result(), { error_code: "deployment.checkout_dirty" });
      f.workspace("checkout", "--", "index.php");

      // An older commit does not fast-forward the branch.
      const refetch = f.run("fetch");
      assert(refetch.ok, refetch.output);
      const diverged = f.run("checkout");
      assert(!diverged.ok);
      assertEquals(f.result(), { error_code: "deployment.branch_diverged" });
      assertEquals(f.workspace("rev-parse", "HEAD"), second);

      // A failure that is no refusal names its phase.
      f.container.commit = null;
      f.write();
      const state = f.state();
      state.inactive = ["orbit-frankenphp.service"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      assert(!f.run("check").ok);
      assertEquals(f.result(), { error_code: "deployment.processes_inactive" });
      delete state.containers["instance-7"];
      Deno.writeTextFileSync(`${f.folder}/state.json`, JSON.stringify(state));
      assert(!f.run("start").ok);
      assertEquals(f.result(), { error_code: "instance.container_start_failed" });
    } finally {
      f.cleanup();
    }
  },
});
