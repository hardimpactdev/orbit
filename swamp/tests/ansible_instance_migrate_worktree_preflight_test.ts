import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

for (const common of [false, true]) {
  Deno.test({
    name: `a ${common ? "common_of" : "direct"} repository refuses a worktree created before its final preflight`,
    ignore: !hasAnsible,
    fn() {
      const f = fixture();
      try {
        f.build();
        if (common) {
          // The linked checkout has gone; its common Git directory identifies this retirement candidate by equality.
          f.migrate.retire[0].common_of = `${f.apps}/already-retired`;
          f.write();
          const playbook = `${f.folder}/instance-migrate.yml`;
          const plays = parse(Deno.readTextFileSync(playbook)) as Task[];
          for (const play of plays) {
            if (play.hosts === "migrate_node") (play.vars as Task).migrate_common = `${f.checkout}/.git`;
          }
          Deno.writeTextFileSync(playbook, stringify(plays));
        }
        const late = `${f.apps}/unmanaged-late`;
        const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
        const tasks = parse(Deno.readTextFileSync(file)) as Task[];
        // The batch check has passed; the shared per-checkout preflight still refuses this blocker.
        tasks.unshift({
          name: "Fixture creates an unmanaged worktree before final preflight",
          "ansible.builtin.command": { argv: ["git", "-C", f.checkout, "worktree", "add", "--quiet", "--detach", late] },
          changed_when: true,
        });
        Deno.writeTextFileSync(file, stringify(tasks));
        const head = git(f.checkout, "rev-parse", "HEAD");
        const state = f.state();
        const retire = f.run("retire");
        assert(!retire.ok, retire.output);
        assertEquals(f.result().error_code, "instance.migrate_retirement_blocked");
        assert(f.result().error_message.includes(late), f.result().error_message);
        assertEquals(f.result().preflight_refused, false);
        assert(exists(`${f.checkout}/.git`));
        assert(exists(late));
        assertEquals(git(late, "rev-parse", "HEAD"), head);
        assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
        assertEquals(f.state(), state);
        assert(exists(f.migrate.awake.path));
      } finally {
        f.cleanup();
      }
    },
  });
}

