import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const detached of [false, true]) {
  Deno.test({
    name: detached ? "preflight refuses and counts an unpushed detached HEAD" : "preflight refuses and counts every commit when no remote-tracking refs exist",
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        if (detached) {
          git(f.checkout, "checkout", "--quiet", "--detach");
          git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "detached local commit");
        } else git(f.checkout, "update-ref", "-d", "refs/remotes/origin/main");
        const head = git(f.checkout, "rev-parse", "HEAD");
        const refs = git(f.checkout, "show-ref");
        const quiesce = f.run("container");
        assert(!quiesce.ok, quiesce.output);
        assertEquals(f.result().error_code, "instance.migrate_unpushed_commits");
        const expected = detached ? '"branch": "detached HEAD", "commits": 1' : '"branch": "main", "commits": 3';
        assert(f.result().error_message.includes(expected), f.result().error_message);
        assert(exists(f.migrate.awake.path));
        assertEquals(f.state().containers ?? {}, {});
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
        assertEquals(git(f.checkout, "show-ref"), refs);
      } finally {
        f.cleanup();
      }
    },
  });
}
