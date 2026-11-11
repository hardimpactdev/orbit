import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1";
import { parse, stringify } from "jsr:@std/yaml@1";
import { hasAnsible, root, Task, transform, git, exists, fixture, migrationProcesses } from "./_support/ansible_instance_migrate.ts";

Deno.test("retention keeps real Git edge cases intact", () => {
  const done = new Deno.Command("python3", {
    args: ["-I", new URL("fixtures/instance-migrate-retention.py", import.meta.url).pathname],
  }).outputSync();
  assert(done.success, new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr));
});

