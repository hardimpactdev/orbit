import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a worktree created after the final listing keeps all its Git data after retirement",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.build();
      const late = `${f.apps}/after-final-listing`;
      const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
      const tasks = parse(Deno.readTextFileSync(file)) as Task[];
      const retain = tasks.findIndex((task) => task.name === "Retain the checkout without deleting any files");
      tasks.splice(retain, 0, {
        name: "Fixture adds a late worktree after the final preflight listing",
        "ansible.builtin.command": { argv: ["git", "-C", f.checkout, "worktree", "add", "--quiet", "--detach", late] },
        changed_when: true,
      });
      Deno.writeTextFileSync(file, stringify(tasks));
      const head = git(f.checkout, "rev-parse", "HEAD");
      const retire = f.run("retire");
      assert(retire.ok, retire.output);
      assert(!exists(f.checkout));
      const retained = f.retained();
      assert(exists(`${retained}/.git/worktrees/after-final-listing/HEAD`));
      assertEquals(git(retained, "cat-file", "-t", head), "commit");
      assertEquals(Deno.readTextFileSync(`${late}/.git`).trim(), `gitdir: ${f.checkout}/.git/worktrees/after-final-listing`);
      assert(exists(`${late}/index.php`));
      const receipt = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/retained-git.json`));
      assertEquals(receipt.retained_git_path, `${f.checkout}.orbit-retired-git`);
      assertMatch(receipt.retained_git_at, /^\d{4}-\d{2}-\d{2}T/);
      assert(exists(f.migrate.awake.path));
    } finally {
      f.cleanup();
    }
  },
});
