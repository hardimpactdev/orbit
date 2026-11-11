import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const blocker of ["changes", "worktree", "commits", "stashes"] as const) {
  Deno.test({
    name: `retirement rechecks ${blocker} created after preflight and leaves the container and source intact`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.build();
        const path = blocker === "changes" ? `${f.checkout}/late.txt` : `${f.apps}/late-worktree`;
        if (blocker === "changes") Deno.writeTextFileSync(path, "late edit\n");
        else if (blocker === "worktree") git(f.checkout, "worktree", "add", "--quiet", "--detach", path);
        else if (blocker === "commits") git(f.checkout, "commit", "--quiet", "--allow-empty", "-m", "late commit");
        else {
          Deno.writeTextFileSync(`${f.checkout}/late-stash.txt`, "late stash\n");
          git(f.checkout, "stash", "push", "--quiet", "--include-untracked");
        }
        const refs = git(f.checkout, "show-ref");
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        const code = blocker === "commits" ? "instance.migrate_unpushed_commits" : blocker === "stashes" ? "instance.migrate_stashes_present" : "instance.migrate_retirement_blocked";
        assertEquals(f.result().error_code, code);
        const detail = blocker === "changes" ? "late.txt" : blocker === "worktree" ? path : blocker === "commits" ? '"commits": 1' : "Stashes: 1";
        assert(f.result().error_message.includes(detail), f.result().error_message);
        assertEquals(git(f.checkout, "show-ref"), refs);
        assertEquals(f.result().preflight_refused, false);
        assert(exists(f.migrate.awake.path), "The container Route must serve after a retirement refusal.");
        assert(exists(f.checkout));
        if (["changes", "worktree"].includes(blocker)) assert(exists(path));
        assertEquals(f.state(), state);
        assertEquals(f.state().containers["instance-7"].status, "Running");
      } finally {
        f.cleanup();
      }
    },
  });
}
