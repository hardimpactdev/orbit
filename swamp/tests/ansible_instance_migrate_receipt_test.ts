import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test({
  name: "a worker receipt failure leaves the source untouched and a retry reuses the prepared retention time",
  ignore: !hasAnsible,
  fn() {
    const f = fixture();
    try {
      f.build();
      const file = `${f.folder}/tasks/instance-migrate-retire.yml`;
      const original = Deno.readTextFileSync(file);
      const tasks = parse(original) as Task[];
      const record = tasks.find((task) => task.name === "Record retained Git data for the terminal result")!;
      (record["ansible.builtin.copy"] as Task).dest = `${f.folder}/missing-parent/retained-git.json`;
      Deno.writeTextFileSync(file, stringify(tasks));
      const head = git(f.checkout, "rev-parse", "HEAD");
      const failed = f.run("retire");
      assert(!failed.ok, failed.output);
      assert(exists(`${f.checkout}/.git`));
      assertEquals(git(f.checkout, "rev-parse", "HEAD"), head);
      assert(!exists(`${f.folder}/run/retained-git.json`));
      const prepared = JSON.parse(Deno.readTextFileSync(`${f.checkout}.orbit-retired-git/retained.json`));
      Deno.writeTextFileSync(file, original);
      const retry = f.run("retire");
      assert(retry.ok, retry.output);
      const recorded = JSON.parse(Deno.readTextFileSync(`${f.folder}/run/retained-git.json`));
      assertEquals(recorded.retained_git_at, prepared.retained_git_at);
      assertEquals(git(f.retained(), "rev-parse", "HEAD"), head);
      assert(!exists(f.checkout));
    } finally {
      f.cleanup();
    }
  },
});

